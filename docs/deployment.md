# Nazoratli deployment va operatsiyalar

2026-10-31. Hozirgi sprintda **production yoki stagingga deploy ruxsati yo‘q**.
Quyidagi fayllar review uchun tayyorlangan, serverga o‘rnatilmagan. Avvalgi
migration bajarishga oid yo‘riqnoma bu sprint uchun amal qilmaydi.

## Ish tartibi va vakolat

1. Hosting/operator, staging domeni, region, process manager va monitoring egasini aniqlash.
2. Lokal ops fayllari, testlar va environment manifestini review qilish.
3. Staging infratuzilmasiga o‘zgartirish uchun aniq ruxsat olish.
4. Keyin stagingda TLS/DB/storage/worker/SMTP/backup/rollbackni real tekshirish.
5. Dalillar bilan production release candidate tasnifini qayta baholash.
6. Production release uchun alohida explicit approval. Staging ruxsati production ruxsati emas.

Schema migratsiyasi yoki destructive DB amali **bajarilmaydi**. Read-only
`php artisan ops:check --dependencies` repository migration nomlari bilan mavjud
migration yozuvlarini solishtiradi. Pending schema bo‘lsa release bloklanadi;
ushbu topshiriqdan uning bajarilishi uchun ruxsat kelib chiqmaydi. Mavjud kalitlar
saqlanadi; .env overwrite va key regenerate qilinmaydi.

## Tayyorlangan shablonlar

- operations/smart-market-imports.service: database/imports worker, boot autostart
  uchun install target, restart=always, SIGTERM va timeout=100.
- operations/smart-market-check.service/.timer: read-only dependency/queue checker.
- operations/smart-market-backup.service/.timer va pg-backup.sh: encrypted dump
  namunasi. pg_dump/age, secret files va offsite retention alohida provisioning.
- operations/nginx-staging.conf: TLS public-root/PHP-front-controller namunasi.
- operations/runtime.env.example: secret qiymatlarsiz environment talablari.

Hozir macOS muhitida systemd/nginx/pg_dump/age yo‘q, shablon syntaxi real Linux
serverda tekshirilmagan. Hosting tanlanmaguncha ularni install/start/reload qilmang.
Cloud tanlansa service unitlar qo‘llanmaydi; platform process managerga moslashtiring.
Mavjud private local catalog driver ephemeral replicas uchun mos emas; file path
APIlariga tayangan adapterlar qayta review qilinmaguncha Cloud storagega ko‘chirmang.

## Environment va TLS

Staging uchun alohida DB, session, storage va APP_KEY; production key ishlatilmasin.
Secret manager yoki root-owned 0600 environment fayl. PHP-FPM pool va systemd
workerga bir xil mos environment inject qilinsin; PHP-FPM `clear_env` defaulti sabab
shell envning avtomatik o‘tishini taxmin qilmang. Nginx maxfiy qiymat saqlamasin.

APP_ENV staging/production, APP_DEBUG=false, APP_URL=https, secure/encrypted/HttpOnly
sessions; database session/cache/queue. TLS sertifikat hostname/chain/expiry,
HTTP redirect, HTTPS cookie va proxy headerlari stagingda real tekshirilsin.
Forwarded headerlar faqat aniq ishonchli proxy manzillaridan qabul qilinsin;
host/proxy tanlanmagan muhitda barcha proxylarga ishonish yoqilmadi.

PostgreSQL sslmode=verify-full + DB_SSLROOTCERT environment opsiyasi. Application
roli superuser/createdb/createrole bo‘lmasin; schema/permission provisioning operator
review’i bilan. ops:check real TLS va role flaglarini faqat --dependencies bilan
SELECT orqali ko‘radi; role credentiallarini chiqarmaydi.

## Worker

Database queue imports, retry_after=90, lease=90, job timeout=60. Prepare tries=2,
Commit tries=3, backoff 5/15. Shablon worker har soatda clean exit qiladi va systemd
restart=always bilan qaytadi; --memory=256 local capacity isboti emas. `enable` boot
uchun, `start` va server reboot/failure rehearsal faqat infra ruxsatidan keyin.

Operator amallari (ruxsatli serverda): process status/journal, queue age/count,
failed_jobs va last success. Template check timer nonzero statusini tashqi monitoring
incidentga aylantirsin; journalning o‘zi alert yetkazildi degani emas. UI/CLI worker
heartbeat yo‘qligida unknown. Database queue konfiguratsiyasi active worker dalili emas.

`OPS_WORKER_HEARTBEAT_ENABLED=true` va `OPS_WORKER_HEARTBEAT_PATH` barcha import
workerlar hamda check timer ko‘radigan private shared operations katalogiga berilsin.
Yangi kod idle iteration yoki import job startidan keyin, har processda ko‘pi
bilan 10 sekundda bir atomik 0600 JSON yozadi; o‘ziga kerak katalogni 0700 yaratadi.
Hech qanday payload/tenant/user/credential yozilmaydi. Signal yoshi 120 sekunddan
oshsa stale, yo‘q/noto‘g‘ri/kelajakdagi/o‘qilmaydigan signal monitoringni nonzero qiladi.
Bu kamida bitta workerning yaqin faoliyatini bildiradi; barcha replica healthi,
ayni ondagi liveness yoki successful job/delivery tasdig‘i emas. Host clocklari
sinxron bo‘lsin; config o‘zgarsa worker graceful restart bilan yangilansin.
File/volume huquqi, reboot va timer→external alert stagingda tekshirilsin.
Local environmentda flag default false; `.env`ga yozilmadi.
Laravel idle eventini queue xatosi/paused queue bilan ham chiqarishi mumkin.
Recent heartbeat successful queue poll emas: DB/queue probe, queue age va failed-job
monitoring alohida baholanadi. Global heartbeat barcha workerlar sonini bermaydi.

```sh
php artisan ops:check --dependencies
php artisan queue:failed
php artisan imports:recover --dry-run
```

Actual recovery yoki retry side effect qiladi: xato sababi, recipient ehtimoliy
qayta yuborilishi, generation va idempotency auditini tekshirib, tegishli ruxsat bilan.
Worker start/restart ishlab turgan importlarni graceful tugatsin. Queued external
request retry’da provider idempotency keyga amal qilishi real provider bilan tekshirilsin.

## SMTP

MAIL_SCHEME=smtp + MAIL_REQUIRE_TLS=true (STARTTLS/587), yoki smtps/465.
MAIL_TIMEOUT=15. MAIL_HOST/USERNAME/PASSWORD/FROM tashqi secret configuration.
MAIL_URL overrides ishlatilsa alohida review; ops probe konservativ ravishda to‘xtaydi.
Peer/certificate verification o‘chirilmasin. O‘rnatilgan Symfony 8.1 TLS talabi opsiyasini
qo‘llaydi; Laravel MailManager uni transportga uzatadi.

`ops:smtp-probe` default dry run. Foydalanuvchi tasdiqlagan recipient
OPS_SMTP_TEST_RECIPIENTS allowlistiga operator qo‘shganidan keyin explicit --send
bilan bitta nazorat xati. Credential yoki recipient logga chiqarilmaydi. Avtomatik
resend yo‘q: SMTP keyin uzilgan bo‘lsa qabul qilingan bo‘lishi mumkin, oldin provider
logi/inboxdagi probe IDni tekshiring. SMTP_ACCEPTED delivery emas.

## AI

Vendor-neutral extractor gateway contracti oldingi Phase 4 hujjatida. Endpoint,
model va key mavjud bo‘lsa ham real fixture PDF/PNG/JPEG, confidence/region/missing
fields, usage va human review tekshiruvisiz operational deyilmaydi. Document text
untrusted; supplier/price verification confidence’dan meros bo‘lmaydi. Missing key
bo‘lsa unavailable, manual CSV/XLSX/import review saqlanadi. Provider narxi yoki
modeli taxmin qilinmaydi. Haqiqiy chaqiruv uchun credentials/data-transfer ruxsati kerak.

## Release va rollback

Working tree’dagi corrective sprint o‘zgarishlari ham hali commit qilinmagan.
Reviewed immutable commit/artifact bo‘lmaguncha controlled release yo‘q. Build yangi
release katalogida, shared storagega yozmasdan, test/staging muhitida tayyorlansin:

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run typecheck
npm run build
```

`composer setup` va application initialization scriptlari bu sprintda ishlatilmaydi:
ular schema/key amallarini bajarishi mumkin. Cache faqat aniq target runtime
configuration bilan candidate katalogida yaratiladi, joriy local configga tegilmaydi.

Schema compatibility: ushbu sprint migration qo‘shmaydi. Oldingi forward-only
schema bilan avvalgi artifact mosligi manifest orqali tekshirilsin. Staging operator
release hash, current target, schema version, shared storage va key reference’larini
maxfiy qiymatlarsiz evidence’da qayd etadi. Preflight/staging health fail bo‘lsa traffic
switch yo‘q. Tasdiqdan keyin atomic current symlink switch va graceful worker restart.

Rollback — mos oldingi immutable artifactga application symlinkini qaytarish;
**DB restore/drop/schema rollback yo‘q**. Public root, shared catalog/session DB va
APP_KEY o‘zgarmaydi. Stagingda aynan current→candidate→previous oqimi, in-flight
import, login/session va private file access real tekshirilmaguncha rehearsal passed
emas. Production approval faqat tayyor artifact va staging dalillari mavjud bo‘lganda
so‘raladi; ushbu sessiyada production approval so‘ralmadi va deploy bajarilmadi.
