# Deployment va operatsiyalar sprinti

2026-10-31. Yakuniy tasnif: **NOT READY** — production deployment uchun.
Lokal `/login` HTTP 200; staging yoki production deploy bajarilmadi.

## Davom — worker signalini real tekshirish

`QueueWorkerHeartbeat` va AppServiceProvider listenerlari qo‘shildi. Faqat import
queue’ning idle iteration yoki job start eventi signal yozadi; boshqa
connection/queue va sync job worker dalili bo‘lmaydi. Har processda 10 sekundlik
throttle, private 0600 atomik fayl, 0700 operations katalogi; faqat stream hash va
UTC epoch sekund saqlanadi. Idle event queue xatosi yoki paused queue bilan ham
kelishi mumkin: bu successful poll emas, DB/queue probe alohida. Monitoring read-only. Missing/stale/invalid/unavailable
signal `ops:check --dependencies`ni nonzero qiladi. Writer/logging xatosi jobni
to‘xtatmaydi. Config/env shabloniga opt-in flag/path qo‘shildi; `.env` o‘zgarmadi.

Yakuniy joriy tekshiruv: **25 passed / 56 assertions / 2.575 s**
(`QueueWorkerHeartbeatTest` + `OperationsPreparationTest`), Pint va diff check passed.
Haqiqiy artisan worker alohida subprocessda 2 sekundlik bounded execution bilan,
faqat yangi vaqtinchalik SQLite fayldagi bo‘sh `jobs` fixture jadvalida ishladi.
Parent process uning private fayldagi signalini o‘qidi; job soni 0, migration yo‘q,
mavjud biznes bazasiga ulanmadi. Test fayllari tozalandi, doimiy worker qoldirilmadi.
Stale/future/oversized/corrupt/mismatched evidence, throttling, disabled monitoring,
IO/log failure va command failure ham tekshirildi. Dastlab test fixture helperidagi
protected API va SyncJobning `sync` queue semantikasiga mos kelmagan fixture
tuzatildi; yakuniy run yuqorida. Production infra muvaffaqiyati da’vo qilinmaydi.

Lokal flag o‘chiq: read-only probe hali **worker unknown**, 11 migration/0 pending
schema va DB/storage reachable; 0 pending/reserved/failed jobs. Linux shablonlari
install/start qilinmadi. Docker daemon va PostgreSQL 17 image mavjudligi tekshirildi,
running container yo‘q; yangi container ishga tushirilmadi.

`heartbeat_recent` kamida bitta worker yaqinda ishlaganini bildiradi; worker ayni
onda tirikligi, barcha replica yoki job/email muvaffaqiyati emas. Shared filesystem,
clock sync, process supervision/reboot, failed-job incident delivery va actual
staging/release/restore dalillari hali ochiq. **NOT READY** tasnifi saqlanadi.
Texnik tayanch: o‘rnatilgan Worker/WorkerIdle/JobProcessing/Filesystem source va
[Laravel queue events](https://laravel.com/framework/docs/13.x/queues).

## 1. Audit

Critical quality, readiness, continuation, queue/job, private storage, mail va
extraction konfiguratsiyasi ko‘rildi. Git remote mavjud, lekin corrective sprint
va ops o‘zgarishlari working tree’da; reviewed immutable release artifact yo‘q.
Read-only tekshiruv: SQLite reachable, 11 migration yozuvi, 0 pending schema,
0 pending/reserved/failed job, private storage accessible. Worker unknown;
bo‘sh queue worker ishlayotganini isbotlamaydi.

Muhit local/debug/HTTP, mail log, AI unavailable. Staging host/access va authorized
SMTP recipient berilmagan. macOS muhitida systemd/nginx/pg_dump/age mavjud emas.
`.env`, key va biznes ma’lumotlari almashtirilmadi. `migrate`, schema rollback,
DB reset/delete yoki production infrastructure amali bajarilmadi.

## 2. O‘zgargan fayllar

- `app/Services/OperationsCheck.php`, `app/Console/Commands/OperationsCheckCommand.php`:
  secret qiymatlarsiz config va read-only DB/schema/queue/storage tekshiruvlari.
- `app/Console/Commands/SmtpProbe.php`: default dry run, explicit send va recipient
  allowlist, authenticated mandatory TLS, sanitized errors; accepted delivery emas.
- `config/mail.php`, `config/database.php`, `config/operations.php`: SMTP timeout/TLS,
  PostgreSQL CA va operator allowlist/queue threshold opsiyalari. `.env` o‘zgarmadi.
- `docs/operations/`: systemd worker/check/backup service/timer, encrypted PostgreSQL
  dump scripti, staging nginx va secret qiymatsiz environment shablonlari.
- `tests/Feature/OperationsPreparationTest.php`, `tests/Operations/`: safe CLI/provider
  failure, isolated SQLite restore va bounded anonymous local GET load tekshiruvlari.
- `docs/deployment.md`, readiness/continuation/README: deployment/release/rollback
  tartibi va haqiqiy tekshiruv chegaralari. Yangi migration/runtime dependency yo‘q.

## 3. Queue

Database/imports, timeout 60 s < retry_after 90 s, lease 90 s; Prepare attempts 2,
Commit 3, backoff 5/15 s. Systemd shablonida graceful stop/restart/boot target bor;
serverga o‘rnatilmagan. Reboot, process failure recovery va failed-job alertlari
live tekshirilmagan. Oldingi isolated real queue **1 passed / 17 assertions**
tarixiy dalil, ushbu sprintda qayta bajarilmadi.

## 4. SMTP

Dry run hech narsa yubormaydi. Unauthorized recipient, log/non-TLS refusal,
sanitized failure va no-auto-resend testlandi. Required TLS transport opsiyasi
ulanishsiz tekshirildi. Haqiqiy handshake/auth/SMTP accepted/inbox delivery hali
tekshirilmagan; bu sprintda hech bir real xat yuborilmadi. Operator allowlistiga
foydalanuvchi tasdiqlagan recipient kerak; credentiallar chatga kiritilmasin.

## 5. AI

Credentials yo‘q, live provider unavailable. Missing key so‘rov yubormasligi,
timeout, 429, outage va invalid output HTTP fake bilan testlandi. Bu real extraction
dalili emas. Manual import saqlanadi; confidence supplier/price verification emas.
Live extraction uchun endpoint/model/budget va secret-managed key kerak.

## 6. Staging va TLS

Nginx HTTPS va secure environment shablonlari tayyor, install/reload bajarilmadi.
`ops:check --dependencies` exit 1 / NOT READY: local environment, debug, HTTP,
secure sessions, PostgreSQL TLS va SMTP gate. Private local storage uchun persistent
shared volume kerak; ephemeral replicas avtomatik qo‘llanmaydi. Host, certificate,
DB least privilege/verify-full, cookie/proxy sozlamalari live tekshirilmagan.

## 7. Monitoring va backup/restore

Read-only CLI queue age/count/failed jobs/DB/storageni beradi; heartbeat kodi lokalda
testlandi, ammo staging worker heartbeat,
external incident delivery va operational owner hali verified emas. Global queue
ma’lumotlari yangi tenant HTTP endpointiga chiqarilmadi.

SQLite backup read-only manbadan shaxsiy temp snapshotga, undan yangi temp restorega
olindi: **31 table, schema/count equality, integrity/FK OK, 67.497 ms**. Temp nusxalar
tozalandi. Bu production PostgreSQL, encrypted/offsite backup yoki retention dalili
emas. Backup shabloni pg_dump → age va verify-full talab qiladi; `bash -n` passed.
Auto prune yo‘q. Offsite/retention/key recovery/private uploads consistent backup,
real isolated PostgreSQL restore hamda RPO/RTO o‘lchovi ochiq.

## 8. Joriy testlar, load va xavfsizlik

| Tekshiruv | Haqiqiy natija |
| --- | --- |
| OperationsPreparationTest | 11 passed, 30 assertions, 231 ms; schema/migration yo‘q |
| Targeted Pint | Passed |
| Vite build | Passed; 2630 module, 605 ms |
| Composer / npm audit | Advisory yo‘q / 0 vulnerabilities, execution paytida |
| Lokal login | HTTP 200 |
| GET /up, 40 request, concurrency 4 | 40 HTTP 200; median 47.951 ms; sample p95 225.952 ms |
| GET /login, 40 request, concurrency 4 | 40 HTTP 200; median 120.324 ms; sample p95 740.927 ms |

Load faqat kichik lokal anonymous GET namunasi; authenticated import/notification
concurrency, query-plan yoki production capacity o‘lchovi emas. To‘liq pentest yo‘q.
Oldingi PostgreSQL 216 passed/2 skipped, SQLite 204 passed/14 skipped va desktop/mobile
9+9 browser natijalari critical-quality-sprint.md dagi **tarixiy dalil**. Joriy migration
taqiqi sabab schema tayyorlaydigan full-suite/browser setup qayta bajarilmadi.

## 9. Rollback

Tartib oldingi mos immutable application artifactga qaytadi; DB/schema/uploaded
files qaytarilmaydi yoki o‘chirilmaydi. Shared storage/key saqlanadi. Staging
current→candidate→previous, in-flight import/session/private file rehearsal hali
bajarilmadi. Production deploy ruxsati so‘ralmadi va olinmadi.

## 10. Blockerlar va keyingi reja

Staging host/domen/operator/access, reviewed immutable release, environment secrets,
DB TLS/permissions, persistent files/worker va real SMTP recipient kerak. Keyin
ruxsatli stagingda monitoring, failure/reboot recovery, encrypted/offsite backup va
PostgreSQL restore, authenticated concurrent load/security va rollback tekshirilsin.
Joriy migration taqiqi saqlanadi. Faqat tayyor artifact va staging dalillaridan so‘ng
production uchun alohida explicit approval so‘raladi. Yangi biznes fazasi boshlanmadi.

Texnik tayanch: o‘rnatilgan Laravel/Symfony source;
[PostgreSQL TLS](https://www.postgresql.org/docs/17/libpq-ssl.html),
[Symfony Mailer](https://symfony.com/doc/current/mailer.html).
