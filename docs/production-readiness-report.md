# Production tayyorlik hisoboti

Operations davomi: opt-in private worker heartbeat implement qilindi. Targeted
ops/heartbeat tekshiruvi **25 passed / 56 assertions / 2.575 s**; haqiqiy idle worker
faqat yangi vaqtinchalik test bazasi/subprocessda, migratsiyasiz sinaldi. Local
monitoring flag o‘chiq, worker unknown. Production tasnifi **NOT READY**; staging
server/SMTP/TLS/monitoring/backup/rollback tashqi gate’lari yopilmadi.

2026-11-02 corrective follow-up: Laravel suite **312 total / 298 passed / 14 skipped /
1982 assertions / 12.068 s**; desktop/mobile Playwright **28/28 passed / 2.4 min**;
TypeScript checks, production frontend build, Pint va `git diff --check` passed.
Bu lokal regression dalili; production credentials, staging va release gate’larini
tasdiqlamaydi.

2026-10-31 operations yangilanishi: **NOT READY** (production deployment uchun).
Lokal login HTTP 200; read-only probe DB/storage reachable, 11 migration/0 pending
schema. Operations testlari 11 passed / 30 assertions. Worker unknown, SMTP/AI live,
staging TLS, monitoring, encrypted PostgreSQL restore va rollback rehearsal ochiq.
Migration/deploy bajarilmadi. [Joriy dalillar](production-operations-sprint.md).
Quyidagi full-suite/browser natijalari oldingi corrective sprintga tegishli.

Baholash: 2026-11-02, Asia/Tashkent. **Lokal sinov uchun ishlaydi; production release
candidate emas.** 7–10-bosqichlar ushbu corrective sprintda boshlanmadi.

## Amaldagi holat

| Holat | Dalil va chegara |
| --- | --- |
| Implement qilingan | Identity/organization, katalog/private files, Phase 3 narx intelligence, managed CSV/XLSX import, extraction abstraction/human review, personal alerts/in-app notifications, bounded analytics/export |
| Tekshirilgan | Quyidagi shu-sessiya backend, real worker, desktop/mobile browser va build natijalari |
| Sozlangan | Lokal SQLite, private catalog disk, database imports queue; bu production sozlama emas |
| Sozlanmagan / tasdiqlanmagan | Real SMTP delivery, live AI, staging/TLS, monitoring, production backup/restore/load/rollback |
| Qolgan scope | To‘liq forecast/group buy/procurement approval/AI orchestration/platform administration; daily digest va barcha alert event hooklari ham to‘liq emas |

## Repository va database

Git repository mavjud, branch `main`, `origin` GitHub remote bor. Sprint boshida
toza working tree tekshirildi. Remote CI/deployment bajarilgani tasdiqlanmagan.
**11/11 migrations Ran**; yangi migration kerak bo‘lmadi, historical migrationlar
qayta yozilmadi. Lokal biznes bazasi reset/drop/replace qilinmadi. `.env`/APP_KEY
saqlandi va credentials hisobotga chiqarilmadi.

Eski hujjatlardagi “8 migration / Git yo‘q / Phase 4 boshlanmadi” avvalgi sessiyaning
tarixiy holati edi; amaldagi holat sifatida ishlatilmasin. Phase 2/3 hisobotlaridagi
sonlar o‘sha bosqich natijalari bo‘lib qoladi, yangi baseline emas.

## Tuzatilgan nuqsonlar

- Analyticsdagi MIN(id) va turli supplierlarni bitta vaqt seriyasi deb olish olib
  tashlandi. Phase 3 observed/recorded/ID latest, as-of va correction saqlanadi.
- Har supplierning latest/previous quote’i alohida, eligible min/max UZS/base-unit,
  product change aniq matched cohort IDs bilan. Invalid newest eski narxni qaytarmaydi.
- Export full offer ID hydration o‘rniga 500-row ID chunks va per-chunk snapshots;
  tenant filter IDlari tekshiriladi, formula-safe CSV ishlaydi.
- Email personal subscriber egasiga, active/verified/current membership bilan;
  global mahsulot qamrovi organization-wide broadcast emas. Rule edit owner-only.
- log/array unavailable; sent faqat SMTP acceptance, delivery emas. Per-recipient To,
  opt-out, exception failure, independent in-app notification va tenant dedupe bor.
- Extraction assertion catchdan tashqariga ko‘chirildi; true-only debug test actual
  invalid JSON regressioniga aylantirildi. Yashil test soni o‘zi barcha talab dalili emas.
- Import real queue worker bilan tekshirildi; UI worker heartbeat yo‘qligida unknown.
  SQLite IMMEDIATE + busy timeout web/worker writerlarini serializatsiya qiladi.

## Shu-sessiyadagi tekshiruvlar

Oldingi sprint baseline: PostgreSQL **201 passed / 1424 assertions / 43.988 s**;
SQLite **188 passed / 13 skipped / 1408 assertions / 13.734 s**;
Chromium **12 passed / 59.8 s**.

| Tekshiruv | Yakuniy haqiqiy natija |
| --- | --- |
| Laravel SQLite suite (2026-11-02) | 312 total; 298 passed, 14 skipped; 1982 assertions; 12.068 s |
| Playwright desktop + mobile (2026-11-02) | 28/28 passed; 2.4 min |
| TypeScript app/browser (2026-11-02) | Passed |
| Production frontend build (2026-11-02) | Passed; 2639 modules; 546 ms |
| Pint (2026-11-02) | Passed |
| PostgreSQL full suite | 216 passed, 2 skipped; 1496 assertions; 22.900 s |
| Alohida haqiqiy PostgreSQL queue worker | 1 passed; 17 assertions; 3.695 s |
| SQLite full suite | 204 passed, 14 skipped; 1482 assertions; 9.103 s |
| Chromium desktop | 9/9 passed |
| Chromium mobil (Pixel 7 viewport) | 9/9 passed |
| Jami browser | 18/18 passed; runner 1.7 min deb ko‘rsatdi |
| Pint / Composer validate | Passed |
| Composer / npm audits | Advisory yoki vulnerability topilmadi (shu execution paytida) |
| Config / route / view cache | Yaratish va clear passed, lokal cachelar tozalandi |
| Lokal migrations | 11/11 Ran; yangi migration yo‘q |

PG skiplar: alohida DB talab qiluvchi RealImportQueueTest (yuqorida alohida bajarilgan)
va PG CHECK bilan imkonsiz zero-price legacy SQLite testi. SQLite skiplar:
13 PostgreSQL-only CHECK/performance testi va alohida PG real-worker testi.
Hech bir yangi biznes fazasi boshlanmadi. Browser server va test worker teardown
bilan to‘xtadi (8137 listener va imports test worker qolmadi). Temporary PostgreSQL
container yakunda to‘xtatildi; haqiqiy ilova bazasi o‘zgartirilmadi.


Real worker testi alohida `quality_queue_test` bazasida wrapping transactionsiz,
haqiqiy artisan queue:work subprocess bilan. Default suites bu testni skip qiladi;
PostgreSQL zero-price legacy testini ham skip qiladi, chunki DB CHECK zero’ni
rad etadi. SQLite PostgreSQL-specific CHECK/performance testlarini qo‘llamaydi.

## Queue operational holati

Database queue `imports`; retry_after=90, job timeout=60, lease=90. Prepare tries=2,
Commit tries=3, backoff 5/15 sekund. Real worker preparation, failed_jobs, manual retry,
sheet reparse, human confirm, completed, valid_rows→completed_with_errors va duplicate
executionni tekshiradi. Production persistent worker joriy etilgani tasdiqlanmagan;
workersiz processing operational emas. Buyruqlar deployment.md da.

Dashboard pending/failed tenant batchlar va oxirgi completionni ko‘rsatadi; global
failed_jobs tafsilotlarini tenantga ochmaydi. Driver sozlanganligi active worker
dalili emas. Heartbeat/protected operator monitoring alohida gate.

## Integratsiyalar va xavfsizlik

Local APP_ENV=local, debug=true, mailer=log, AI unavailable. SMTP host/port fieldlari
borligi connectivity/deliveryni isbotlamaydi. Private storage local;
object storage/backup/encryption/retention/scanner productionda tasdiqlanmagan.

Direct tenant import/file/errors, alert/rule/notification IDOR, report filters,
auditor permissions va personal recipient isolation targeted testlar bilan tekshirildi.
Bu to‘liq pentest, RLS, 2FA yoki external immutable audit dalili emas.

AI extraction live credentialsiz HTTP so‘rov yubormaydi; schema/confidence/usage,
provider failure va human correction fake test provider bilan. Real provayder
muvaffaqiyati da’vo qilinmaydi. Price verification extraction approval’dan alohida.

## Performance va qamrov chegaralari

Analytics 10 product/sahifa, productga maksimum 500 supplier snapshot; 2001 history
qatori uchun bir supplier series, 21 query va taxminan 106 KB qo‘shimcha PHP peak
namunasi olindi. Har product explicit hisoblash querylari bor, page query soni product
soni bilan o‘sadi; constant-time da’vosi yo‘q. Export 500-row, history 50-row.

Real worker namunalari local subprocess vaqtidir; production p95/throughput/capacity
emas. Full JSON namunalari storage/framework/testing/quality-*.json da; aniq o‘lchov
va failures critical-quality-sprint.md da. Browser tarixidagi timeout/lokator/race
urinishlari yashirilmagan; final natijalar alohida qayd etiladi.

## Ochiq release gate va keyingi ish

- [ ] Production environment/debug/cookie/TLS/proxy konfiguratsiyasi va protected release.
- [ ] Haqiqiy SMTP reset/delivery va per-recipient provider evidence.
- [ ] Persistent worker/process health, queue failure alerts va recovery rehearsal.
- [ ] Real AI source/model/budget/provider smoke test yoki unavailable holatini saqlash.
- [ ] Private evidence retention/quota/scanning, encrypted backups va isolated restore.
- [ ] External monitoring/incident ownership, staging security/load/rollback testi.
- [ ] Qolgan agreed biznes scope va mavjud alert event/digest cheklovlarini yakunlash.

Bu gate’lar lokal test yoki hujjat mavjudligidan yopilmaydi. Batafsil sabab/fayl/fix/
test dalillari critical-quality-sprint.md, keyingi ish continuation-plan.md da.
