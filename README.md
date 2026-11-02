# Smart Market AI

O‘zbekiston bozori bo‘yicha mahsulot narxlarini qidirish va solishtirish platformasi.
Tizimda ikki rol bor: **Admin** barcha katalog, import, tahlil va foydalanuvchilarni
boshqaradi; **User** ommaviy bozor qidiruvi va shaxsiy kuzatuvlardan foydalanadi.
Eski tashkilot yozuvlari o‘chirilmagan, ular Admin uchun global ko‘rinishda qoladi.

## Lokal ishga tushirish

PHP 8.4 (BCMath, GD, SQLite/PostgreSQL PDO), Composer va Node 24 kerak. Faqat yangi
checkout uchun:

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
php artisan migrate
npm run build
php artisan serve
```

Mavjud `.env` faylini yoki `APP_KEY`ni almashtirmang. Interfeysni ishlab chiqish
uchun ikkinchi terminalda `npm run dev` ishlating. Standart admin paroli yoki
soxta bozor narxlari seed qilinmaydi. Birinchi Admin’ni faol hisobga
`php artisan admin:grant email@example.com` orqali bering; Admin uchun email tasdig‘i
talab qilinmaydi. Oddiy User AI rasm qidiruvi va boshqaruv funksiyalaridan oldin
emailini tasdiqlashi kerak.

Mahalliy muhitda bozor kataloglari va boshqa rejalashtirilgan vazifalarni
avtomatik bajarish uchun yana bir terminalda `php artisan schedule:work`ni
ishga tushiring. Aks holda kataloglar oxirgi muvaffaqiyatli yangilangan holatda
qoladi va eskirgan deb belgilanadi.

Kamera API’si `localhost` yoki HTTPS va foydalanuvchi ruxsatini talab qiladi.
Rasm kameradan yoki qurilmadagi JPG/PNG/WebP faylidan olinadi; AI tanish uchun
OpenRouter sozlanishi kerak. Rasm saqlanmaydi. Narxlar AI'dan emas, ko‘rsatilgan
katalog manbalaridan olinadi.

## Imkoniyatlar

- **User:** Korzinka va qurilish manbalari kataloglarini qidirish, narx va trendni
  solishtirish, narx o‘zgarishini kuzatish, rasm/kamera orqali mahsulot nomzodini
  topish, Telegram briefingni ulash.
- **Admin:** global mahsulot va yetkazib beruvchilar katalogi, taklif/import
  boshqaruvi, tahlil va eksport, dalil bilan narx/yetkazib beruvchi tekshiruvi,
  foydalanuvchi va tizim boshqaruvi.
- **Telegram bot:** `/brief` bozor xulosasi, `/status` ulanish holati, `/help`
  buyruqlar va `/stop` uzish. Hisob ulash havolasi bir marta ishlaydi.
- **Google va Telegram login:** Google uchun `GOOGLE_CLIENT_ID` va
  `GOOGLE_CLIENT_SECRET`ni Google Cloud OAuth Web Client’dan oling va
  `APP_URL` qiymatiga `/auth/google/callback` qo‘shilgan manzilni ruxsat etilgan
  redirect URI qilib kiriting. Telegram login uchun BotFather’da botning Login
  Widget domenini sozlang. Telegram email bermagani sabab yangi Telegram
  hisobiga ichki manzil beriladi; email bilan himoyalangan imkoniyatlar uchun
  Hisob sahifasidan haqiqiy email qo‘shib tasdiqlash mumkin.
- Narx hisoblari decimal arifmetikadan foydalanadi; noma’lum yetkazish xarajati
  to‘liq jami deb ko‘rsatilmaydi. Rasm tanish va katalog natijalari taxmin bo‘lishi
  mumkin, shuning uchun manba, qadoq va variantni tekshirish kerak.

Tashkilotga bog‘liq eski ma’lumotlar fizik birlashtirilmagan: har bir yozuvning
`organization_id`i provenance/foreign-key munosabatlari uchun saqlanadi. Yangi
yozuvlar ichki storage partition’da yaratiladi; u foydalanuvchi roli emas.

## Tekshiruv

```sh
php artisan test --compact
npm run typecheck
npm run build
vendor/bin/pint --test
composer validate --strict
composer audit
npm audit --audit-level=high
npx playwright install chromium
npm run test:browser
```

Playwright alohida vaqtinchalik baza/server ishlatadi; production bazasiga test
yurgizmang. Standart E2E porti `8138`; band bo‘lsa `SMART_MARKET_BROWSER_PORT=8140`
kabi bo‘sh port bering. PostgreSQL sinovi va worker tekshiruvlari
[testing.md](docs/testing.md)da.

## Production holati

Mahalliy testlar o‘tishi production tayyorligini anglatmaydi. Haqiqiy SMTP va AI,
HTTPS staging, persistent worker/scheduler, monitoring, shifrlangan backup/restore,
load/security test va rollback rehearsal hali alohida release gate. Kalitlarni
chatga yoki repoga qo‘ymang; `.env` va deploy jarayoni
[deployment yo‘riqnomasi](docs/deployment.md)ga muvofiq boshqarilsin.

Joriy tayyorlik dalillari: [production readiness](docs/production-readiness-report.md),
[operatsion blockerlar](docs/production-operations-sprint.md), va
[keyingi bosqichlar](docs/completion-roadmap.md).
