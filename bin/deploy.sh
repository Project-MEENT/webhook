#!/usr/bin/env bash

set -o errexit -o nounset -o pipefail

# deploy.sh — deploy a tagged version to /opt/meent/webhook/vX.Y.Z/
# and atomically point /opt/meent/webhook/current to that version.
#
# Default usage:
#   bin/deploy-versioned.sh v0.4.0
#
# Configurable environment variables:
#   COMPOSER_BIN        Composer binary (default: first of composer/composer.phar)
#   PHP_BIN             PHP binary for composer (default: /usr/local/php/8.2.31/bin/php)

COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer-2.2}"
PHP_BIN="${PHP_BIN:-/usr/local/php/8.2.31/bin/php}"

log() {
    printf '==> %s\n' "$1"
}

deploy() {
    local version="${1:?Usage: $0 <version-tag>}"
    local target_dir="/opt/meent/webhook/${version}"
    local current_dir="/opt/meent/webhook/current"

    if [ ! -x "${PHP_BIN}" ]; then
        echo "[ERROR]: PHP binary not executable at ${PHP_BIN}" >&2
        exit 1
    elif [ ! -e "${COMPOSER_BIN}" ]; then
        echo "[ERROR]: Composer binary not found at ${COMPOSER_BIN}" >&2
        exit 1
    elif [ ! -f "/opt/meent/config.php" ]; then
        echo "[ERROR]: /opt/meent/config.php not found" >&2
        exit 1
    elif [ ! -f "/opt/meent/.htaccess" ]; then
        echo "[ERROR]: /opt/meent/.htaccess not found" >&2
        exit 1
    else
        log "Deploying ${version} to ${target_dir}"

        if [ -d "${target_dir}" ]; then
            log "Directory already exists; skipping clone"
        else
            git clone \
                --branch "${version}" \
                --depth=1 \
                'git@github.com:project-meent/webhook.git' "${target_dir}"
        fi

        if [ -d "${target_dir}/vendor" ]; then
            log "Vendor directory already exists; skipping composer install"
        else
            log "Installing dependencies with ${PHP_BIN} + ${COMPOSER_BIN}"
            "${PHP_BIN}" "${COMPOSER_BIN}" install \
                --no-dev \
                --no-interaction \
                --optimize-autoloader \
                --working-dir="${target_dir}"
        fi

        log "Copying config.php (from /opt/meent/config.php)"
        cp /opt/meent/config.php "${target_dir}/config.php"

        log "Copying web/.htaccess for PHP-FPM routing"
        cp /opt/meent/.htaccess "${target_dir}/web/.htaccess"

        log "Creating www -> web symlink"
        ln -sfn web "${target_dir}/www"

        log "Switching ${target_dir} to be current release"
        ln -sfn "${target_dir}" "${current_dir}"

        log "Normalizing ownership and modes on ${target_dir} (www-data:muze)"
        # @TODO: Doublecheck ownership and permissions
        sudo chown -R "www-data:muze" "${target_dir}"
        sudo chmod -R u=rwX,g=rwX,o=rX "${target_dir}"
        sudo find "${target_dir}" -type d -exec chmod g+s {} +
        sudo chown -h "www-data:muze" "${current_dir}" || true

        log "Deploy complete: ${version}"
    fi
}

if [[ "${BASH_SOURCE[0]}" != "${0}" ]]; then
    export -f deploy
else
    deploy "$@"
fi
