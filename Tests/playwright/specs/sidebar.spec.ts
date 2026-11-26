import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

/**
 * Sidebar of merge request acme/app!12 in the fake GitLab (Tests/playwright/fake-gitlab/FakeGitLab.php):
 * no assignees, reviewer jane, milestone "v1.0", unlocked, tester subscribed. Users: jane, joe.
 */
test.describe('Detail sheet sidebar', () => {
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

  test('anybody is assigned with suggestions, and removed again', async ({ page }) => {
    const sheet = await openSheet(page);
    const assignees = sheet.locator('[data-sidebar="assignees"]');
    const field = assignees.getByRole('combobox', { name: 'Assignee to add' });

    await field.pressSequentially('jo');
    await expect(assignees.locator('datalist option[value="joe"]')).toHaveAttribute('label', 'joe Joe Bloggs');
    await field.fill('joe');
    await assignees.getByRole('button', { name: 'Add' }).click();
    await expect(sheet.locator('[data-sidebar="assignees"] [data-user="joe"]')).toBeVisible();

    await sheet.locator('[data-sidebar="assignees"]').getByRole('button', { name: 'Remove assignee joe' }).click();
    await expect(sheet.locator('[data-sidebar="assignees"] [data-user]')).toHaveCount(0);
  });

  test('reviewers are requested and removed', async ({ page }) => {
    const sheet = await openSheet(page);
    const reviewers = () => sheet.locator('[data-sidebar="reviewers"]');

    await expect(reviewers().locator('[data-user="jane"]')).toContainText('pending');
    await reviewers().getByRole('combobox', { name: 'Reviewer to add' }).fill('joe');
    await reviewers().getByRole('button', { name: 'Add' }).click();
    await expect(reviewers().locator('[data-user="joe"]')).toBeVisible();
    await reviewers().getByRole('button', { name: 'Remove reviewer jane' }).click();
    await expect(reviewers().locator('[data-user]')).toHaveCount(1);
    await expect(reviewers().locator('[data-user="joe"]')).toContainText('pending');
  });

  test('milestone, lock and notifications are changed', async ({ page }) => {
    const sheet = await openSheet(page);
    const sidebar = sheet.locator('[data-item-sidebar]');

    await expect(sidebar.locator('[data-item-milestone]')).toHaveText('v1.0');
    await sidebar.getByRole('combobox', { name: 'Milestone' }).selectOption('v1.1');
    await sidebar.getByRole('button', { name: 'Set' }).click();
    await expect(sidebar.locator('[data-item-milestone]')).toHaveText('v1.1');

    await sidebar.getByRole('button', { name: 'Lock discussion' }).click();
    await expect(sidebar.locator('[data-sidebar="lock"]')).toContainText('Locked: only members can comment.');
    await expect(sidebar.getByRole('button', { name: 'Unlock discussion' })).toBeVisible();

    await sidebar.getByRole('button', { name: 'Unsubscribe' }).click();
    await expect(sidebar.locator('[data-sidebar="notifications"]')).toContainText('You get no notifications.');
    await expect(sidebar.locator('[data-sidebar="participants"]')).toContainText('jane, tester');
  });
});
