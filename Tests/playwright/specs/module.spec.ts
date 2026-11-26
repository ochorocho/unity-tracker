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
    await expect(module.getByRole('heading', { name: 'Add connection' })).toBeVisible();
    await expect(module.getByLabel('Personal access token')).toBeVisible();
  });

  test('connection list offers "Add connection" in the doc header', async ({ page }) => {
    await page.goto(`${MODULE_PATH}/connections`);
    const module = moduleFrame(page);

    await expect(module.getByText('You have no connections yet.')).toBeVisible();
    await expect(module.getByText('Back to overview')).toHaveCount(0);
    await expect(module.getByLabel('Personal access token')).toHaveCount(0);

    await module.locator('.module-docheader').getByRole('button', { name: 'Add connection' }).click();
    await expect(module.getByRole('heading', { name: 'Add connection' })).toBeVisible();

    await module.locator('.module-docheader').getByRole('button', { name: 'Close' }).click();
    await expect(module.getByRole('heading', { name: 'Connections', exact: true })).toBeVisible();
  });

  test('rejects a host that is not allowed and keeps the form values', async ({ page }) => {
    await page.goto(`${MODULE_PATH}/newConnection`);
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

    await page.goto(`${MODULE_PATH}/connections`);
    await expect(module.getByText('You have no connections yet.')).toBeVisible();
  });

  test('item table sorts newest first and by any column', async ({ page }) => {
    await page.goto(MODULE_PATH);
    const module = moduleFrame(page);

    const item = (title: string, connection: string, updatedAt: string) => ({
      title, connection, updatedAt, updatedAtFormatted: updatedAt, url: '', project: 'acme/app', reference: '',
      author: 'jane', reason: '', labels: [], draft: false, provider: 'GitLab',
    });
    const items = [
      item('Banana', 'Zeta', '2026-11-01T10:00:00+00:00'),
      item('apple', 'Alpha', '2026-11-20T10:00:00+00:00'),
      item('Cherry', 'Mid', '2026-11-10T10:00:00+00:00'),
    ];
    const labels = {
      'column.title': 'Title', 'column.project': 'Project', 'column.author': 'Author', 'column.reason': 'Reason',
      'column.updated': 'Updated', 'column.connection': 'Connection', 'item.draft': 'Draft', 'overview.nothing': 'Nothing here.',
      'sort.ascending': 'Sort ascending', 'sort.descending': 'Sort descending',
    };
    await module.locator('body').evaluate(async (body, { items, labels }) => {
      await customElements.whenDefined('unity-tracker-item-table');
      const table = document.createElement('unity-tracker-item-table');
      table.id = 'sort-test';
      Object.assign(table, { category: 'assignedIssue', items, labels });
      body.prepend(table);
    }, { items, labels });

    const table = module.locator('#sort-test');
    const titles = table.locator('tbody tr td:first-child');
    await expect(titles).toHaveText(['apple', 'Cherry', 'Banana']);
    await expect(table.locator('th[aria-sort="descending"]')).toHaveAttribute('data-column', 'updated');

    const titleHeader = table.locator('th[data-column="title"]');
    await titleHeader.getByRole('button', { name: 'Title' }).click();
    await titleHeader.getByRole('button', { name: 'Sort ascending' }).click();
    await expect(titles).toHaveText(['apple', 'Banana', 'Cherry']);
    await expect(titleHeader).toHaveAttribute('aria-sort', 'ascending');
    await expect(titleHeader.getByRole('button', { name: 'Sort ascending' })).toBeHidden();

    const connectionHeader = table.locator('th[data-column="connection"]');
    await connectionHeader.getByRole('button', { name: 'Connection' }).click();
    await connectionHeader.getByRole('button', { name: 'Sort descending' }).click();
    await expect(titles).toHaveText(['Banana', 'Cherry', 'apple']);
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
    // Rendered by the <unity-tracker-item-table> web component.
    await expect(module.locator('[data-category="todo"]')).toHaveText('Nothing here.');
    await expect(module.locator('[data-category="assignedIssue"]')).toBeHidden();

    await module.getByRole('tab', { name: 'Issues assigned to me' }).click();
    await expect(module.locator('[data-category="assignedIssue"]')).toBeVisible();
    await expect(module.locator('[data-category="todo"]')).toBeHidden();

    await page.goto(`${MODULE_PATH}/connections`);
    const row = module.locator('[data-connections] tbody tr', { hasText: 'Broken GitLab' });
    await expect(row).toContainText('GitLab');
    await row.getByRole('button', { name: 'Delete' }).click();

    await expect(module.getByText('Connection deleted.')).toBeVisible();
    await expect(module.getByText('You have no connections yet.')).toBeVisible();
  });
});
