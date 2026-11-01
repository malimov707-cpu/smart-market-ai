# Davom ettirish rejasi

## 2026-10-31 deployment/operations tayyorgarligi

Davomi: import worker uchun opt-in private heartbeat implement qilindi. Idle
iteration/job start signali, throttling va stale/missing/invalid signalda ops failure
bor. Alohida vaqtinchalik SQLite job jadvali bilan haqiqiy worker subprocess testi
migratsiyasiz bajarildi; bu production worker o‘rnatilgani degani emas. Lokal flag
o‘chiq, worker holati unknown. External host/SMTP recipient haqidagi savollar ochiq.
Yakuniy targeted ops/heartbeat natija: **25 passed / 56 assertions / 2.575 s**;
Pint/diff passed. Quyidagi 11-test natijasi oldingi ops incrementga tegishli.

Production tasnifi **NOT READY**. Lokal login HTTP 200. Read-only probe: 11 migration,
0 pending schema, DB/storage reachable; worker unknown. SMTP live/AI live unverified.
OperationsPreparationTest 11 passed / 30 assertions; migration ishlatmaydi. Ops
shablonlari serverga o‘rnatilmadi. [Joriy dalillar](production-operations-sprint.md).
Keyingi ish: staging host/access/operator va authorized SMTP recipient, reviewed
artifact; keyin ruxsatli worker/TLS/SMTP/monitoring/restore/load/rollback tekshiruvi.
`migrate` va destructive DB amallari taqiqlangan; full-suite/browser setup schema
tayyorlashi sabab joriy sprintda qayta ishlatilmadi. Quyidagi sonlar tarixiy baseline.

## 2026-10-31 corrective sprint

Mavjud analytics MIN(id)/supplier mixing, personal notification recipient/status,
assertion suppression va real import queue dalili bo‘yicha tuzatishlar bajarildi.
7–10-bosqichlar boshlanmadi. Phase 1–4 core regressiyalari saqlandi.

Joriy code: AnalyticsPrices Phase 3 latest/as-of/comparison/historyni ishlatadi;
export 500-row chunks. Personal alert faqat active/verified/current-member egasiga,
log/array unavailable va SMTP accepted deliverydan ajratilgan. Rule owner-only edit.
RealImportQueueTest haqiqiy isolated PostgreSQL worker processlari bilan, browserlar
isolated SQLite persistent worker bilan. Test transaction assertion callbackdan
tashqarida. Soxta production AI yoki market seed yo‘q.

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


Git branch main, remote mavjud, 11/11 migrations Ran. Yangi schema kerak bo‘lmadi;
.env/key/business DB reset/replace qilinmadi. Local debug/mail development holati
saqlandi. Test setup faqat o‘z temp DB/storage/workerini yaratadi va tozalaydi.

## Muhim saqlanadigan qoidalar

Latest observed_at/recorded_at/ID, correction immutable, missing delivery null,
invalid newest sabab eski arzon quote revival yo‘q. Cohort IDs va insufficient-data
saqlansin. Personal global rule org broadcast emas; current membership qayta
tekshirilsin, email opt-out va individual To buzilmasin. SMTP accepted delivery emas.

Private files/price evidence hard-delete qilinmasin; purge faqat tasdiqlanmagan
expired failed/cancelled import uchun reviewed amal. Live extraction unavailable
holatini fake confidence yoki task bilan yashirmang. UI worker unknownni activega
almashtirish uchun real heartbeat dalili kerak.

## Navbatdagi aniq ishlar

1. critical-quality-sprint.md/readiness va testlar bilan tanishing; yangi sessiyada
   joriy baseline’ni qayta oling, historical sonlarni yangi natija sifatida yozmang.
2. Stagingda database imports workerini process manager orqali doimiy ishga tushiring;
   queue oldest age/failed_jobs/process health va recovery/worker restartni tekshiring.
3. Real SMTP/TLS/cookies/debug configuration review, secret-safe provisioning va
   haqiqiy recipient/account recovery testi. Key/secretni chatga yubormang.
4. Real AI kerak bo‘lsa tasdiqlangan endpoint/model/budget va credentials bilan smoke
   test; aks holda unavailable. Live provider hali tekshirilmagan.
5. Encrypted backup/retention/isolated restore, monitoring/incident ownership,
   security/load/release rollback rehearsal dalillarini oling.
6. Daily digest/category scope va barcha notification event hooklari tugallanmaganini
   saqlang; yangi biznes fazasiga faqat alohida kelishilgan topshiriqda o‘ting.

Lokal testlar production readiness o‘rnini bosmaydi. Biznes bazasi va .env saqlansin.
