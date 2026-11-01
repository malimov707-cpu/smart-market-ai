#!/usr/bin/env bash
# SHABLON: productionda ishga tushirish uchun alohida ruxsat kerak.
# DB libpq service + 0600 pgpass; secrets argumentlarda yoki loglarda emas.
set -euo pipefail
umask 077
: "${PGSERVICE:?Dedicated read-only libpq service required}"
: "${PGSERVICEFILE:?External service configuration required}"
: "${PGPASSFILE:?External 0600 credentials required}"
: "${BACKUP_DIRECTORY:?Private pre-provisioned directory required}"
: "${BACKUP_RECIPIENTS_FILE:?age public recipients file required}"
[[ "$PGSERVICE" =~ ^[a-zA-Z0-9_-]+$ ]]
for tool in pg_dump age; do command -v "$tool" >/dev/null; done
[[ -d "$BACKUP_DIRECTORY" && -f "$BACKUP_RECIPIENTS_FILE" && -f "$PGSERVICEFILE" && -f "$PGPASSFILE" ]]
[[ "${PGSSLMODE:-}" == 'verify-full' ]]
# Partial/output directory faqat shu run uchun; oldingi nusxalar overwrite qilinmaydi.
backup_run=$(mktemp -d "$BACKUP_DIRECTORY/run-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
pg_dump --dbname="service=$PGSERVICE sslmode=verify-full" --format=custom --no-owner --no-acl --no-password |
    age --recipients-file "$BACKUP_RECIPIENTS_FILE" --output "$backup_run/database.dump.age.partial"
mv "$backup_run/database.dump.age.partial" "$backup_run/database.dump.age"
# File existence SMTP/live/restore verification emas.
printf '%s\n' 'Encrypted database backup completed; offsite upload and restore verification still required.'
# Retention deletion avtomatik bajarilmaydi. Object-store lifecycle operator review bilan.
