#!/usr/bin/env bash
# Rebuild lib/reprint from WordPress/reprint at a pinned tag plus our patch series.
#
# Why not Composer: the Packagist build of wp-php-toolkit/reprint-client 0.10.13
# fatals on its first line, it loads the MySQL parser from the
# sqlite-database-integration git submodule, which the Packagist archive does
# not contain. So we vendor from the git tag, submodule included.
#
# Layout under lib/reprint mirrors the upstream repo root, because the client
# finds the parser by walking up from its own path:
#   reprint-server-wp/                 source role (runs inside WordPress)
#   packages/reprint-client/           target role (runs as a separate PHP process)
#   lib/sqlite-database-integration/packages/mysql-on-sqlite/
#
# Patches: scripts/reprint-patches/SERIES lists *.patch files (git format-patch,
# one per upstream PR) applied in order with git am. Drop a patch once upstream
# releases it and REPRINT_TAG moves past it.
#
#   scripts/vendor-reprint.sh            # clone into a temp dir
#   REPRINT_SRC=/path/to/clone scripts/vendor-reprint.sh
set -euo pipefail

REPRINT_TAG="${REPRINT_TAG:-v0.10.13}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PATCHES="${ROOT}/scripts/reprint-patches"
DEST="${ROOT}/lib/reprint"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

if [ -n "${REPRINT_SRC:-}" ]; then
    git clone -q "${REPRINT_SRC}" "${WORK}/src"
else
    git clone -q https://github.com/WordPress/reprint.git "${WORK}/src"
fi
cd "${WORK}/src"
git -c advice.detachedHead=false checkout -q "${REPRINT_TAG}"
git submodule update -q --init --depth 1 lib/sqlite-database-integration

if [ -f "${PATCHES}/SERIES" ]; then
    git config user.email vendor@localhost
    git config user.name vendor
    while read -r p; do
        [ -z "${p}" ] && continue
        git am -q "${PATCHES}/${p}"
    done < "${PATCHES}/SERIES"
fi

# Runtime dependencies only. The client package requires reprint-server through
# a path repository in the monorepo, so install from inside the checkout.
( cd reprint-server-wp && composer install -q --no-dev --no-interaction --optimize-autoloader )
( cd packages/reprint-client && composer install -q --no-dev --no-interaction --optimize-autoloader )

rm -rf "${DEST}"
mkdir -p "${DEST}/lib/sqlite-database-integration/packages" "${DEST}/packages"
rsync -a --copy-links --exclude tests --exclude '.git*' --exclude 'composer.lock' \
    reprint-server-wp/ "${DEST}/reprint-server-wp/"
rsync -a --copy-links --exclude tests --exclude '.git*' --exclude 'composer.lock' \
    packages/reprint-client/ "${DEST}/packages/reprint-client/"
rsync -a --exclude tests \
    lib/sqlite-database-integration/packages/mysql-on-sqlite/ \
    "${DEST}/lib/sqlite-database-integration/packages/mysql-on-sqlite/"
cp LICENSE "${DEST}/LICENSE"

# Upstream ships its own plugin header in reprint-server-wp/index.php. Inside
# Migrator that file is included, never activated, so the header only confuses
# WordPress's plugin scanner when someone unzips us one level too deep.
perl -0pi -e 's/^ \* (Plugin Name|Plugin URI|Version|Requires PHP|Author|License|License URI|Description):[^\n]*\n//mg' \
    "${DEST}/reprint-server-wp/index.php"

{
    echo "WordPress/reprint ${REPRINT_TAG} ($(git rev-parse --short HEAD) after patches)"
    [ -f "${PATCHES}/SERIES" ] && sed 's/^/  patch: /' "${PATCHES}/SERIES"
} > "${DEST}/VERSION"

echo "lib/reprint rebuilt from ${REPRINT_TAG}: $(du -sh "${DEST}" | cut -f1)"
