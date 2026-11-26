import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Linked items, internal notes, uploads and quick actions of merge request acme/app!12 in the fake GitLab
 * (Tests/playwright/fake-gitlab/FakeGitLab.php): it closes issue acme/app#3.
 */
test.describe('Detail sheet links and editor extras', () => {
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

  test('a closed issue is linked and opens in the sheet', async ({ page }) => {
    const sheet = await openSheet(page);
    const linked = sheet.locator('[data-sidebar="linked"] [data-linked="acme/app#3"]');

    await expect(linked).toContainText('Closes');
    await expect(linked).toContainText('Open');
    const link = linked.getByRole('link', { name: 'Crash on start' });
    await expect(link).toHaveAttribute('href', /\/typo3\/module\/user\/unity-tracker\/item\?.*type=issue/);
    await expect(link).toHaveAttribute('href', /project=acme(%2F|\/)app&number=3/);
  });

  test('an internal note is added and marked', async ({ page }) => {
    const sheet = await openSheet(page);
    const form = sheet.locator('[data-new-comment]');

    await form.locator('textarea').fill('Only for the team.');
    await form.getByRole('button', { name: 'Add internal note' }).click();
    const note = sheet.locator('[data-comment]').last();
    await expect(note).toContainText('Only for the team.');
    await expect(note.locator('[data-internal]')).toHaveText('Internal');
  });

  test('an attached file is uploaded and its Markdown inserted', async ({ page }) => {
    const sheet = await openSheet(page);
    const form = sheet.locator('[data-new-comment]');

    await form.locator('textarea').fill('See ');
    await form.locator('input[type="file"]').setInputFiles({ name: 'shot.png', mimeType: 'image/png', buffer: Buffer.from('png') });
    await expect(form.locator('textarea')).toHaveValue('See ![shot.png](/uploads/0123456789abcdef0123456789abcdef/shot.png)\n');
    await expect(form.getByRole('button', { name: 'Attach a file (or paste/drop it)' })).toBeVisible();
  });

  test('quick actions are suggested at the start of a line', async ({ page }) => {
    const sheet = await openSheet(page);
    const form = sheet.locator('[data-new-comment]');
    const textarea = form.getByRole('combobox', { name: 'Comment (Markdown)' });

    await textarea.click();
    await textarea.pressSequentially('/rea');
    await expect(form.getByRole('option')).toHaveText(['/react :emoji:', '/ready']);
    await textarea.press('ArrowDown');
    await textarea.press('Enter');
    await expect(textarea).toHaveValue('/ready ');

    await textarea.pressSequentially('and /rea');
    await expect(form.getByRole('option')).toHaveCount(0);
  });
});
