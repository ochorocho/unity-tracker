import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { query } from '../support/database';
import { login, loginAsAdmin } from '../support/typo3';

const MODULE_PATH = '/typo3/module/user/unity-tracker';

function moduleFrame(page: Page): FrameLocator {
  return page.frameLocator('#typo3-contentIframe');
}

/** A connection whose token cannot be decrypted: the overview renders, without calling any API. */
async function addBrokenConnection(): Promise<void> {
  await query(
    `INSERT INTO tx_unitytracker_connection (be_user, provider, host, label, account, token, crdate)
     SELECT uid, 'gitlab', 'gitlab.com', 'Broken GitLab', 'admin', 'v1:broken', UNIX_TIMESTAMP() FROM be_users WHERE username = 'admin'`
  );
}

const item = (category: string, title: string, author: string, labels: string[]) => ({
  category, title, author, labels, type: 'merge_request', url: `https://gitlab.com/acme/app/-/merge_requests/${title.length}`,
  project: 'acme/app', reference: '', reason: '', draft: false, provider: 'GitLab', connection: 'Work',
  updatedAt: '2026-11-20T10:00:00+00:00', updatedAtFormatted: '20.11.2026 10:00',
});

test.describe('Overview filter', () => {
  test.beforeEach(async ({ page }) => {
    await query('DELETE FROM tx_unitytracker_connection');
    await query('DELETE FROM tx_unitytracker_filter');
    await query('DELETE FROM cache_unitytracker');
    await query("DELETE FROM be_users WHERE username = 'editor'");
    await query("DELETE FROM be_groups WHERE title IN ('Editors', 'Others')");
    await query("INSERT INTO be_groups (pid, title, groupMods) VALUES (0, 'Editors', 'unity_tracker'), (0, 'Others', 'unity_tracker')");
    await loginAsAdmin(page);
  });

  test('filters items live with tokens and text, without showing labels', async ({ page }) => {
    await page.goto(MODULE_PATH);
    const module = moduleFrame(page);
    // Without a connection the page has no overview element: render one with known items where the real one goes.
    await module.locator('.module-body').evaluate(async (body, items) => {
      await customElements.whenDefined('unity-tracker-overview');
      const overview = document.createElement('unity-tracker-overview');
      overview.id = 'filter-test';
      Object.assign(overview, {
        items,
        categories: [{ key: 'todo', attention: true }, { key: 'assignedIssue', attention: false }],
        labels: {
          'category.todo': 'To-dos', 'category.assignedIssue': 'Issues assigned to me', 'overview.nothing': 'Nothing here.',
          'column.title': 'Title', 'filter.field.author': 'Author', 'filter.field.label': 'Label', 'filter.field.draft': 'Draft',
          'filter.operator.is': 'is', 'filter.operator.isNot': 'is not', 'filter.placeholder': 'Search or filter results…',
          'filter.searchFor': 'Search for "%s"', 'filter.search': 'Text', 'filter.removeToken': 'Remove %s', 'filter.clear': 'Clear filter',
        },
      });
      body.prepend(overview);
    }, [
      item('todo', 'Fix login', 'jane', ['bug']),
      item('todo', 'Bump lodash', 'bot', ['deps']),
      item('assignedIssue', 'Crash on start', 'jane', ['bug', 'wontfix']),
    ]);

    const overview = module.locator('#filter-test');
    const titles = overview.locator('tbody tr td:first-child');
    const tab = (name: string) => overview.getByRole('tab', { name });
    const input = overview.getByRole('combobox', { name: 'Search or filter results…' });

    await expect(titles).toHaveText(['Fix login', 'Bump lodash']);
    await expect(overview.locator('tbody')).not.toContainText('bug');
    await expect(overview.locator('[data-attention-count]')).toHaveText('2');

    // Pointer: field → operator → suggested value.
    await input.click();
    await overview.getByRole('option', { name: 'Author', exact: true }).click();
    await overview.getByRole('option', { name: 'is', exact: true }).click();
    await overview.getByRole('option', { name: 'jane', exact: true }).click();
    await expect(overview.locator('[data-token]')).toHaveText(['Author is jane ×']);
    await expect(titles).toHaveText(['Fix login']);
    await expect(overview.locator('[data-attention-count]')).toHaveText('1');
    await expect(tab('Issues assigned to me')).toContainText('1');

    // Keyboard: "Lab" → Label, is not, typed value.
    await input.fill('Lab');
    await input.press('ArrowDown');
    await input.press('ArrowDown');
    await input.press('Enter');
    await input.press('ArrowDown');
    await input.press('ArrowDown');
    await input.press('Enter');
    await input.fill('wontfix');
    await input.press('Enter');
    await expect(overview.locator('[data-token]')).toHaveText(['Author is jane ×', 'Label is not wontfix ×']);
    // Suggestions stay open for the next token (as in GitLab) and float over the tabs: close them first.
    await input.press('Escape');
    await expect(overview.getByRole('listbox')).toBeHidden();
    await tab('Issues assigned to me').click();
    await expect(overview.locator('[data-category="assignedIssue"]')).toHaveText('Nothing here.');

    // Backspace in the empty input removes the last token.
    await input.press('Backspace');
    await expect(titles).toHaveText(['Crash on start']);

    // Text search, then clear everything.
    await input.fill('crash');
    await input.press('Enter');
    await expect(overview.locator('[data-token]').first()).toHaveText('Text: crash ×');
    await expect(tab('To-dos')).toContainText('0');
    await overview.getByRole('button', { name: 'Clear filter' }).click();
    await expect(overview.locator('[data-token]')).toHaveCount(0);
    await expect(tab('To-dos')).toContainText('2');
  });

  test('saves a filter, shares it with a group and applies it', async ({ page }) => {
    await addBrokenConnection();
    await page.goto(MODULE_PATH);
    const module = moduleFrame(page);
    const input = module.getByRole('combobox', { name: 'Search or filter results…' });

    await input.click();
    await module.getByRole('option', { name: 'Author', exact: true }).click();
    await module.getByRole('option', { name: 'is not', exact: true }).click();
    await input.fill('dependabot');
    await input.press('Enter');
    await module.getByRole('button', { name: 'Save filter' }).click();

    await expect(module.getByRole('heading', { name: 'Save filter' })).toBeVisible();
    await expect(module.locator('[data-filter-tokens]')).toHaveText(/Author is not dependabot/);
    await module.getByLabel('Name').fill('No bots');
    await module.getByLabel('Editors').check();
    await module.getByRole('button', { name: 'Save', exact: true }).click();

    await expect(module.getByText('Filter "No bots" saved.')).toBeVisible();
    await expect(page).toHaveURL(/filter=\d+/);
    await expect(module.locator('[data-token]')).toHaveText(['Author is not dependabot ×']);
    await expect(module.getByRole('combobox', { name: 'Search or filter results…' })).toBeVisible();
    await expect(module.locator('unity-tracker-overview select')).toHaveValue(/\d+/);

    await page.goto(`${MODULE_PATH}/filters`);
    const row = module.locator('[data-filters="own"] tbody tr', { hasText: 'No bots' });
    await expect(row).toContainText('Editors');
    await expect(row).toContainText('Author is not dependabot');
  });

  test('a shared filter is visible to group members, read-only', async ({ page }) => {
    await query(
      `INSERT INTO be_users (pid, username, password, usergroup, admin, crdate, tstamp)
       SELECT 0, 'editor', password, (SELECT uid FROM be_groups WHERE title = 'Editors'), 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
       FROM be_users WHERE username = 'admin'`
    );
    const definition = JSON.stringify({ search: '', tokens: [{ field: 'label', operator: 'is', value: 'bug' }] });
    await query(
      `INSERT INTO tx_unitytracker_filter (be_user, title, definition, be_groups, crdate, tstamp)
       SELECT u.uid, f.title, ?, (SELECT uid FROM be_groups WHERE title = f.grp), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
       FROM be_users u JOIN (SELECT 'Bugs for editors' AS title, 'Editors' AS grp UNION SELECT 'Bugs for others', 'Others') f
       WHERE u.username = 'admin'`,
      [definition]
    );
    await query(
      `INSERT INTO tx_unitytracker_filter (be_user, title, definition, be_groups, crdate, tstamp)
       SELECT uid, 'Private bugs', ?, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM be_users WHERE username = 'admin'`,
      [definition]
    );

    await page.context().clearCookies();
    await login(page, 'editor');
    await page.goto(`${MODULE_PATH}/filters`);
    const module = moduleFrame(page);

    const shared = module.locator('[data-filters="shared"] tbody tr');
    await expect(shared).toHaveCount(1);
    await expect(shared).toContainText('Bugs for editors');
    await expect(shared).toContainText('admin');
    await expect(shared.getByRole('button')).toHaveCount(0);
    await expect(module.getByText('You have no saved filters yet.')).toBeVisible();

    // Editing someone else's filter is refused.
    const [{ uid }] = await query<{ uid: number }>("SELECT uid FROM tx_unitytracker_filter WHERE title = 'Bugs for editors'");
    await page.goto(`${MODULE_PATH}/editFilter?uid=${uid}`);
    await expect(module.getByText('The filter does not exist or is not yours.')).toBeVisible();
  });
});
