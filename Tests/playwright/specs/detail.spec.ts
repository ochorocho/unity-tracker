import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

function moduleFrame(page: Page): FrameLocator {
  return page.frameLocator('#typo3-contentIframe');
}

const item = (connectionUid: number, category: string, title: string, type = 'merge_request') => ({
  connectionUid, category, title, type, number: 12, todoId: '55', project: 'acme/app', reference: '!12',
  url: 'https://gitlab.com/acme/app/-/merge_requests/12', author: 'jane', labels: ['bug'], reason: 'assigned',
  draft: false, provider: 'GitLab', connection: 'Broken GitLab', updatedAt: '2026-11-20T10:00:00+00:00',
  updatedAtFormatted: '20.11.2026 10:00',
});

test.describe('Item detail sheet', () => {
  let connectionUid = 0;

  test.beforeEach(async ({ page }) => {
    await query('DELETE FROM tx_unitytracker_connection');
    await query('DELETE FROM cache_unitytracker');
    // A token that cannot be decrypted: the overview renders, no API is called.
    await query(
      `INSERT INTO tx_unitytracker_connection (be_user, provider, host, label, account, token, crdate)
       SELECT uid, 'gitlab', 'gitlab.com', 'Broken GitLab', 'admin', 'v1:broken', UNIX_TIMESTAMP() FROM be_users WHERE username = 'admin'`
    );
    [{ uid: connectionUid }] = await query<{ uid: number }>('SELECT uid FROM tx_unitytracker_connection');
    await loginAsAdmin(page);
    await page.goto(MODULE_PATH);
  });

  async function showItems(page: Page, items: unknown[]): Promise<void> {
    await moduleFrame(page).locator('unity-tracker-overview').evaluate((overview: HTMLElement & { items: unknown[] }, rows) => {
      overview.items = rows;
    }, items);
  }

  test('a merge request opens in the sheet, other items link to GitLab', async ({ page }) => {
    await showItems(page, [
      item(connectionUid, 'todo', 'Fix login redirect'),
      { ...item(connectionUid, 'todo', 'v2.0.0 released', 'release'), number: 0 },
    ]);
    const module = moduleFrame(page);

    await expect(module.getByRole('link', { name: 'v2.0.0 released' })).toHaveAttribute('target', '_blank');
    await expect(module.getByRole('link', { name: 'Open in GitLab' })).toHaveAttribute('href', 'https://gitlab.com/acme/app/-/merge_requests/12');

    await module.getByRole('link', { name: 'Fix login redirect' }).click();
    const sheet = page.locator('typo3-backend-modal');
    await expect(sheet.locator('dialog')).toHaveClass(/modal-position-sheet/);
    const detail = page.frameLocator('typo3-backend-modal iframe');
    // The token cannot be decrypted, so the sheet reports that instead of calling GitLab.
    await expect(detail.getByText('Could not load the details')).toBeVisible();
    await expect(detail.getByText('The stored token cannot be decrypted')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
  });

  test('suggestions overlay the tabs and the table keeps a gap to them', async ({ page }) => {
    await showItems(page, [item(connectionUid, 'todo', 'Fix login redirect')]);
    const module = moduleFrame(page);
    const tabs = module.locator('unity-tracker-overview .nav-tabs');

    const before = await tabs.boundingBox();
    await module.getByRole('combobox', { name: 'Search or filter results…' }).click();
    await expect(module.getByRole('listbox')).toBeVisible();
    expect(await tabs.boundingBox()).toEqual(before);

    const table = await module.locator('unity-tracker-item-table table').boundingBox();
    expect(table!.y - (before!.y + before!.height)).toBeGreaterThanOrEqual(8);
  });
});
