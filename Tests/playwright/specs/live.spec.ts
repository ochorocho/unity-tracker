import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Live updates through the Mercure hub of DDEV (.ddev/docker-compose.mercure.yaml) and the messenger worker of the
 * test database (.ddev/scripts/messenger-worker.sh test). The subscriber cookie is `__Secure-…` and the hub is
 * served on the site's origin: these specs need the HTTPS URL, not http://web.
 */
test.use({ baseURL: 'https://unity-tracker.ddev.site', ignoreHTTPSErrors: true });

test.describe('Live updates', () => {
  test.beforeEach(async ({ page }) => {
    await query('DELETE FROM tx_unitytracker_connection');
    await query('DELETE FROM cache_unitytracker');
    await query('DELETE FROM sys_messenger_messages');
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

  async function openSheet(page: Page, title = 'Fix login redirect'): Promise<FrameLocator> {
    await page.goto(MODULE_PATH);
    await page.frameLocator('#typo3-contentIframe').getByRole('link', { name: title }).click();
    const sheet = page.frameLocator('typo3-backend-modal iframe');
    await expect(sheet.getByRole('heading', { name: title })).toBeVisible();
    return sheet;
  }

  test('a change in one sheet reaches the other sheets and the overview', async ({ page, context }) => {
    const watching = await openSheet(page);
    await expect(watching.locator('[data-item-detail]')).toHaveAttribute('data-live-url', /\/\.well-known\/mercure\?match=/);
    // Lets the EventSource connect before anything is published.
    await page.waitForTimeout(1000);

    const editing = await context.newPage();
    const sheet = await openSheet(editing);
    await sheet.locator('header').getByRole('button', { name: 'Edit' }).click();
    await sheet.getByRole('textbox', { name: 'Title' }).fill('Fix the login redirect');
    await sheet.locator('[data-item-description]').getByRole('button', { name: 'Save' }).click();
    await expect(sheet.locator('[data-item-title]')).toHaveText('Fix the login redirect');

    // The other sheet re-renders without a reload, the overview behind it gets the worker's new rows.
    await expect(watching.locator('[data-item-title]')).toHaveText('Fix the login redirect', { timeout: 15_000 });
    await expect(page.frameLocator('#typo3-contentIframe').getByRole('link', { name: 'Fix the login redirect' }))
      .toBeVisible({ timeout: 20_000 });
    // The sending sheet ignores its own update.
    await expect(editing.locator('typo3-notification-message', { hasText: 'changed in another window' })).toHaveCount(0);
  });

  test('a sheet with unsaved input offers a reload instead', async ({ page, context }) => {
    const watching = await openSheet(page);
    await watching.locator('header').getByRole('button', { name: 'Edit' }).click();
    await watching.getByRole('textbox', { name: 'Title' }).fill('My unsaved title');
    await page.waitForTimeout(1000);

    const editing = await context.newPage();
    const sheet = await openSheet(editing);
    const bar = sheet.locator('[data-item-description] [data-reactions=""]');
    await bar.getByRole('button', { name: ':thumbsup: (1)' }).click();
    await expect(bar.getByRole('button', { name: ':thumbsup: (2)' })).toBeVisible();

    const notice = page.locator('typo3-notification-message', { hasText: 'changed in another window' });
    await expect(notice).toBeVisible({ timeout: 15_000 });
    await expect(notice.getByRole('link', { name: 'Reload' })).toBeVisible();
    await expect(watching.getByRole('textbox', { name: 'Title' })).toHaveValue('My unsaved title');
  });
});
