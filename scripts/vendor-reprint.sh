#!/usr/bin/env bash
# Rebuild lib/vendor/reprint from WordPress/reprint at a pinned tag plus our patch series.
#
# Why not Composer: the Packagist build of wp-php-toolkit/reprint-client 0.10.13
# fatals on its first line, it loads the MySQL parser from the
# sqlite-database-integration git submodule, which the Packagist archive does
# not contain. So we vendor from the git tag, submodule included.
#
# It sits under a folder named vendor because it is a third-party library kept
# as upstream wrote it, and Plugin Check skips vendor folders for that reason.
#
# Layout under lib/vendor/reprint mirrors the upstream repo root, because the client
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
DEST="${ROOT}/lib/vendor/reprint"
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

# Development files have no place in a plugin package (wp.org rejects
# phpunit.xml as "application files").
find "${DEST}" \( -name 'phpunit.xml*' -o -name 'phpstan*.neon*' -o -name 'phpcs.xml*' -o -name '.editorconfig' \) -delete

# The server's admin screen ships under Migrator, so its strings belong to
# Migrator's text domain or they can never be translated.
find "${DEST}/reprint-server-wp" -name '*.php' -not -path "${DEST}/reprint-server-wp/vendor/*" -print0 \
    | xargs -0 perl -0pi -e "s/(\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n|esc_html_x|esc_attr_x)\(\s*(?:'(?:[^'\\\\]|\\\\.)*'\s*,\s*)+)'reprint'(\s*\))/\\1'plogins-migrator'\\2/g"

# The credentials screen and the endpoint's messages are part of Migrator's
# Pull and Push feature, so they speak about Migrator, not the library.
perl -pi -e '
    s/the remote Reprint API URL/the address of this site/g;
    s/Remote Reprint API URL/Address for the pulling site/g;
    s/"reprint keygen" or by "reprint pull"/"wp migrator remote keygen" or by "wp migrator pull"/g;
    s/Tools > Reprint Server/Migrator > Pull and Push/g;
    s/Reprint Server API (error|exception): /Pull and Push API $1: /g;
    s/Run composer install in (the plugin directory|reprint-server-wp) or (reinstall|rebuild) the release package\./Reinstall Migrator./g;
    s/home URL followed by \?reprint-api/home URL/g;
    s/home_url\(\x27\?reprint-api\x27\)/home_url(\x27?migrator-api\x27)/g;
    s/Reprint Server runtime/Pull and Push runtime/g;
    s/manage Reprint Server/manage Pull and Push access/g;
    s/Reprint Server/Pull and Push access/g;
    s/Standalone Reprint (requires|configuration|on a host)/The standalone transfer route $1/g;
    s/Update the Reprint client to a version that has `reprint keygen`, run it,/Update Migrator on the pulling site, run `wp migrator remote keygen`,/g;
    s/Reprint client/Migrator/g;
' "${DEST}/reprint-server-wp/lib.php" "${DEST}/reprint-server-wp/standalone.php" "${DEST}"/reprint-server-wp/wordpress/*.php

# Migrator serves the endpoint at ?migrator-api. The client's pull pipeline
# appends its own query name to any address without one, which then no longer
# matches the address the lower-level commands saved.
python3 - "${DEST}/packages/reprint-client/src/lib/pull/class-pull.php" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding="utf-8").read()
a = "!array_key_exists('reprint-api', $query_parameters)\n"
b = "$url . $separator . 'reprint-api' . $fragment"
if a not in s or b not in s:
    sys.exit("migrator-api endpoint patch did not apply; check class-pull.php")
s = s.replace(a, a + "            && !array_key_exists('migrator-api', $query_parameters)\n", 1)
s = s.replace(b, "$url . $separator . 'migrator-api' . $fragment", 1)
open(p, "w", encoding="utf-8").write(s)
PY

# While a push commits, the source writes a .maintenance file that lets only
# push requests through. It recognised the library's own query names and read
# the endpoint from the query string alone, but the client sends commit
# requests with the endpoint in the POST body, and Migrator serves the API at
# ?migrator-api. A commit that failed once then locked the source out of the
# very requests that finish it: every page 503, the push unable to resume.
python3 - "${DEST}/reprint-server-wp/vendor/wp-php-toolkit/reprint-server/src/class-push-session.php" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding="utf-8").read()
old = (
    '. "\\$reprint_push_request = (isset(\\$_GET[\'reprint-api\']) || isset(\\$_GET[\'site-export-api\']))\\n"\n'
    '                . "    && isset(\\$_GET[\'endpoint\']) && is_string(\\$_GET[\'endpoint\'])\\n"\n'
    '                . "    && strpos(\\$_GET[\'endpoint\'], \'push_\') === 0;\\n"\n'
)
new = (
    '. "\\$reprint_push_endpoint = \\$_GET[\'endpoint\'] ?? \\$_POST[\'endpoint\'] ?? null;\\n"\n'
    '                . "\\$reprint_push_request = (isset(\\$_GET[\'reprint-api\']) || isset(\\$_GET[\'migrator-api\']) || isset(\\$_GET[\'site-export-api\']))\\n"\n'
    '                . "    && is_string(\\$reprint_push_endpoint)\\n"\n'
    '                . "    && strpos(\\$reprint_push_endpoint, \'push_\') === 0;\\n"\n'
)
if old not in s:
    sys.exit("maintenance pass-through patch did not apply; check class-push-session.php")
s = s.replace(old, new, 1)
s = s.replace('. "unset(\\$reprint_push_request);\\n"', '. "unset(\\$reprint_push_request, \\$reprint_push_endpoint);\\n"', 1)
open(p, "w", encoding="utf-8").write(s)
PY

# The client's terminal messages name the library and its own CLI; inside
# Migrator they point at Migrator's screen and wp migrator commands instead.
find "${DEST}/packages/reprint-client/src" "${DEST}/packages/reprint-client/bin" -type f \( -name '*.php' -o -name 'reprint-client' \) -print0 \
    | xargs -0 perl -pi -e '
        s/Tools (?:>|\x{2192}|\xe2\x86\x92) Reprint Server/Migrator > Pull and Push/g;
        s/Run `php reprint\.phar install-server` for setup instructions\./Switch on Migrator > Pull and Push on the source site./g;
        s/php reprint\.phar /wp migrator remote /g;
        s/`reprint (pull|push)`/`wp migrator $1`/g;
        s/`reprint ([a-z-]+)/`wp migrator remote $1/g;
        s/"reprint (keygen|pull)"/"wp migrator remote $1"/g;
        s/Usage: reprint /Usage: wp migrator remote /g;
        s/(["\x27(])reprint ([a-z]+(?:-[a-z]+)*)\b/$1wp migrator remote $2/g;
        s/<remote-reprint-api-url>/<site-url>/g;
        s/^(\s*["\x27]?\s*)reprint (pull|push|keygen|preflight|files-[a-z]+|db-[a-z-]+|pull-[a-z]+|flat-docroot|merge-wp-content|apply-runtime|post-process|recover|install-server) /$1wp migrator remote $2 /g;
        s/remote Reprint API URL/remote site address/g;
        s/Reprint API URL/site address/g;
        s/(?:The )?Reprint Server plugin/Pull and Push on the remote site/g;
        s/remote Reprint Server/Migrator on the remote site/g;
        s/Reprint Server/Pull and Push/g;
        s/Reprint client/Migrator/g;
        s/Reprint version/Migrator version/g;
        s/\bReprint (does|cannot|can|will|could|needs|uses|saves|stores|keeps|found|expects|requires|reads)\b/Migrator $1/g;
        s/the Reprint state directory/the transfer state directory/g;
        s/(the|held|Another) Reprint (process|import)/$1 transfer $2/g;
        s/stopped Reprint\./stopped the transfer./g;
        s/Uses Reprint/Uses Migrator/g;
        s/as Reprint\./as Migrator./g;
        s/previous Reprint build/previous Migrator version/g;
        s/Reprint internal table/transfer internal table/g;
        s/Reprint config/remote config/g;
        s/its Reprint plugin path/its Pull and Push path/g;
        s/maximum Reprint waits/maximum Migrator waits/g;
        s/source Reprint plugin/source Pull and Push files/g;
        s/run any reprint command against this site/run the same wp migrator command again/g;
        s/"Reprint\/1\.0"/"Migrator\/1.5"/g;
    '

# db-apply takes the target password only as --target-pass, which every user on
# the server can read in the process list (upstream issue #24). Let it fall back
# to MYSQL_PASSWORD, as db-pull already does.
perl -0pi -e 's/\$options\["target_pass"\] \?\? null,/\$options["target_pass"] ?? (false !== getenv("MYSQL_PASSWORD") ? getenv("MYSQL_PASSWORD") : null),/' \
    "${DEST}/packages/reprint-client/src/import.php"
grep -q 'getenv("MYSQL_PASSWORD") ? getenv' "${DEST}/packages/reprint-client/src/import.php" \
    || { echo "MYSQL_PASSWORD fallback did not apply; check import.php" >&2; exit 1; }

# Upstream ships its own plugin header in reprint-server-wp/index.php. Inside
# Migrator that file is included, never activated, so the header only confuses
# WordPress's plugin scanner when someone unzips us one level too deep.
perl -0pi -e 's/^ \* (Plugin Name|Plugin URI|Version|Requires PHP|Author|License|License URI|Description):[^\n]*\n//mg' \
    "${DEST}/reprint-server-wp/index.php"

{
    echo "WordPress/reprint ${REPRINT_TAG} ($(git rev-parse --short HEAD) after patches)"
    [ -f "${PATCHES}/SERIES" ] && sed 's/^/  patch: /' "${PATCHES}/SERIES"
} > "${DEST}/VERSION"

echo "lib/vendor/reprint rebuilt from ${REPRINT_TAG}: $(du -sh "${DEST}" | cut -f1)"
