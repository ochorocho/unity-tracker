import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Merge widget of merge request acme/app!12 in the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php):
 * the pipeline is running, so it cannot be merged yet, only set to merge automatically.
 */
test.describe('Detail sheet merge widget', () => {
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

  test('a running pipeline blocks the merge, auto-merge is set and canceled', async ({ page }) => {
    const sheet = await openSheet(page);
    const widget = () => sheet.locator('[data-merge-widget]');

    await expect(widget().locator('[data-pipeline]')).toHaveText('Checks running');
    await expect(widget().getByRole('link', { name: 'Details' })).toHaveAttribute('href', 'https://gitlab.fake.test/acme/app/-/pipelines/7');
    await expect(widget().locator('[data-blocker]')).toHaveText(['The pipeline/checks must pass']);
    await expect(widget().getByRole('button', { name: 'Merge', exact: true })).toHaveCount(0);
    await expect(widget().getByRole('checkbox', { name: 'Delete source branch' })).toBeChecked();

    await widget().getByRole('button', { name: 'Merge when checks pass' }).click();
    await expect(widget().locator('[data-auto-merge]')).toHaveText('Merges automatically when the checks pass.');
    await widget().getByRole('button', { name: 'Cancel auto-merge' }).click();
    await expect(widget().getByRole('button', { name: 'Merge when checks pass' })).toBeVisible();
  });

  test('a merge request is marked as draft and ready again', async ({ page }) => {
    const sheet = await openSheet(page);
    const widget = () => sheet.locator('[data-merge-widget]');

    await widget().getByRole('button', { name: 'Mark as draft' }).click();
    await expect(sheet.locator('[data-item-title]')).toContainText('Draft');
    await expect(widget().locator('[data-blocker="draft_status"]')).toHaveText('It is a draft');
    await widget().getByRole('button', { name: 'Mark as ready' }).click();
    await expect(widget().getByRole('button', { name: 'Mark as draft' })).toBeVisible();
    await expect(widget().locator('[data-blocker="draft_status"]')).toHaveCount(0);
  });
});
