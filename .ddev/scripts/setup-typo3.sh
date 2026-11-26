#!/usr/bin/env bash
#
# Idempotent bootstrap for the TYPO3 dev installation under Build/.
# Runs as DDEV post-start hook; safe to re-run at any time.
#
#   0. Generate Build/composer.json for ${TYPO3_VERSION} (default ^14.3);
#      a version switch wipes install state and both databases.
#   1. composer update in Build/
#   2. typo3 setup against `db` (editor) and `test` (Playwright)
#   3. Seed fixtures (Tests/playwright/fixtures/seed.sql) into fresh DBs
#   4. Write Build/config/system/additional.php
#
# Inside the web container: /var/www/html = extension, /var/www/html/Build = TYPO3.

set -euo pipefail

PROJECT_NAME="unity-tracker"
BUILD_DIR="/var/www/html/Build"
SETTINGS_FILE="${BUILD_DIR}/config/system/settings.php"
ADDITIONAL_FILE="${BUILD_DIR}/config/system/additional.php"
SEED_FILE="/var/www/html/Tests/playwright/fixtures/seed.sql"
SITES_FIXTURE_DIR="/var/www/html/Tests/playwright/fixtures/sites"
SITE_BASE_URL="https://${PROJECT_NAME}.ddev.site/"
ADMIN_EMAIL="email@example.com"
ADMIN_USER="admin"
ADMIN_PASS='Password.1'

TYPO3_VERSION="${TYPO3_VERSION:-^14.3}"
TYPO3_VERSION_MARKER="${BUILD_DIR}/var/typo3-version"
COMPOSER_JSON="${BUILD_DIR}/composer.json"

mkdir -p "${BUILD_DIR}/var"
cd "${BUILD_DIR}"

if [ -f /var/www/html/package-lock.json ]; then
    (cd /var/www/html && npm ci --no-audit --no-fund)
fi

# 0. Build/composer.json
CURRENT_TYPO3_VERSION=""
[ -f "${TYPO3_VERSION_MARKER}" ] && CURRENT_TYPO3_VERSION=$(cat "${TYPO3_VERSION_MARKER}")

if [ ! -f "${COMPOSER_JSON}" ] || [ "${TYPO3_VERSION}" != "${CURRENT_TYPO3_VERSION}" ]; then
    if [ -n "${CURRENT_TYPO3_VERSION}" ]; then
        echo "[${PROJECT_NAME}] Switching TYPO3 from ${CURRENT_TYPO3_VERSION} to ${TYPO3_VERSION} ..."
        rm -rf vendor composer.lock config/system var/cache
        rm -rf public/typo3 public/_assets public/index.php 2>/dev/null || true
        mysql -h db -u root -proot -e "
            DROP DATABASE IF EXISTS \`db\`; CREATE DATABASE \`db\`;
            DROP DATABASE IF EXISTS \`test\`; CREATE DATABASE \`test\`;
        " >/dev/null
    else
        echo "[${PROJECT_NAME}] Generating Build/composer.json (TYPO3 ${TYPO3_VERSION}) ..."
    fi

    cat > "${COMPOSER_JSON}" <<EOF
{
    "name": "ochorocho/unity-tracker-dev",
    "description": "Development TYPO3 installation.",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "ochorocho/unity-tracker": "@dev",
        "typo3/cms-backend": "${TYPO3_VERSION}",
        "typo3/cms-belog": "${TYPO3_VERSION}",
        "typo3/cms-beuser": "${TYPO3_VERSION}",
        "typo3/cms-core": "${TYPO3_VERSION}",
        "typo3/cms-extbase": "${TYPO3_VERSION}",
        "typo3/cms-extensionmanager": "${TYPO3_VERSION}",
        "typo3/cms-filelist": "${TYPO3_VERSION}",
        "typo3/cms-fluid": "${TYPO3_VERSION}",
        "typo3/cms-frontend": "${TYPO3_VERSION}",
        "typo3/cms-install": "${TYPO3_VERSION}",
        "typo3/cms-lowlevel": "${TYPO3_VERSION}",
        "typo3/cms-scheduler": "${TYPO3_VERSION}",
        "typo3/cms-setup": "${TYPO3_VERSION}"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.64",
        "phpstan/phpstan": "^2.1",
        "typo3/coding-standards": "^0.8",
        "typo3/testing-framework": "^9.0 || ^10.0"
    },
    "autoload-dev": {
        "psr-4": { "Ochorocho\\\\UnityTracker\\\\Tests\\\\": "../Tests/" }
    },
    "repositories": [
        { "type": "path", "url": "../", "options": { "symlink": true } }
    ],
    "config": {
        "allow-plugins": {
            "typo3/cms-composer-installers": true,
            "typo3/class-alias-loader": true
        },
        "sort-packages": true,
        "vendor-dir": "vendor"
    },
    "extra": { "typo3/cms": { "web-dir": "public" } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
EOF
    echo "${TYPO3_VERSION}" > "${TYPO3_VERSION_MARKER}"
fi

# 1. Composer
if [ ! -f vendor/autoload.php ] || [ ! -x vendor/bin/typo3 ] || [ ! -x vendor/bin/phpunit ]; then
    echo "[${PROJECT_NAME}] Running composer update in Build/ ..."
    composer update --no-interaction --no-progress
fi

# 2. Databases
DB_SETUP_RAN_db=0
DB_SETUP_RAN_test=0

run_typo3_setup() {
    local db_name="$1"
    local project_label="$2"
    mysql -h db -u db -pdb -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`;"

    local table_count
    table_count=$(mysql -h db -u db -pdb "${db_name}" -sN -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${db_name}';" 2>/dev/null || echo 0)

    if [ "${table_count}" -eq 0 ]; then
        echo "[${PROJECT_NAME}] Running 'typo3 setup' against '${db_name}' ..."
        TYPO3_DB_DBNAME="${db_name}" vendor/bin/typo3 setup \
            --driver=mysqli --host=db --port=3306 --dbname="${db_name}" \
            --username=db --password=db \
            --admin-username="${ADMIN_USER}" --admin-user-password="${ADMIN_PASS}" \
            --admin-email="${ADMIN_EMAIL}" --project-name="${project_label}" \
            --server-type=other --force --no-interaction
        eval "DB_SETUP_RAN_${db_name}=1"
    else
        echo "[${PROJECT_NAME}] Applying pending schema changes to '${db_name}' ..."
        TYPO3_DB_NAME="${db_name}" vendor/bin/typo3 extension:setup --no-interaction >/dev/null || true
    fi
}

# settings.php gone but DB populated → wipe so setup recreates the install.
if [ ! -f "${SETTINGS_FILE}" ]; then
    table_count=$(mysql -h db -u db -pdb db -sN -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'db';" 2>/dev/null || echo 0)
    if [ "${table_count}" -ne 0 ]; then
        mysql -h db -u root -proot -e "DROP DATABASE IF EXISTS \`db\`; CREATE DATABASE \`db\`;" >/dev/null
    fi
fi

run_typo3_setup "db"   "${PROJECT_NAME}"
run_typo3_setup "test" "${PROJECT_NAME} (test)"

# The last setup call wins settings.php's dbname; the editor's default is `db`.
if [ -f "${SETTINGS_FILE}" ]; then
    sed -i -E "s/^([[:space:]]*'dbname' => )'[^']*',/\\1'db',/" "${SETTINGS_FILE}"
fi

# 3. Seeds for freshly created databases
if [ -f "${SEED_FILE}" ]; then
    for db_name in db test; do
        ran_var="DB_SETUP_RAN_${db_name}"
        if [ "${!ran_var}" -eq 1 ]; then
            echo "[${PROJECT_NAME}] Importing seed fixtures into '${db_name}' ..."
            mysql -h db -u db -pdb "${db_name}" < "${SEED_FILE}"
        fi
    done
fi

if [ -d "${SITES_FIXTURE_DIR}" ]; then
    mkdir -p "${BUILD_DIR}/config/sites"
    rm -rf "${BUILD_DIR}/config/sites/main"
    for site in "${SITES_FIXTURE_DIR}"/*/; do
        site_name=$(basename "${site}")
        rm -rf "${BUILD_DIR}/config/sites/${site_name}"
        cp -R "${site}" "${BUILD_DIR}/config/sites/${site_name}"
    done
fi

rm -rf "${BUILD_DIR}/var/cache/" || true

# 4. additional.php
cat > "${ADDITIONAL_FILE}" <<'PHP'
<?php
// Generated by .ddev/scripts/setup-typo3.sh — do not deploy.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';

foreach ([
    'host' => 'TYPO3_DB_HOST',
    'port' => 'TYPO3_DB_PORT',
    'dbname' => 'TYPO3_DB_DBNAME',
    'user' => 'TYPO3_DB_USERNAME',
    'password' => 'TYPO3_DB_PASSWORD',
    'driver' => 'TYPO3_DB_DRIVER',
] as $ssKey => $ssEnv) {
    $ssValue = getenv($ssEnv);
    if ($ssValue !== false && $ssValue !== '') {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'][$ssKey] = $ssKey === 'port' ? (int)$ssValue : $ssValue;
    }
}

// Playwright sends `X-Test-Run: 1` on every request → isolated `test` database, and a fake GitLab
// (https://gitlab.fake.test, answered inside TYPO3's Guzzle stack, no network).
if (($_SERVER['HTTP_X_TEST_RUN'] ?? '') === '1') {
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = 'test';
    require_once '/var/www/html/Tests/playwright/fake-gitlab/FakeGitLab.php';
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['unitytracker-fake-gitlab'] = \Ochorocho\UnityTracker\Tests\Playwright\FakeGitLab::middleware();
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['unity_tracker']['allowedHosts'] = \Ochorocho\UnityTracker\Tests\Playwright\FakeGitLab::HOST;
}

// CLI: `TYPO3_DB_NAME=test vendor/bin/typo3 …`
if (PHP_SAPI === 'cli' && getenv('TYPO3_DB_NAME')) {
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = getenv('TYPO3_DB_NAME');
}
PHP

echo "[${PROJECT_NAME}] TYPO3 is ready."
echo "[${PROJECT_NAME}] Backend:  ${SITE_BASE_URL}typo3   (${ADMIN_USER} / ${ADMIN_PASS})"
