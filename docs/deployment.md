# Deploy va operatsiyalar

Holat: staging/production deploy bajarilmagan. Bu hujjat ishlayotgan hosting,
TLS, SMTP, monitoring yoki backup dalili emas. To‘liq release gate’lar readiness
hisobotida. Lokal `.env` o‘zgartirilmagan.

## Muhitlar

Local mavjud SQLite/.env bilan ishlaydi. Feature testlar default in-memory SQLite;
PostgreSQL integratsiya testlari alohida process env va vaqtinchalik test bazasi;
browser testlari alohida temp SQLite/server. Staging va production uchun alohida
APP_KEY, database, private storage va mail credentials kerak.

PHP 8.4, BCMath, PDO pgsql, mbstring, XML, curl va fileinfo; Composer; Node 24 build
uchun; PostgreSQL 17. Productionda Docker/Redis hozir talab qilinmaydi. Web root
faqat public/; storage/ va bootstrap/cache/ application user uchun yoziladigan bo‘lsin.

## Staging tartibi

1. Operator HTTPS host, PostgreSQL va private storage yaratadi. APP_ENV=staging,
   APP_DEBUG=false, APP_URL haqiqiy HTTPS host, yangi APP_KEY; DB_CONNECTION=pgsql
   va DB_* secretlarini o‘rnating. APP_LOCALE=uz, SESSION_DRIVER=database,
   SESSION_ENCRYPT=true, SESSION_SECURE_COOKIE=true, CACHE_STORE=database,
   QUEUE_CONNECTION=database, LOG_LEVEL=info.
2. TLS bilan haqiqiy SMTP/API transporti va verified sender sozlang. Log/array
   transport tokenli identity xatlari uchun productionda bloklangan. Ro‘yxatdan
   o‘tish, verification, reset va invitation yetkazib berilishini tekshiring.
3. Yangi release katalogida:
   ```sh
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   npm ci
   npm run typecheck
   npm run build
   php artisan migrate:status
   php artisan migrate --pretend
   ```
4. Backup va SQL reviewdan keyin kelishilgan oynada `php artisan migrate --force`.
   Yangi identity migratsiyasi forward-only. Productionda migrate:fresh/seed/rollback
   amallarini avtomatik bajarmang.
5. `php artisan optimize`; HTTPS web serverni release public/ ga yo‘naltiring.
6. /up, login, verification, invitation acceptance, last-owner protection, tenant
   isolation, sessiya bekor qilish, CSV va comparison oqimlarini tekshiring.
7. Hozir domain job/schedule yo‘q. Qo‘shilganda supervisor bilan
   `php artisan queue:work --tries=3 --timeout=90`; cron har daqiqada
   `php /path/to/artisan schedule:run`; deploydan keyin `php artisan queue:restart`.
   Bu jarayonlar hali sozlangan yoki sinovdan o‘tgan deb hisoblanmaydi.

## Release va rollback

Barcha release blockers yopilmaguncha production release yo‘q. Stagingdan keyin
alohida production secretlar va operator tasdig‘i bilan immutable release kataloglari
hamda atomic symlink qo‘llang. CI hozir validation uchun; real GitHub run/deploy yo‘q.

Rollbackda yozishlarni to‘xtating, schema bilan mos avvalgi releasega o‘ting va
configuration cache/workerlarni yangilang. Schema uchun forward fix afzal. Audit va
identity jadvallarini o‘chirish xavfsiz rollback emas. Restore faqat impact review va
aniq ruxsatdan keyin; [backup-restore](backup-restore.md) hali tekshirilmagan.

## Monitoring va yuklama

/up faqat boot. DB/storage/provider health probes, error aggregation, log rotation,
operator alertlari va incident response keyingi hardening vazifalari. Request ID
orqali loglarni bog‘lash va security/audit DB yozuvlari mavjud.

Boshlang‘ich taklif etilgan sinov: 20 concurrent user, 10 org, har orgda 10 ming
product/100 ming observation; listing/search/comparison va 500-row import.
Read p95 <500ms — faqat qabul maqsadi, o‘lchangan natija emas. Hardware, dataset,
latency/error percentile, query plan va peak memory bilan staging load-test kerak.
