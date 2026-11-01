# Muhim sifat tuzatishlari va tayyorlik sprinti

Sana: 2026-10-31, Asia/Tashkent. Maqsad — mavjud analytics, notifications, import
queue, testlar va hujjatlarni tuzatish. Yangi 7–10-bosqichlar boshlanmadi.

## Boshlang‘ich holat

Git branch `main`, ish boshida working tree toza. `origin` GitHub remote mavjud.
11 migratsiyaning barchasi lokal bazada Ran. Eski “Git yo‘q / 8 migration” yozuvlari
hozirgi holat emas. Mavjud `.env`/kalitlar o‘zgartirilmadi, biznes bazasi reset yoki
replace qilinmadi. Schema o‘zgarishi kerak bo‘lmadi; historical migrations tahrirlanmadi.

Shu sessiyadagi qayta baseline:

| Muhit | Natija |
| --- | --- |
| PostgreSQL 17, isolated test DB | 201 passed, 1424 assertions, 43.988 s |
| SQLite in-memory | 188 passed, 13 skipped, 1408 assertions, 13.734 s |
| Chromium desktop/mobil | 12 passed, 59.8 s |

Baseline yashil bo‘lsa ham debug testi unconditional true va transaction assertion
catch bilan yutilgani aniqlandi. Shu sabab baseline funksional talablarning to‘liq
isbotiga tenglashtirilmadi.

## Nuqsonlar, tuzatish va dalil

| Nuqson / ildiz sabab | O‘zgargan fayllar va tuzatish | Regressiya |
| --- | --- | --- |
| MIN(id) eski taklif tanlagan, turli supplierlar first/last deb aralashgan | AnalyticsController + AnalyticsPrices: Phase 3 CompareOffers/PriceHistory qayta ishlatiladi; latest/as-of, supplier series, eligible min/max, cohort IDs | QualityAnalyticsTest; quality.spec analytics |
| Export barcha offer IDlarini pluck qilgan, verification filtri ishlamagan | 500-row ID chunks, per-chunk review snapshot, tenant filter ownership, safeCell | Existing export + QualitySecurityTest, browser download |
| Global scope personal alertni org broadcastga aylantirgan | ManageAlerts: qoida egasi, active/verified/current membership, per-recipient To, tenant dedupe | AlertDeliveryTest |
| log/array real sent deb yozilgan | Faqat configured smtp transport return bilan sent; failed/unavailable alohida; UI delivery emasligini ko‘rsatadi | SMTP exception, opted-out, log va acceptance tests |
| Boshqa manager birovning rule preference’ini o‘zgartira olgan | Owner-only update va listing, backend 404; UI name/email/enabled edit | Direct HTTP + browser |
| Threshold abs floatga aylangan | BCMath bilan signni olib tashlash, bounded decimal validation | Alert delivery/threshold regressiyalari |
| Assertion application catch ichida yutilgan | DocumentExtractionTest callback faqat transaction darajasini yozadi; assertion test kontekstida, tashqarida | Real structured output, confidence, usage va unavailable regressiyalari |
| Debug test doim true | DebugExtractionTest actual invalid JSONni rad etishini va request countni tekshiradi | Invalid-JSON test |
| Queue configured holati operational deb talqin qilingan | Import dashboard tenant pending/failed/last-success; worker unknown | RealImportQueueTest + browser actual worker |

## Haqiqiy queue sinovi

Alohida yangi `quality_queue_test` PostgreSQL bazasida migrationlar qo‘llandi.
Test wrapping transaction ishlatmaydi, worker boshqa process. `queue:work database
--queue=imports --once --sleep=0 --timeout=60 --tries=2` haqiqatan ishga tushadi.

XLSX noto‘g‘ri header bilan queuega yoziladi; worker reserve/claim qiladi, validating
historiya yaratiladi, failed status va failed_jobs yozuvi paydo bo‘ladi. Explicit retry
ikkinchi urinishni qiladi. Sheet/header tuzatilgach yangi generation preview tayyorlaydi.
Inson tasdig‘idan keyin boshqa worker processing→completed va product yozuvini yaratadi.
Takroriy commit delivery productni qayta yaratmaydi; oxirida pending jobs 0.

Yakuniy isolated real-worker namuna: 17 assertion, 3.695 s test; worker workflow wall
3346.99 ms, asosiy batch uchun 4 claim va qo‘shimcha partial batch uchun 2 claim,
2 kutilgan failed job. Bu failurelar sinov fixture’i,
production hodisasi emas. Artifact storage/framework/testing/quality-queue.json.

Database queue default retry_after=90 s, import lease=90 s, job timeout=60 s.
Prepare tries=config imports.attempts (default 2, 1–3), Commit tries=3;
backoff=[5,15]. Worker exception matni/payload emas, class/code bilan kuzatiladi.
Real SMTP/live AI bu testda chaqirilmagan. Browserlar ham isolated SQLite DBdagi
haqiqiy worker bilan ishlaydi, teardown faqat o‘zi yaratgan worker PIDni to‘xtatadi.

## Authorization va test integrity

QualitySecurityTest tenant import/details/file/errors, alert-rule/notification IDOR,
report export va auditor write denialni tekshiradi. Guest/tenant/role avvalgi testlar
saqlandi. Joriy membership bekor bo‘lsa recipient chiqariladi. UI restriction backend
policy o‘rnini bosmaydi. Hisoblashlar decimal string; narx verification supplier yoki
AI confidence’dan meros bo‘lmaydi.

Transaction assertion LazyRefreshDatabase ochgan tashqi test transactionini baseline
sifatida oladi: provider ichida qo‘shimcha transaction bo‘lmasin. Assert HTTP callback
ichida exception otmaydi, uning natijasi tashqarida baholanadi. Bu provider/worker
exception handleri assertionni yutishini oldini oladi. Production fake AI qo‘shilmadi.

## Browser tekshiruvlari

Yangi quality.spec: CSV upload, header mapping, invalid-row error download, correction,
confirmation, history, XLSX sheet/reparse, unavailable AI, manual review va reject;
notification list/unread/read-all, personal rule creation/name/email/enabled;
analytics same-supplier +20%/−10%, historical filter, CSV export, auditor restriction.
Har biri desktop va mobil profilda. Oldingi 12 test saqlangan.

Dastlabki yangi lokatorlar HTML select labelining optional textini hisobga olmagani
sabab timeout bo‘ldi; aniq role/name yoki form field selectoriga bog‘landi. Sheet response/poll yakuni
kutilmasdan eski forma submit qilinishi revision guard bilan rad qilindi; test yangi
previewni kutadigan bo‘ldi. Bir oldingi worker urinishida generic processing failure
ko‘rindi; o‘sha istisno matni sanitizatsiya qilinganidan asl sababni qat’iy tasdiqlash
imkoni yo‘q. SQLite IMMEDIATE/busy timeout va class/code diagnostikasi qo‘shildi;
keyingi actual-worker runlar bilan tekshirildi. Assertions
zaiflashtirilmadi. Final natijalar quyida, failed/interrupted urinishlar yashirilmaydi.

## Performance

2001 observation / 1 supplier uchun yakuniy analytics namuna: 21 query,
PostgreSQL DB 33.09 ms, service 40.91 ms, qo‘shimcha peak PHP memory 105272 byte.
SQLite namuna: 21 query, DB 12.47 ms, wall 17.02 ms, peak delta 105520 byte.
Artifacts storage/framework/testing/quality-analytics-{driver}.json.

History o‘sishi bilan hydrated series bitta qoladi. Dashboard maksimum 10 product,
per-product querylar bor; page query soni product soni bilan o‘sadi. SQL index/window
Phase 3dan saqlandi. Bu warmed local namuna, concurrency/p95/production capacity emas.

## Konfiguratsiya va tashqi bloklar

Tekshirilgan local holat: APP_ENV=local, debug=true, mail transport=log, AI unavailable,
private local catalog disk, database imports queue, retry_after=90. SMTP host/port
fieldlari mavjudligi real connectivity/delivery dalili emas. Secret qiymatlar chiqarilmadi.

Production checklist:

- APP_ENV production, debug false; real HTTPS, secure cookies, trusted proxy sozlamalari.
- MAIL_MAILER smtp, operator boshqaradigan host/port/credential va TLS scheme;
  Laravel 13 mail config’dagi scheme/URLni tekshiring, eski MAIL_ENCRYPTION nomi
  amaldagi configga ulanmagan bo‘lsa ta’sir qilmaydi. Real recipientga recovery/delivery testi.
- Persistent database queue worker process manager, restart/recover runbook, failure alerts.
- AI endpoint/model/key faqat secret manager/env; live provider testi bo‘lmasa unavailable.
- Private storage ACL/encryption/quota/retention, malware scanner va evidence legal retention.
- Secret/payloadsiz loglar, external monitoring va incident ownership.
- Encrypted backup, retention, isolated restore va o‘lchangan RPO/RTO.
- Staging load/security/rollback rehearsal va protected CI/release.

Local config real production configga almashtirilmadi. Staging/TLS/monitoring,
SMTP/live AI, backup/restore va load sinovlari hali tashqi release gate.

## Yakuniy natijalar

| Tekshiruv | Yakuniy haqiqiy natija |
| --- | --- |
| PostgreSQL full suite | 216 passed, 2 skipped; 1496 assertions; 22.900 s |
| Alohida haqiqiy PostgreSQL queue worker | 1 passed; 17 assertions; 3.695 s |
| SQLite full suite | 204 passed, 14 skipped; 1482 assertions; 9.103 s |
| Chromium desktop | 9/9 passed |
| Chromium mobil (Pixel 7 viewport) | 9/9 passed |
| Jami browser | 18/18 passed; runner 1.7 min deb ko‘rsatdi |
| TypeScript app/browser | Passed |
| Production frontend build | Passed; 2630 module, 1.55 s |
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


## Keyingi aniq ishlar

Yangi biznes fazasiga o‘tishdan oldin production operator persistent worker, SMTP,
staging/TLS/monitoring, retention/backup/restore va load/security/release rehearsallarini
haqiqiy muhitda bajarishi kerak. Daily digest/category scope, hamma alert event
hooklari va real provider delivery tasdig‘i bu sprintda tugallangan deb belgilanmaydi.
Mahsulot lokal regression daliliga ega; production-ready da’vosi yo‘q.

## Yakuniy baho

Corrective sprintning tekshirilgan analytics/recipient/status/queue/test integrity,
browser va targeted tenant regression ishlari bajarildi. Yangi migration yoki paket
kiritilmadi. GitHub Actions workflow real-worker uchun alohida test DB qadami bilan
kengaytirildi, ammo remote Actions shu sessiyada bajarilmadi. Yashil local natija
production SMTP, persistent host worker, TLS, backup/restore yoki release approval
emas. Real ishga chiqarish yuqoridagi tashqi gate’lar bajarilmaguncha tavsiya etilmaydi.
