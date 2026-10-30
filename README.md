# Smart Market AI

O‘zbekiston bizneslari uchun tashkilotga ajratilgan bozor narxlari va xarid
ma’lumotlari platformasi. Mavjud katalog/CSV/comparison ustiga identity va
tashkilot boshqaruvi qo‘shildi. **Mahsulotning barcha bosqichlari hali tugamagan;
production-ready deb baholanmaydi.**

## Lokal ishga tushirish

PHP 8.4 (BCMath, GD va SQLite/PostgreSQL PDO), Composer va Node 24 kerak.
Faqat yangi checkout uchun:

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Mavjud `.env` ni almashtirmang, mavjud APP_KEY ni qayta yaratmang.

```sh
npm ci
php artisan migrate
npm run build
php artisan serve
```

Artisan chiqargan manzilni oching. Jonli frontend uchun ikkinchi terminalda
`npm run dev`. Standart foydalanuvchi/parol yoki soxta bozor ma’lumotlari seed qilinmaydi.

Yangi ro‘yxatdan o‘tgan hisob emailni tasdiqlashi shart. Haqiqiy SMTP/API mail
transportini xavfsiz sozlang; log mailerga maxfiy havolalar yozilishi bloklangan.
`.env` ushbu ish sessiyasida o‘zgartirilmadi. SMTP yetkazib berilishi hali tekshirilmagan.

## Ishlaydigan imkoniyatlar

Hisob/profil/parol, email verification, sessiyalarni bekor qilish, o‘z hisobini
faolsizlantirish/qayta faollashtirish; tashkilotni tahrirlash, 5 rol, taklifnomalar
va a’zolik; katalog, yetkazib beruvchi, qo‘lda/CSV takliflari, aniq narx solishtirish
va audit. Barcha boshqaruv amallari serverda ruxsat bilan tekshiriladi.

CSV UTF-8: 2 MB/500 satr; tekshiruv to‘liq tugamaguncha hech narsa saqlanmaydi.
Bir xil yozuv takror kiritilmaydi. Narxlar decimal string/BCMath bilan hisoblanadi.
Yetkazish noma’lum bo‘lsa to‘liq jami deb ko‘rsatilmaydi. Supplier va narx verification scope’lari dalil/mustaqil reviewer bilan alohida boshqariladi.

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

Browser testlari o‘z vaqtinchalik bazasi/serverini yaratadi. Production bazasi uchun
testlarni ishga tushirmang. PostgreSQL test muhiti [testing.md](docs/testing.md) da.
Playwright yangi dev dependency bo‘lib, foydalanuvchi so‘ragan haqiqiy desktop/mobil
E2E uchun qo‘shildi; production runtimega kirmaydi. Chromium alohida yuklanadi.

## Davom ettirish

1–3-bosqichlar katalog, private media, dalilli verification, product CSV hamda bounded/as-of price intelligence bilan implement qilindi. 4-bosqich — yagona Import markazi (`/import-center`): CSV/XLSX/PDF/PNG/JPG yuklash, preview + column mapping, duplicate reconciliation, background joblar (lease/idempotency), AI hujjat ajratish (kredensial bo'lmasa aniq `unavailable`) va inson review workflow. Batafsil: [phase-4-import-and-extraction.md](docs/phase-4-import-and-extraction.md).
AI provayderi hali real kredensial bilan tekshirilmagan; procurement/approval, forecasting, alert/report va ishlab chiqarish infratuzilmasi hali tugamagan. [Roadmap](docs/completion-roadmap.md),
[continuation](docs/continuation-plan.md), [xavfsizlik](docs/security.md),
[deploy](docs/deployment.md) va [haqiqiy tayyorlik holati](docs/production-readiness-report.md).

`@types/node` dev dependency browser/config TypeScript fayllarida Node APIlarini
typecheck qilish uchun qo‘shildi; production runtime dependency emas.

## 2-bosqich imkoniyatlari

/products va /suppliers: tafsilot, edit, archive/restore, filter/sort/pagination.
Mahsulotda barcode, aliases, variant/parent SKU, kategoriya va qadoq metadata.
Private image/PDF boshqaruvi va role/tenant himoyasi. Verification scope’lari
alohida, mustaqil reviewer va saqlangan dalillar bilan. Feedback completed purchase
reytingi emas; uning eligibility va dispute qoidalari hujjatlashtirilgan.
Mahsulot CSV importi /catalog/product-imports: preview, mapping, row errors,
confirm va history; /catalog/products/export formula-safe eksport.
Parent mahsulotni avval kiriting/import qiling, so‘ng variantlarni parent_sku bilan
kiriting. JSON aliases/variant_attributes list bo‘lishi kerak.

[2-bosqich tavsifi](docs/phase-2-implementation.md) va
[jadval/ruxsatlar](docs/database-design.md) amaldagi cheklovlarni tushuntiradi.
Production readiness hali tasdiqlanmagan.

## 3-bosqich imkoniyatlari

Latest/as-of comparison, source/correction tarixi, alohida price review, UZS base
va manbali independently approved USD/EUR/RUB rates, exact landed/unit/nominal
ranking, supplier history/trend va previous-valid price changes. Live rate/OCR/API
integration ulanmagan. [Hisoblash qoidalari va dalil](docs/phase-3-price-intelligence.md).
