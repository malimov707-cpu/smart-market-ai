"""Izolyatsiyalangan SQLite backup/restore sinovi; original faqat mode=ro bilan ochiladi."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import time


def fingerprint(connection):
    schema = connection.execute("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name").fetchall()
    tables = connection.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").fetchall()
    counts = {}
    for (name,) in tables:
        quoted = '"' + name.replace('"', '""') + '"'
        counts[name] = connection.execute('SELECT count(*) FROM ' + quoted).fetchone()[0]
    return hashlib.sha256(json.dumps([schema, counts], sort_keys=True).encode()).hexdigest(), len(tables)


def writable_new(path):
    descriptor = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    os.close(descriptor)
    return sqlite3.connect(path)


def verify(source):
    started = time.monotonic()
    with tempfile.TemporaryDirectory(prefix='smart-market-restore-') as directory:
        backup_path = Path(directory) / 'snapshot.sqlite'
        restore_path = Path(directory) / 'restored.sqlite'
        with sqlite3.connect(source.resolve().as_uri() + '?mode=ro', uri=True) as original:
            with writable_new(backup_path) as snapshot:
                original.backup(snapshot)
                baseline, tables = fingerprint(snapshot)
        with sqlite3.connect(backup_path.as_uri() + '?mode=ro', uri=True) as snapshot:
            with writable_new(restore_path) as restored:
                snapshot.backup(restored)
                assert fingerprint(restored)[0] == baseline, 'Restored schema/count fingerprint mismatch'
                assert restored.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'Integrity check failed'
                assert restored.execute('PRAGMA foreign_key_check').fetchall() == [], 'Foreign-key check failed'
        return {'database': 'SQLite local snapshot', 'source_access': 'read_only', 'tables_verified': tables,
                'schema_and_counts_equal': True, 'integrity_check': 'ok', 'foreign_key_check': 'ok',
                'elapsed_ms': round((time.monotonic() - started) * 1000, 3),
                'backup_bytes': backup_path.stat().st_size, 'encryption_verified': False,
                'production_postgresql_restore_verified': False, 'temporary_copies_removed_on_exit': True}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=Path, required=True)
    arguments = parser.parse_args()
    print(json.dumps(verify(arguments.source), indent=2))
