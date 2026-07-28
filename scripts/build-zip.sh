#!/usr/bin/env bash
# Build a clean, installable plogins-migrator.zip, honouring .distignore.
# vendor/ is dev-only (runtime uses the bundled autoload.php + lib/), so no
# composer install is needed here. Produces ${OUT}/plogins-migrator and the zip.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-/tmp/plogins-migrator-build}"
STAGE="${OUT_DIR}/plogins-migrator"

rm -rf "${OUT_DIR}"
mkdir -p "${STAGE}"

rsync -a --exclude-from="${ROOT_DIR}/.distignore" \
    --exclude '.git' --exclude 'node_modules' \
    --exclude '.DS_Store' \
    "${ROOT_DIR}/" "${STAGE}/"

find "${STAGE}" -name '.DS_Store' -delete

( cd "${OUT_DIR}" && zip -rqX /tmp/plogins-migrator.zip plogins-migrator -x '*.DS_Store' )
echo "Built /tmp/plogins-migrator.zip from ${STAGE}"
