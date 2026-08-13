#!/bin/sh
set -e

if [ -z "$1" ]; then
    echo "Usage: ./restore.sh <path_to_backup.sql.gz>"
    exit 1
fi

RESTORE_FILE="$1"

if [ ! -f "$RESTORE_FILE" ]; then
    echo "❌ Backup file not found: $RESTORE_FILE"
    exit 1
fi

echo "⚠️ Restoring PostgreSQL database from $RESTORE_FILE..."

gunzip -c "$RESTORE_FILE" | PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}"

echo "✅ Database restore completed successfully!"
