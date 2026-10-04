#!/usr/bin/env bash
# Regenerate languages/plogins-migrator.pot.
#
# make-pot skips every folder named vendor, and the bundled Reprint Server's
# admin screen lives in lib/vendor/reprint/reprint-server-wp with its strings in
# our text domain. So its strings are extracted in a second pass and merged.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

wp i18n make-pot . "${TMP}/main.pot" --slug=plogins-migrator --domain=plogins-migrator \
    --exclude=vendor,node_modules,tests,scripts,build --skip-audit >/dev/null
( cd lib/vendor/reprint/reprint-server-wp && wp i18n make-pot . "${TMP}/server.pot" \
    --domain=plogins-migrator --exclude=vendor --skip-audit --skip-plugins >/dev/null )
# References in the second pass are relative to the server folder.
perl -pi -e 's{(?<= )(?=[\w./-]+\.php:\d)}{lib/vendor/reprint/reprint-server-wp/}g if /^#: /' "${TMP}/server.pot"
msgcat --use-first "${TMP}/main.pot" "${TMP}/server.pot" -o languages/plogins-migrator.pot
echo "languages/plogins-migrator.pot: $(grep -c '^msgid ' languages/plogins-migrator.pot) entries"
