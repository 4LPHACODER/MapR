#!/bin/sh
set -eu
# Read APP_ENV from the selected file, rather than masking it in Compose.
if [ -n "${MAPR_EXPECTED_ENV:-}" ] && [ "${APP_ENV:-}" != "$MAPR_EXPECTED_ENV" ]; then
    echo 'APP_ENV does not match this Compose stack; check MAPR_ENV_FILE.' >&2
    exit 1
fi
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts bootstrap/cache
# Initialize named volumes; never migrate, seed, generate keys, or cache env here.
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data storage bootstrap/cache
    if command -v composer >/dev/null 2>&1; then
        mkdir -p vendor
        chown -R www-data:www-data vendor
    fi
fi
if [ ! -L public/storage ] && [ -e public/storage ]; then
    echo 'public/storage must be a symlink; move the existing file or directory aside before starting.' >&2
    exit 1
fi
if [ "$(readlink public/storage || true)" != ../storage/app/public ]; then
    # A relative target works on the host and in app/web containers. Rename
    # atomically so simultaneous app/queue startup never exposes a missing link.
    link_dir=$(mktemp -d public/.storage-link.XXXXXX)
    trap 'rm -f "$link_dir/link"; rmdir "$link_dir"' EXIT
    ln -s ../storage/app/public "$link_dir/link"
    mv -Tf "$link_dir/link" public/storage
    rmdir "$link_dir"
    trap - EXIT
fi
if [ "${1:-}" = php-fpm ]; then
    exec "$@"
fi
if [ "$(id -u)" = 0 ]; then
    exec gosu www-data "$@"
fi
exec "$@"
