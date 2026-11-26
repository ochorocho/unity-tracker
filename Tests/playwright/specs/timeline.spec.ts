import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Activity of merge request acme/app!12 in the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php):
 * comments of jane (101) and tester (102), a system note, jane's unresolved diff thread on src/Login.php
 * and one older page with "created branch fix/login".
 */
test.describe('Detail sheet activity', () => {
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

  test('events, threads and older activity are shown', async ({ page }) => {
    const sheet = await openSheet(page);

    await expect(sheet.locator('[data-event]')).toContainText('jane requested review from @tester');
    const thread = sheet.locator('[data-thread="d4"]');
    await expect(thread).toContainText('src/Login.php:12');
    await expect(thread.getByRole('link', { name: 'View in diff' })).toHaveAttribute('href', /#note_104$/);

    await sheet.getByRole('button', { name: 'Show older activity' }).click();
    await expect(sheet.locator('[data-event]').first()).toContainText('jane created branch fix/login');
    await expect(sheet.getByRole('button', { name: 'Show older activity' })).toHaveCount(0);
  });

  test('a thread is replied to and resolved in the background', async ({ page }) => {
    const sheet = await openSheet(page);
    const thread = sheet.locator('[data-thread="d4"]');

    await thread.getByRole('button', { name: 'Reply' }).click();
    const textarea = thread.getByRole('combobox', { name: 'Reply (Markdown)' });
    await expect(textarea).toBeFocused();
    await textarea.fill('Fixed the typo.');
    await thread.getByRole('button', { name: 'Reply', exact: true }).last().click();
    await expect(sheet.locator('[data-thread="d4"] [data-comment]')).toHaveCount(2);
    await expect(sheet.locator('[data-thread="d4"]')).toContainText('Fixed the typo.');

    await sheet.locator('[data-thread="d4"]').getByRole('button', { name: 'Resolve thread' }).click();
    await expect(sheet.locator('[data-thread="d4"]')).toHaveAttribute('data-resolved', '');
    await expect(sheet.locator('[data-thread="d4"]').getByRole('button', { name: 'Unresolve thread' })).toBeVisible();
  });

  test('only the own comment can be deleted, after a confirmation', async ({ page }) => {
    const sheet = await openSheet(page);

    await expect(sheet.locator('[data-comment="101"]').getByRole('button', { name: 'Delete' })).toHaveCount(0);
    await sheet.locator('[data-comment="102"]').getByRole('button', { name: 'Delete' }).click();
    const confirm = page.locator('typo3-backend-modal').filter({ hasText: 'Delete this comment?' });
    await confirm.getByRole('button', { name: 'Delete', exact: true }).click();

    await expect(sheet.locator('[data-comment="102"]')).toHaveCount(0);
    await expect(sheet.locator('[data-comment="101"]')).toBeVisible();
  });
});
