import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Award emoji of merge request acme/app!12 in the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php):
 * jane's 👍 on the merge request and 🎉 on her comment 101.
 */
test.describe('Detail sheet reactions', () => {
  test.beforeEach(async ({ page }) => {
    await query('DELETE FROM tx_unitytracker_connection');
    await query('DELETE FROM cache_unitytracker');
    await query("DELETE FROM sys_registry WHERE entry_namespace = 'unitytracker_fake'");
    await loginAsAdmin(page);

    const module = page.frameLocator('#typo3-contentIframe');
    await page.goto(`${MODULE_PATH}/newConnection`);
    await module.getByLabel('Provider').selectOption('gitlab');
    await module.getByLabel('Host').fill('gitlab.fake.test');
    await module.getByLabel('Personal access token').fill('fake-token');
    await module.getByRole('button', { name: 'Verify and save' }).click();
    await expect(module.locator('[data-connections]')).toContainText('tester@gitlab.fake.test');
  });

  async function openSheet(page: Page): Promise<FrameLocator> {
    await page.goto(MODULE_PATH);
    await page.frameLocator('#typo3-contentIframe').getByRole('link', { name: 'Fix login redirect' }).click();
    const sheet = page.frameLocator('typo3-backend-modal iframe');
    await expect(sheet.getByRole('heading', { name: 'Fix login redirect' })).toBeVisible();
    return sheet;
  }

  test('a reaction on the merge request is toggled', async ({ page }) => {
    const sheet = await openSheet(page);
    const bar = sheet.locator('[data-item-description] [data-reactions=""]');

    await expect(bar.getByRole('button', { name: ':thumbsup: (1)' })).toHaveAttribute('aria-pressed', 'false');
    await bar.getByRole('button', { name: ':thumbsup: (1)' }).click();
    await expect(bar.getByRole('button', { name: ':thumbsup: (2)' })).toHaveAttribute('aria-pressed', 'true');

    await bar.getByRole('button', { name: ':thumbsup: (2)' }).click();
    await expect(bar.getByRole('button', { name: ':thumbsup: (1)' })).toHaveAttribute('aria-pressed', 'false');
  });

  test('a new reaction on a comment is picked', async ({ page }) => {
    const sheet = await openSheet(page);
    const bar = sheet.locator('[data-comment="101"] [data-reactions="101"]');

    await expect(bar.getByRole('button', { name: ':tada: (1)' })).toContainText('🎉 1');
    await bar.locator('summary').click();
    await bar.getByRole('button', { name: 'React with :rocket:' }).click();

    const rocket = sheet.locator('[data-comment="101"]').getByRole('button', { name: ':rocket: (1)' });
    await expect(rocket).toHaveAttribute('aria-pressed', 'true');
    await expect(sheet.locator('[data-comment="102"] [data-reaction]')).toHaveCount(0);
  });
});
