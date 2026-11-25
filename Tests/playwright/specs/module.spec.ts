import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

function moduleFrame(page: Page): FrameLocator {
  return page.frameLocator('#typo3-contentIframe');
}

test.describe('Unity Tracker module', () => {
  test.beforeEach(async ({ page }) => {
    await query('DELETE FROM tx_unitytracker_connection');
    await query('DELETE FROM cache_unitytracker');
    await loginAsAdmin(page);
  });

  test('shows an empty state without connections', async ({ page }) => {
    await page.goto(MODULE_PATH);
    const module = moduleFrame(page);

    await expect(module.getByRole('heading', { name: 'What needs my attention' })).toBeVisible();
    await expect(module.getByText('No connections yet')).toBeVisible();

    await module.getByRole('link', { name: 'Add connection' }).click();
    await expect(module.getByRole('heading', { name: 'Connections', exact: true })).toBeVisible();
    await expect(module.getByText('You have no connections yet.')).toBeVisible();
  });

  test('rejects a host that is not allowed and keeps the form values', async ({ page }) => {
    await page.goto(`${MODULE_PATH}/connections`);
    const module = moduleFrame(page);

    await module.getByLabel('Provider').selectOption('gitlab');
    await module.getByLabel('Host').fill('intranet.example.com');
    await module.getByLabel('Name (optional)').fill('Intranet');
    await module.getByLabel('Personal access token').fill('glpat-not-used');
    await module.getByRole('button', { name: 'Verify and save' }).click();

    await expect(module.getByText('Host "intranet.example.com" is not allowed')).toBeVisible();
    await expect(module.getByLabel('Host')).toHaveValue('intranet.example.com');
    await expect(module.getByLabel('Name (optional)')).toHaveValue('Intranet');
    await expect(module.getByLabel('Personal access token')).toHaveValue('');
    await expect(module.getByText('You have no connections yet.')).toBeVisible();
  });

  test('reports a connection whose token cannot be decrypted and deletes it', async ({ page }) => {
    // A token that is not valid cipher text: the overview must report it without calling the API.
    await query(
      `INSERT INTO tx_unitytracker_connection (be_user, provider, host, label, account, token, crdate)
       SELECT uid, 'gitlab', 'gitlab.com', 'Broken GitLab', 'admin', 'v1:broken', UNIX_TIMESTAMP() FROM be_users WHERE username = 'admin'`
    );

    await page.goto(MODULE_PATH);
    const module = moduleFrame(page);
    await expect(module.getByText('Could not load "Broken GitLab"')).toBeVisible();
    await expect(module.getByText('The stored token cannot be decrypted')).toBeVisible();
    await expect(module.locator('[data-category="todo"]')).toBeVisible();

    await page.goto(`${MODULE_PATH}/connections`);
    const row = module.locator('[data-connections] tbody tr', { hasText: 'Broken GitLab' });
    await expect(row).toContainText('GitLab');
    await row.getByRole('button', { name: 'Delete' }).click();

    await expect(module.getByText('Connection deleted.')).toBeVisible();
    await expect(module.getByText('You have no connections yet.')).toBeVisible();
  });
});
