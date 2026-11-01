# Testlash

## Joriy operations continuation — 2026-10-31

```sh
php artisan test --compact tests/Feature/QueueWorkerHeartbeatTest.php tests/Feature/OperationsPreparationTest.php
```

**25 passed / 56 assertions / 2.575 s**. Bu ikki fayl DB refresh/migration traitini
ishlatmaydi. Haqiqiy idle worker testi faqat yangi random private temp katalogdagi
SQLite fixture `jobs` jadvalini yaratadi; application DBga ulanmaydi. Subprocess
--max-time=2, timeout=10 s; config cache/storage/mail/key test uchun ajratilgan.
Private heartbeatni boshqa process o‘qishi va 0 queued job tekshirildi. Temp fayllar
tozalanadi. Stale/corrupt/future/mismatched/oversized/disabled/IO/logging holatlari
ham qamralgan. Bu PostgreSQL, supervision reboot yoki staging dalili emas.

Joriy `migrate` taqiqi sabab quyidagi historical full-suite/browser setup buyruqlari
qayta bajarilmadi. Ular schema/migration ishlatadi; ushbu sprintda ishga tushirmang.

Bazaviy sessiyada 49 test / 533 assertion qayta bajarilib o‘tdi. Ularning hech biri
o‘chirilmadi. Umumiy fixture yordamchilari `tests/Pest.php` ga ko‘chirildi.
PostgreSQL expiry testi noqonuniy fixture o‘rniga observationdan keyingi, ayni
hisoblash paytida tugaydigan taklif bilan tuzatildi; test maqsadi saqlandi.

## Buyruqlar

```sh
php artisan test --compact
npm run typecheck
npm run build
vendor/bin/pint --test
composer validate --strict
composer audit
npm audit --audit-level=high
```

SQLite suite in-memory ishlaydi; PostgreSQL CHECK testlari unda skip qilinadi.
Haqiqiy PostgreSQL uchun alohida bo‘sh TEST bazasi va process env bering. Hech
qachon DB_DATABASE sifatida mavjud development/production bazasini bermang:
Laravel test traitlari test schema’ni yangilaydi.

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=smart_market_identity_test DB_USERNAME=TEST_USER DB_PASSWORD=TEST_PASSWORD \
SESSION_DRIVER=array CACHE_STORE=array MAIL_MAILER=array php artisan test --compact
```

Ushbu sessiyada PostgreSQL 17 vaqtinchalik Docker konteynerida tekshirildi:
loopbackdagi tasodifiy port, faqat test credentials, tmpfs storage; production
ma’lumotlari ko‘chirilmadi. Docker production talabi sifatida kiritilmadi.

## Brauzer

Yangi `@playwright/test` dev dependency foydalanuvchi so‘ragan haqiqiy browser E2E
uchun zarur. Production runtimega kirmaydi. Chromium alohida o‘rnatiladi:

```sh
npx playwright install chromium
npm run build
npm run test:browser
```

Config OS temp katalogida alohida SQLite fayli yaratadi. Fixture seeder faqat
testing muhitida shu katalogdagi bazada ishlaydi. `.env` o‘zgarmaydi; server array
mailer bilan tashqi email yubormaydi. Yakunda temp baza va server tozalanadi.
Desktop Chrome va Pixel 7 mobil viewportda ikki oqim tekshiriladi:
login → product → supplier → offer → exact comparison → invitation → sessions;
registration → email verification talabi. Bu butun mahsulot E2Esi emas.

Failure screenshot/trace `test-results/` da saqlanadi va Gitdan chiqarilgan.
Ular avtomatik test ma’lumotlari uchun; real hisoblar bilan trace saqlamang.

## Qamrov va cheklovlar

Email signing/expiry, current password, stale session, deaktivatsiya, oxirgi Owner,
5 rol matritsasi, tenant isolation, invitation mismatch/expiry/revocation/replay,
mail xatosi, CSV atomicity/dedupe va deterministic hisoblar tekshiriladi.
Real SMTP, concurrency stress, pentest, barcha sahifalar accessibility auditi,
load va restore sinovlari hali bajarilmagan. Yakuniy raqamlar readiness hisobotida.

`@types/node` dev dependency browser/config TypeScript fayllarida Node APIlarini
typecheck qilish uchun qo‘shildi; production runtime dependency emas.

## 2-bosqich tekshiruvlari

CatalogManagementTest: CRUD/restore, metadata/aliases/variant, unit history lock,
SKU/barcode, role/tenant, search/filter/sort, 502-row remote lookup, Inertia return
URL va flash regressiyalari. CatalogFilesTest: MIME/size, private authorized access,
foreign download/replace/retire denial, replacement/retirement va image request
session regressiyasi. SupplierTrustTest: independent approve/reject/suspend,
platform permission, evidence retention, review eligibility/dedupe, dispute moderation.
ProductImportTest: preview/mapping/row errors/atomic confirmation/idempotency/history,
formula export, foreign batches, expiry, metadata va preview query budget.
PhaseTwoUpgradeTest eski product/supplier/offerlarni saqlashni tekshiradi.
PostgresConstraintsTest yangi package/owner/rating/self-parent CHECKlarni ham qamraydi.

`tests/Browser/phase-two.spec.ts` desktop/mobil uchun image/metadata/variant/archive,
mustaqil supplier verification, CSV mapping/export va review/dispute oqimlarini qo‘shadi.
Fixturelarda Owner va Manager bor; PNG/PDF va barcha mahsulotlar AUTOMATED TEST
ma’lumotidir. Browser fayllari ham temp CATALOG_STORAGE_PATH ichida; lokal biznes
storage’ga yozilmaydi. Inertia link’dan keyin sahifa almashishi aniq kutiladi.

Yangi runtime kutubxona qo‘shilmadi; mavjud GD extension ext-gd sifatida deklaratsiya
qilindi, chunki image decode/re-encode unga tayanadi. Composer versiyalari asossiz
almashtirilmadi. Yakuniy real sonlar readiness va Phase 2 hujjatida.

TestDatabaseGuardTest child process’da file-backed DBni rad etish va fayl hashining
o‘zgarmasligini tekshiradi. Backend test guard uncached APP_ENV=testing talab qiladi;
SQLite :memory: yoki _test belgisi bor PostgreSQL DBdan tashqari test boshlanmaydi.
Browser APP_CONFIG_CACHE/APP_ROUTES_CACHE/APP_EVENTS_CACHE ham temp yo‘lda:
mavjud application cache’i testni haqiqiy bazaga yo‘naltirmaydi. Production serverda
testlarni ishga tushirmang; test nomi bo‘lgan DB ham alohida bo‘sh test DB bo‘lsin.

## 3-bosqich tekshiruvlari

PriceIntelligenceTest: latest expensive vs old cheap, tie-break, 3000-row history,
501-supplier fail-closed cap, as-of/future/UTC-offset/subsecond correction, source
metadata va dedupe, independent price review/evidence/dispute/platform permission,
FX history/missing/expiry/precision/rounding, landed/nominal/unit ranking, previous
valid price va same-cohort changes, 5 rol va direct HTTP tenant/guest tekshiruvlari.
PhaseThreeUpgradeTest eski narx/reference/time va FKlardan ma’lumot yo‘qolmasligini
alohida in-memory connectionda tekshiradi.

PriceQueryPerformanceTest faqat real PostgreSQL’da 20 000 observation / 20 supplier
uchun EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON), query count/time va memory delta oladi.
Artifact: storage/framework/testing/phase3-performance.json (test-only records).
Test default application DBga yozmaydi; oldingi TestCase guard saqlangan. Bu
microbenchmark, concurrency/load/p95 emas. SQLite bu PG-only testni skip qiladi.

Browser phase-three.spec.ts desktop/mobileda eski/yangi narx, historical cutoff,
trend, source details, independent review va kurs self-approval denial/independent
approvalni tekshiradi. Existing 10 test saqlangan. Browser fixturega faqat isolated
TEST bazasida qo‘shimcha reviewer Owner beriladi; production default user yaratilmaydi.
CI workflow GD dependency’ni ham ochiq ko‘rsatadi. Real remote CI bajarilmagan.

Yakuniy shu-sessiya sonlari phase-3-price-intelligence.md va readiness hisobotida.
## Import markazi testlari (4-bosqich)

`ManagedImportTest`, `DocumentExtractionTest`, `SpreadsheetReaderTest`,
`ProductImportTest`: CSV regresiya, XLSX (formula/macro/path/entity rad etilishi),
oversized fayl, noto'g'ri MIME, column mapping, preview, duplicate siyosatlari,
parent/variant, decimal aniqligi (SQLite exact-text ustunlari bilan), sana
normalizatsiyasi, satr darajasidagi validatsiya, idempotency (upload va worker
lease), retry/backoff, failed recovery, cancellation, AI unavailable, invalid AI
chiqishi, confidence, inson approval, unauthorized evidence, tenant izolyatsiya,
audit tarixi. SQLite suit (default) + PostgreSQL suit (yuqoridagi buyruq) 195/195.
Chromium desktop + mobile 12/12.

## Corrective sprint — test integrity va real worker

DocumentExtractionTest transaction darajasini HTTP callbackda faqat o‘lchaydi;
assertion callbackdan tashqarida, LazyRefreshDatabase tashqi transaction baseline’iga
solishtiriladi. Application catch failure’ni yashira olmaydi. DebugExtractionTest
endi actual invalid JSON rejection va provider request countni tekshiradi; true-only
assertion olib tashlandi. Meaningful avvalgi testlar o‘chirilmagan.

QualityAnalyticsTest: supplier-aware ordering/cohort/eligible min-max, invalid newest,
as-of correction/recording, zero legacy baseline va 2001-observation query/memory.
AlertDeliveryTest: personal subscribers, opt-out, revoked/deactivated, SMTP failure,
log unavailable, per-recipient acceptance va cross-tenant dedupe. QualitySecurityTest:
direct import/file/error/report/notification/rule IDOR, auditor permissions.

RealImportQueueTest default suite’da skip: unga alohida yangi PostgreSQL
`quality_queue_test` bazasi, wrapping transactionsiz muhit kerak. Test `migrate`
bajaradi, mavjud biznes bazasini reset qilmaydi. Process env bilan guardlangan baza
nomi, PDO konfiguratsiyasi va temp private storage qo‘llanadi. Haqiqiy queue worker
subprocess preparation/failed/manual retry/reparse/confirm/commit/dedupeni tekshiradi.
`handle()` bevosita chaqirilmaydi. Full suite PostgreSQL CHECKlarni, SQLite esa
mos kelmaydigan PG-only testlarni skip qiladi. Zero-price legacy testi faqat
SQLite’da: PG CHECK allaqachon zero price’ni taqiqlaydi.

Playwright setup isolated SQLite queue workerini ham ishga tushiradi; teardown
faqat saqlangan o‘z PIDini tugatadi. quality.spec desktop/mobile import CSV/XLSX,
mapping/error/correction/human confirmation, AI unavailable/manual review,
notification read/preferences hamda analytics/export/date/permissionni tekshiradi.
Asynchronous sheet response/poll yakunini kutadi; backend revision guard saqlangan.
Yakuniy sonlar critical-quality-sprint.md/readiness hisobotida.
