-- Minimal content for the Playwright specs. Imported into fresh `db`/`test`
-- databases by .ddev/scripts/setup-typo3.sh and before each Playwright run.
INSERT INTO pages (uid, pid, title, slug, doktype, is_siteroot, perms_userid, perms_groupid, perms_user, perms_group, perms_everybody, crdate, tstamp)
VALUES (1, 0, 'Tracker Home', '/', 1, 1, 2, 0, 31, 27, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE title = 'Tracker Home', deleted = 0, hidden = 0;
