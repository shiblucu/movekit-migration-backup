#!/usr/bin/env bash
# Builds movekit-migration-backup-<version>.zip, the file to upload to
# WordPress.org or install from Plugins > Add New > Upload Plugin.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' movekit-migration-backup.php | head -1)
out="movekit-migration-backup-${version}.zip"
tmp=$(mktemp -d)
mkdir "$tmp/movekit-migration-backup"
rsync -a --exclude-from=.distignore ./ "$tmp/movekit-migration-backup/"
rm -f "$out"
(cd "$tmp" && zip -qr "$OLDPWD/$out" movekit-migration-backup)
rm -rf "$tmp"
echo "Built $out"
