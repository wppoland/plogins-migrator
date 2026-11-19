#!/usr/bin/env bash
# Regenerate languages/plogins-migrator.pot.
#
# The bundled Reprint Server's admin screen (lib/reprint-server-wp) has
# its strings in our text domain. It must never live under a vendor folder:
# make-pot and translate.wordpress.org both skip those, which kept the Pull and
# Push screen English until 1.11.3. Its own Composer vendor/ holds no strings of ours.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"
wp i18n make-pot . languages/plogins-migrator.pot --slug=plogins-migrator --domain=plogins-migrator \
    --exclude=vendor,node_modules,tests,scripts,build --skip-audit >/dev/null
echo "languages/plogins-migrator.pot: $(grep -c '^msgid ' languages/plogins-migrator.pot) entries"
