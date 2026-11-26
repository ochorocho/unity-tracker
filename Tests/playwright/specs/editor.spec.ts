import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Runs against the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php): user "tester" with one
 * to-do, merge request acme/app!12, commented by jane ("Please add a test.") and tester ("Will do."),
 * plus a diff thread of jane.
 */
test.describe('Detail sheet editing', () => {
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
    // Gone after a page load: proves that the sheet did not reload.
    await page.locator('typo3-backend-modal iframe').evaluate((iframe: HTMLIFrameElement) => {
      (iframe.contentWindow as Window & { notReloaded?: boolean }).notReloaded = true;
    });
    return sheet;
  }

  async function notReloaded(page: Page): Promise<boolean> {
    return page.locator('typo3-backend-modal iframe').evaluate(
      (iframe: HTMLIFrameElement) => (iframe.contentWindow as Window & { notReloaded?: boolean }).notReloaded === true
    );
  }

  test('a comment with suggestions and preview is added in the background', async ({ page }) => {
    const sheet = await openSheet(page);
    const form = sheet.locator('[data-new-comment]');
    const textarea = form.getByRole('combobox', { name: 'Comment (Markdown)' });

    await textarea.click();
    await textarea.pressSequentially('Thanks @ja');
    await expect(form.getByRole('option', { name: '@jane Jane Doe' })).toBeVisible();
    await textarea.press('Enter');
    await textarea.pressSequentially('**great** :tad');
    await expect(form.getByRole('option', { name: '🎉 :tada:' })).toBeVisible();
    await textarea.press('Enter');
    await expect(textarea).toHaveValue('Thanks @jane **great** :tada: ');

    await form.getByRole('tab', { name: 'Preview' }).click();
    await expect(form.locator('[data-preview] strong')).toHaveText('great');
    await form.getByRole('tab', { name: 'Write' }).click();

    await form.getByRole('button', { name: 'Comment', exact: true }).click();
    const comments = sheet.locator('[data-comment]');
    await expect(comments).toHaveCount(4);
    await expect(comments.last()).toContainText('Thanks @jane great :tada:');
    await expect(sheet.locator('[data-new-comment] textarea')).toHaveValue('');
    expect(await notReloaded(page)).toBe(true);
  });

  test('images load through the backend, with the token', async ({ page }) => {
    const sheet = await openSheet(page);
    const image = sheet.locator('[data-item-description] img[alt="Screenshot"]');

    await expect(image).toHaveAttribute('src', /\/typo3\/module\/user\/unity-tracker\/itemImage\?/);
    await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(4);
  });

  test('task lists show checkboxes', async ({ page }) => {
    const sheet = await openSheet(page);
    const description = sheet.locator('[data-item-description]');

    await expect(description.getByRole('checkbox', { name: 'Check option: Tests' })).toBeChecked();
    await expect(description.getByRole('checkbox', { name: 'Check option: Docs' })).not.toBeChecked();
    await expect(description).not.toContainText('<input');
  });

  test('toolbar and shortcuts format the selection', async ({ page }) => {
    const sheet = await openSheet(page);
    const form = sheet.locator('[data-new-comment]');
    const textarea = form.locator('textarea');

    await textarea.fill('word');
    await textarea.selectText();
    await form.getByRole('button', { name: 'Bold (Ctrl/⌘+B)' }).click();
    await expect(textarea).toHaveValue('**word**');
    await textarea.press('End');
    await textarea.press('Enter');
    await form.getByRole('button', { name: 'Task list' }).click();
    await expect(textarea).toHaveValue('**word**\n- [ ] ');
  });

  test('Enter continues lists and ends them on an empty item', async ({ page }) => {
    const sheet = await openSheet(page);
    const textarea = sheet.locator('[data-new-comment] textarea');

    await textarea.click();
    await textarea.pressSequentially('- one');
    await textarea.press('Enter');
    await textarea.pressSequentially('two');
    await textarea.press('Enter');
    await textarea.press('Enter');
    await expect(textarea).toHaveValue('- one\n- two\n');

    await textarea.pressSequentially('9. nine');
    await textarea.press('Enter');
    await textarea.pressSequentially('ten');
    await textarea.press('Enter');
    await textarea.press('Enter');
    await textarea.pressSequentially('  - [x] done');
    await textarea.press('Enter');
    await textarea.pressSequentially('next');
    await textarea.press('Shift+Enter');
    await expect(textarea).toHaveValue('- one\n- two\n9. nine\n10. ten\n  - [x] done\n  - [ ] next\n');
  });

  test('only the own comment can be edited, inline', async ({ page }) => {
    const sheet = await openSheet(page);
    const janes = sheet.locator('[data-comment="101"]');
    const mine = sheet.locator('[data-comment="102"]');

    await expect(janes.getByRole('button', { name: 'Edit' })).toHaveCount(0);
    await mine.getByRole('button', { name: 'Edit' }).click();
    const textarea = mine.locator('textarea');
    await expect(textarea).toHaveValue('Will do.');

    await textarea.fill('Changed my mind');
    await mine.getByRole('button', { name: 'Cancel' }).click();
    await expect(mine).toContainText('Will do.');
    await expect(textarea).toBeHidden();

    await mine.getByRole('button', { name: 'Edit' }).click();
    await expect(textarea).toHaveValue('Will do.');
    await textarea.fill('Done, test added.');
    await mine.getByRole('button', { name: 'Save' }).click();
    await expect(sheet.locator('[data-comment="102"]')).toContainText('Done, test added.');
    await expect(sheet.locator('[data-comment="102"] textarea')).toBeHidden();
    expect(await notReloaded(page)).toBe(true);
  });

  test('title and description are edited in place', async ({ page }) => {
    const sheet = await openSheet(page);
    const title = sheet.getByRole('textbox', { name: 'Title' });

    await expect(title).toBeHidden();
    await sheet.locator('header').getByRole('button', { name: 'Edit' }).click();
    await expect(sheet.locator('[data-item-title]')).toBeHidden();
    await expect(title).toBeFocused();
    await title.fill('Fix the login redirect');
    const description = sheet.locator('[data-item-description] textarea');
    await expect(description).toHaveValue('Fixes the **login** redirect.\n\n- [x] Tests\n- [ ] Docs');
    await description.fill('Fixes the **login** redirect, with a test.');
    await sheet.locator('[data-item-description]').getByRole('button', { name: 'Save' }).click();

    await expect(sheet.locator('[data-item-title]')).toHaveText('Fix the login redirect');
    await expect(sheet.locator('[data-item-description]')).toContainText('Fixes the login redirect, with a test.');
    await expect(title).toBeHidden();
    expect(await notReloaded(page)).toBe(true);

    // Closing the sheet reloads the overview with the new title.
    await page.keyboard.press('Escape');
    await expect(page.frameLocator('#typo3-contentIframe').getByRole('link', { name: 'Fix the login redirect' })).toBeVisible();
  });
});
