#!/usr/bin/env bash
# Nightly DB + uploads backup. Rotates after BACKUP_RETENTION_DAYS.
# Run via:  docker compose --profile tools run --rm backup
set -euo pipefail

WP="wp --allow-root --path=/var/www/html"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="/backups"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

mkdir -p "$OUT"

echo "==> Dumping database..."
$WP db export "$OUT/db-$TS.sql"
gzip -f "$OUT/db-$TS.sql"

echo "==> Archiving uploads..."
tar --warning=no-file-changed -czf "$OUT/uploads-$TS.tar.gz" -C /var/www/html wp-content/uploads || true

echo "==> Pruning archives older than $RETENTION_DAYS days..."
find "$OUT" -type f \( -name 'db-*.sql.gz' -o -name 'uploads-*.tar.gz' \) -mtime +"$RETENTION_DAYS" -delete

echo "==> Done. Backups in $OUT:"
ls -lh "$OUT" | tail -n +2
