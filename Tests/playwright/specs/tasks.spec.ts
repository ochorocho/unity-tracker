import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Task lists of merge request acme/app!12 in the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php):
 * the description has "- [x] Tests" and "- [ ] Docs"; tester may edit it and their own comment 102.
 */
test.describe('Detail sheet task lists', () => {
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

  test('a task of the description is ticked and saved in the Markdown', async ({ page }) => {
    const sheet = await openSheet(page);
    const description = sheet.locator('[data-item-description]');

    await description.getByRole('checkbox', { name: 'Check option: Docs' }).check();
    // Disabled while the change is sent, enabled again in the re-rendered description.
    await expect(description.getByRole('checkbox', { name: 'Check option: Docs' })).toBeEnabled();
    await expect(description.getByRole('checkbox', { name: 'Check option: Docs' })).toBeChecked();
    await description.getByRole('checkbox', { name: 'Check option: Tests' }).uncheck();
    await expect(description.getByRole('checkbox', { name: 'Check option: Tests' })).not.toBeChecked();

    await sheet.locator('header').getByRole('button', { name: 'Edit' }).click();
    await expect(description.locator('textarea')).toHaveValue('Fixes the **login** redirect.\n\n- [ ] Tests\n- [x] Docs');
  });

  test('a task of the own comment is ticked', async ({ page }) => {
    const sheet = await openSheet(page);
    const mine = sheet.locator('[data-comment="102"]');

    await mine.getByRole('button', { name: 'Edit' }).click();
    await mine.locator('textarea').fill('- [ ] step one\n- [ ] step two');
    await mine.getByRole('button', { name: 'Save' }).click();
    await expect(mine.getByRole('checkbox', { name: 'Check option: step two' })).toBeEnabled();

    await mine.getByRole('checkbox', { name: 'Check option: step two' }).check();
    await expect(mine.getByRole('checkbox', { name: 'Check option: step two' })).toBeEnabled();
    await expect(sheet.locator('[data-comment="102"]').getByRole('checkbox', { name: 'Check option: step two' })).toBeChecked();
    await sheet.locator('[data-comment="102"]').getByRole('button', { name: 'Edit' }).click();
    await expect(sheet.locator('[data-comment="102"] textarea')).toHaveValue('- [ ] step one\n- [x] step two');
  });
});
