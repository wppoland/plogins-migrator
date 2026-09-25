#!/usr/bin/env bash
# Build a clean, installable plogins-migrator.zip, honouring .distignore.
# vendor/ is dev-only (runtime uses the bundled autoload.php + lib/), so no
# composer install is needed here. Produces ${OUT}/plogins-migrator and the zip.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-/tmp/plogins-migrator-build}"
STAGE="${OUT_DIR}/plogins-migrator"

# The header, the VERSION constant and the readme's Stable tag are three copies
# of one number. 1.2.15 shipped with the constant still on 1.2.13, and since the
# constant is what gets written into every archive manifest, the backups claimed
# to come from a plugin two releases old. Cheaper to refuse the build.
hdr=$(grep -m1 -E '^ \* Version:' "${ROOT_DIR}/plogins-migrator.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
cst=$(grep -m1 -E "^const VERSION" "${ROOT_DIR}/plogins-migrator.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
tag=$(grep -m1 -E '^Stable tag:' "${ROOT_DIR}/readme.txt" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
if [ "${hdr}" != "${cst}" ] || [ "${hdr}" != "${tag}" ]; then
    echo "version mismatch: header=${hdr} const=${cst} stable-tag=${tag}" >&2
    exit 1
fi

rm -rf "${OUT_DIR}"
mkdir -p "${STAGE}"

rsync -a --exclude-from="${ROOT_DIR}/.distignore" \
    --exclude '.git' --exclude 'node_modules' \
    --exclude '.DS_Store' \
    "${ROOT_DIR}/" "${STAGE}/"

find "${STAGE}" -name '.DS_Store' -delete

# zip -r adds to an existing archive, so a stale one keeps files the build no longer ships.
rm -f /tmp/plogins-migrator.zip
( cd "${OUT_DIR}" && zip -rqX /tmp/plogins-migrator.zip plogins-migrator -x '*.DS_Store' )
echo "Built /tmp/plogins-migrator.zip from ${STAGE}"
