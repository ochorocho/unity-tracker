# EXT:unity_tracker

TYPO3 v13.4 / v14.3 backend module (`ochorocho/unity-tracker`) that shows what needs your
attention across all your GitLab and GitHub accounts, in one place.

## What it shows

Module **User › Unity Tracker**, per backend user:

| Section | GitLab | GitHub |
|---|---|---|
| Needs attention: to-dos and notifications | pending to-dos | unread notifications |
| Needs attention: review requested | open MRs with you as reviewer | open PRs `review-requested:@me` |
| My work: assigned merge/pull requests | `scope=assigned_to_me` | `assignee:@me` |
| My work: my open merge/pull requests | `scope=created_by_me` | `author:@me` |
| My work: issues assigned to me | `scope=assigned_to_me` | `assignee:@me` |

Up to 50 entries per list, newest first. Archived GitHub repositories are excluded. Each section is
a tab, every column can be sorted. GitHub notifications have no labels or author, so each issue/pull
request notification costs one extra request (cached with the overview) that adds them.

## Details and actions

Clicking an issue, merge request or pull request opens it in a sheet (TYPO3 v14: the side sheet of
contextual editing; v13: a large modal): description, labels, assignees and the activity. The
icon next to the title opens it on GitLab/GitHub; other items (releases, pipelines, …) link there
directly.

The sheet offers only what the API says the token's user may do, and checks it again before sending:

| Action | GitLab | GitHub |
|---|---|---|
| Comment | ✓ | ✓ |
| Close / reopen | ✓ | ✓ |
| Edit title and description | ✓ | ✓ |
| Assign / unassign me, add / remove label | ✓ | ✓ |
| Approve / revoke approval | merge requests | approve (pull requests) |
| Request changes | – | pull requests |
| Merge (with confirmation) | ✓ (squash, delete source branch) | ✓ (merge method of the repository) |
| Auto-merge when checks pass / cancel | ✓ | ✓ |
| Rebase / update branch, mark as draft / ready | ✓ | ✓ |
| Delete the branch of a merged pull request | – (option of the merge) | ✓ |
| Close as not planned | – | issues |
| Internal note (members only) | Reporter and up | – |
| Attach files in the editor (button, paste, drop; 10 MB) | ✓ | – (no upload API) |
| Mark to-do done / notification read | to-dos | notifications |
| Edit / delete own comments | ✓ | ✓ (submitted reviews: edit only) |
| Reply to a thread | discussions | review threads |
| Resolve / unresolve a thread | resolvable discussions | review threads |
| React (👍 👎 😄 🎉 😕 ❤️ 🚀 👀) to the item and comments | award emoji (others already set can be toggled) | reactions |
| Tick task list items in the description and own comments | ✓ (not `[~]`) | ✓ |
| Add / remove any assignee (with suggestions) | ✓ | ✓ |
| Request / remove reviewers | merge requests | pull requests (pending requests) |
| Set milestone, lock discussion, subscribe | ✓ | ✓ |
| Due date, confidential | issues | – |

The activity reads like on the provider: comments, threads (GitLab discussions incl. diff threads, GitHub
review threads, with file and line) and events (GitLab system notes; GitHub labels, assignees, commits,
reviews, references, …), newest page first; "Show older activity" loads the pages before (route `itemActivity`).

Details come from one GraphQL query per item (rendered HTML and the user's permissions), actions
use the REST API. Actions run in the background: the sheet updates in place, the overview reloads
when the sheet is closed. Title, description and your own comments are edited inline. GitHub threads
are resolved through GraphQL mutations (no REST endpoint), GitLab threads through `discussionToggleResolve`.
Reactions are GraphQL mutations as well (`awardEmojiToggle`, `addReaction`/`removeReaction`). The sidebar
(assignees, reviewers, labels, milestone, due date, confidentiality, lock, notifications, participants) sits
beside the description and activity on wide sheets. Merge and pull requests get a merge widget: pipeline/checks,
approvals or review decision, what blocks the merge (GitLab's mergeability checks, GitHub's merge state) and the
merge options. Merging sends the commit the sheet showed, so a branch that moved on is not merged unseen.
Linked items (closes / closed by, related, blocks / is blocked by) open in the sheet. On GitLab the editor suggests
quick actions (`/assign`, `/label`, …) at the start of a line; GitLab runs them when the comment is saved.

Images in descriptions and comments are loaded by TYPO3 and served from the backend (route
`itemImage`): the backend's Content-Security-Policy blocks other origins, and uploads of non-public
GitLab projects need the token. Only https images on the provider's own hosts are fetched
(GitLab: the instance, gitlab.com's `user-content.gitlab-static.net`; github.com: `camo` and
`*.githubusercontent.com`; GitHub Enterprise: the instance and `media.<host>`), at most 10 MB, image
types only. Other images show their alt text.

Comments and descriptions are written in a Markdown editor like GitLab's: Write/Preview (rendered by
GitLab/GitHub, so references and emoji look as they will), a formatting toolbar, Ctrl/⌘+B/I/K and
Ctrl/⌘+Enter to save, and suggestions while typing: `@` people, `#` issues (GitHub: and pull
requests), `!` merge requests and `~` labels (GitLab), `:` emoji.

## Filters

The bar above the tabs filters all tabs at once, in the browser, like GitLab's filtered search:
pick a field, `is` or `is not`, then a value (suggested from the loaded items, or typed). Plain text
plus Enter searches title, reference and project. All tokens must hold. Backspace in the empty input
removes the last token.

| Field | Matches |
|---|---|
| Author, Project, Connection, Provider, Type, Reason | the item's value (case-insensitive) |
| Label | one of the item's labels (labels are not shown in the table) |
| Draft | `yes` / `no` |

**Save filter** names the current filter and shares it with backend groups (your own groups; admins
can pick any). Members of those groups find it under **Saved filters** and in **Filters**, applied to
their own connections; only the owner can change or delete it. A saved filter's link
(`…/unity-tracker?filter=<uid>`) opens the overview with it applied. Filters are stored in
`tx_unitytracker_filter`, without TCA.

## Connections

Every backend user manages their own connections under **Connections** in the module. Any number
of them, for example gitlab.com, a self-managed GitLab, github.com and GitHub Enterprise Server.

- Personal access token scopes: GitLab `api` (`read_api` is enough to read; the detail sheet is then
  read-only and says so). GitHub: a classic token with `repo` and `notifications`. Fine-grained GitHub
  tokens can't read notifications.
- The token is checked against the API (`/user`) before it is saved, and the account name is stored.
- Tokens are encrypted with libsodium, using a key derived from TYPO3's `encryptionKey`. If that
  key changes, the module asks you to add the connection again.
- Connections have no TCA. They don't appear in the list module, and one user can't see or delete
  another user's connections.

Non-admin users need access to the module in their backend group.

## Configuration

Extension settings (`ext_conf_template.txt`):

- `allowedHosts`: comma-separated self-hosted instances users may connect to, e.g.
  `git.example.com, ghe.example.com:8443`. gitlab.com and github.com are always allowed. Only https
  is accepted. The API is requested by the TYPO3 server, so this list stops editors from pointing it
  at internal addresses.
- `cacheLifetime`: seconds an overview stays cached per user (default 300, `0` disables). The
  module's reload button bypasses the cache. Results with errors are never cached.

Requests go through TYPO3's Guzzle client, so `$GLOBALS['TYPO3_CONF_VARS']['HTTP']` (proxy, CA
bundle, …) applies. All requests of all connections run concurrently. A failing connection is
reported in the module and doesn't hide the others.

## Development

See [CLAUDE.md](CLAUDE.md). In short: `ddev start`, then https://unity-tracker.ddev.site/typo3
(admin / Password.1).
