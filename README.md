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

Up to 50 entries per list, newest first. Archived GitHub repositories are excluded.

## Connections

Every backend user manages their own connections under **Connections** in the module. Any number
of them, for example gitlab.com, a self-managed GitLab, github.com and GitHub Enterprise Server.

- Personal access token scopes: GitLab `read_api`. GitHub: a classic token with `repo` and
  `notifications`. Fine-grained GitHub tokens can't read notifications.
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
