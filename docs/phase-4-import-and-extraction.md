# Phase 4 — Import markazi, Excel va AI hujjat ajratish

Ushbu bosqich mahsulot, ta'minotchi va taklif (narx kuzatuvi) importlarini yagona
hayotiy sikl orqali boshqaradigan **Import markazi**ni joriy etdi. Eski mahsulot CSV
oqimi (`/catalog/product-imports`) o'z holicha saqlangan; yangi markaz
`/import-center` manzilida.

## Hayotiy sikl

`uploaded → validating → preview_ready → awaiting_confirmation → queued → processing
→ completed | completed_with_errors | failed | cancelled`

Har bir holat o'tishi `import_events` jurnaliga yoziladi (actor, from/to, metadata).
Biznes yozuvlari faqat foydalanuvchi **alohida tasdiqlagandan** keyin, tranzaksiya
ichida yaratiladi.

## Asosiy komponentlar

| Komponent | Fayl |
| --- | --- |
| Yagona import servisi (upload, lease, prepare, commit, retry, cancel) | `app/Services/ManageImports.php` |
| CSV/XLSX parser (zip-safety: formula, macro, external link, unsafe path, XML entity) | `app/Services/SpreadsheetReader.php` |
| Takroriy moslash (duplicate policy, immutable narx tarixi) | `app/Services/ImportReconciliation.php` |
| AI ajratish abstraksiyasi | `app/Services/DocumentExtractor.php`, `HttpDocumentExtractor.php` |
| AI chiqishi sxema validatsiyasi (confidence, region, usage) | `app/Services/ExtractionSchema.php` |
| Fayl skaner interfeysi (malware integration point) | `app/Services/FileScanner.php` |
| Background joblar | `app/Jobs/PrepareImport.php`, `CommitImport.php` |
| Saqlash (dry-run/execute) | `app/Console/Commands/PurgeImports.php` |
| Qayta tiklash | `app/Console/Commands/RecoverImports.php` |
| Migratsiyalar | `2026_10_30_122859_create_managed_import_tables`, `2026_10_30_124153_preserve_sqlite_exact_financial_values` |

## Idempotency va retry

- Upload `idempotency_key` (foydalanuvchi beradi yoki sha256(user|type|hash)) bilan
  takroriy fayl yuklashni bloklaydi.
- Worker `lease_token` + `lease_expires_at` (90s) fence orqali bir xil batchni ikki marta
  bajarmasligi kafolatlangan; narx yozuvlari retry'da qayta yaratilmaydi.
- Tranzient xatolar (`provider_transient`, `provider_unreachable`) backoff bilan
  `queued` holatga qaytadi; qolganlari `failed`.
- AI chaqiruvi hech qachon DB tranzaksiya ichidan bajarilmaydi (test bilan
  kafolatlangan).

## AI holati

`IMPORT_AI_PROVIDER=http` + model/endpoint/key bo'lsa live ajratish yoqiladi. Kredensial
yo'q bo'lsa suyunlik aniq `provider_unavailable` xatosi bilan `failed` bo'ladi — fake
javoblar ishlab chiqarishda taqiqlangan. AI chiqishi har doim `unverified`: narx
tasdiqlash alohida Phase 3 workflow'idan o'tadi.

## Xavfsizlik

- Fayllar `catalog` private diskda tasodifiy UUID nom bilan; faqat org a'zolari
  `/import-center/{id}/file` orqali ko'radi.
- MIME, kengaytma, hajm (5 MB), rasm o'lchami chegaralari.
- XLSX: zip bomb, unsafe path, formula, macro, external link, XML entity — barchasi rad etiladi.
- Error report CSV `safeCell` bilan formula injection'dan himoyalangan.
- Tenant izolyatsiya: barcha so'rovlar `organization_id` bilan cheklanadi va
  test bilan tekshiriladi.

## Testlar

- `ManagedImportTest`, `DocumentExtractionTest`, `SpreadsheetReaderTest`,
  `ProductImportTest` (CSV regresiya, XLSX, oversized, MIME, mapping, preview,
  duplicate, decimal, sana normalizatsiyasi, idempotency, retry, cancellation,
  AI unavailable, invalid output, confidence, approval, unauthorized access).
- SQLite (default) + PostgreSQL (CHECK constraintlar) suites.
- Chromium desktop + mobile Playwright testlari.

PostgreSQL uchun:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=smart_market_phase4_test DB_USERNAME=smart_market_test DB_PASSWORD=... \
SESSION_DRIVER=array CACHE_STORE=array MAIL_MAILER=array php artisan test --compact
```
