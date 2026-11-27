#!/usr/bin/env bash
# Messenger worker of the dev install (web_extra_daemons in config.yaml): publishes Mercure updates and runs
# the overview refreshes of EXT:unity_tracker. Waits until setup-typo3.sh has provisioned TYPO3.
# Argument "test": the worker of the Playwright database (with the fake GitLab, see additional.php).
# Restart both after PHP or DI changes: ddev exec sudo supervisorctl restart 'webextradaemons:*'
set -u
if [ "${1:-}" = "test" ]; then
    export TYPO3_DB_NAME=test
fi
cd /var/www/html/Build
while [ ! -x vendor/bin/typo3 ] || [ ! -f config/system/settings.php ] || [ ! -f vendor/ochorocho/typo3-mercure/composer.json ]; do
    sleep 5
done
# Restarted by supervisord: picks up code changes at least every 10 minutes.
exec vendor/bin/typo3 messenger:consume doctrine --time-limit=600 --memory-limit=256M
