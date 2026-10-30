# Arxitektura

Laravel 13.39 / PHP 8.4.22 modular monolit saqlandi. Frontend React 19,
TypeScript, Inertia 3, Tailwind 4 va Vite 8. Lock fayllari aniq versiyalar manbasi.
BCMath pulni floating point ishlatmasdan hisoblaydi. Redis yoki yangi backend talab
qilinmaydi; vaqtinchalik Docker faqat PostgreSQL testlarini ajratish uchun ishlatildi.

## Chegaralar

Controller request/response’ni boshqaradi; FormRequest validatsiya va authorization,
OrganizationPolicy ruxsat matritsasi, ManageMemberships a’zolik transactionlari,
AccountSecurity sessiya bekor qilish, RecordOffer/ImportOffers ma’lumot kiritish,
CompareOffers deterministik hisoblarni bajaradi.

EnsureActiveAccount yangi DB holati va security_version’ni tekshiradi. Verified
middleware tasdiqlanmagan hisobni /verify-email ga yuboradi. ResolveOrganization
sessiondagi IDni joriy a’zolik bilan tekshiradi; revoked selection fail-closed.
/account tanlangan tashkilotsiz ham ochiladi va boshqa mavjud orgga o‘tishga imkon beradi.

Tenant ID request payloadidan olinmaydi. Offer composite FK va tenant-qualified
querylar izolyatsiyani mustahkamlaydi. RLS/global tenant scope qo‘llanmagan; yangi
querylar review qilinishi shart. Platforma administration UI hali yo‘q; supplier review uchun alohida platform permission 2-bosqichda qo‘shildi.

## Takliflar va hisoblash

Faqat bir xil product ID solishtiriladi. SKU bir xil brend/nav/variantni aniq
ifodalashi kerak. g→kg va ml→l aniq normalizatsiya; pcs massaga aylantirilmaydi.
Packages = ceil(quantity / normalized package), kamida MOQ. Jami = packages ×
package price + ma’lum delivery. Base UZS; manbali va mustaqil tasdiqlangan tarixiy kurs bo‘lsa USD/EUR/RUB konversiyasi mavjud.

7 kundan eski yoki tugagan kuzatuv ineligible. Saralash: eligible, delivery known,
known total, ID. Noma’lum fee partial deb belgilanadi. Supplier unverified holati
freshness orqali o‘zgarmaydi. Summa ko‘rinishi brauzer locale fallbackiga bog‘liq
emas va float ishlatmaydi; sanalar Toshkent vaqtida raqamli ko‘rsatiladi.

Comparison har tenant/product/supplier uchun as-ofgacha kuzatilgan va yozilgan latest observationni oladi; tanlash observed_at, recorded_at, ID DESC. PHPga maksimum 500 supplier kuzatuvi yuklanadi; limitdan oshsa ranking rad qilinadi. Katalog remote search cheklovi 2-bosqichda olib tashlandi.

## Identity va audit

Invitation token faqat hash sifatida saqlanadi; 7 kunlik, bir martalik, recipient va
inviterning joriy ruxsati tekshiriladi. Role o‘zgarishi oldin/keyin metadata bilan,
account amallari security_events bilan qayd qilinadi. User va organization locks
oxirgi faol Owner invariantini saqlaydi. Yangi migratsiya forward-only; destructive
rollback yo‘q. Account deactivation tarixni o‘chirmaydi.

## Import/operatsiyalar

Offer CSV 2 MB/500 satr: exact header, validatsiya, transaction va fingerprint.
Original offer fayli saqlanmaydi; offer preview/history va Excel/OCR keyingi
bosqichlarda. Product CSV preview/mapping/history 2-bosqichda qo‘shildi.
Domain job/schedule yoki AI tools hali yo‘q. /up faqat boot; dependency probes
hardeningda kerak. Request ID/route/status/duration loglanadi, payload/token yo‘q.

Rasmiy tayanch hujjatlar: [Laravel 13 verification](https://laravel.com/docs/13.x/verification),
[auth](https://laravel.com/docs/13.x/authentication),
[migrations](https://laravel.com/docs/13.x/migrations),
[Inertia 3 formalar](https://inertiajs.com/docs/v3/the-basics/forms).

## 2-bosqich: katalog va supplierlar

CatalogController/ProductRequest/SaveProduct mahsulot tafsiloti, update, metadata,
variant va archive/restore’ni boshqaradi. Kategoriya avvalgi products.category’da
saqlanadi; qo‘shimcha parallel kategoriya jadvali yaratilmagan. Barcode tenant ichida
unique (8–14 raqam), variant mustaqil SKU va composite parent FK bilan ishlaydi.
Offer tarixi bor mahsulotning birlik/variant identifikatsiyasi o‘zgarmaydi.

SupplierController/SupplierTrust: tashkilot va platform verification scope’i
alohida; faqat tegishli vakolatli boshqa reviewer dalil va sabab bilan qaror beradi.
platform_permissions alohida grant; Ownerga avtomatik berilmaydi. Platform review
ham private supplier profiliga tegishli, boshqa tenantni ko‘rish vakolati emas.
Reviews faqat bazadagi offerga asoslangan ichki feedback; completed purchase emas.
3 ko‘rinadigan author’dan kam bo‘lsa o‘rtacha unavailable. Dispute mustaqil Owner
qarori bilan hal qilinadi; ochiq/yashirilgan feedback aggregate’dan chiqariladi.

CatalogFileController va private catalog disk rasm/PDFlarni random nomda saqlaydi.
Rasmlar GD bilan PNGga qayta kodlanadi. Retire/replacement source’ni overwrite
qilmaydi; dalil sifatida ishlatilgan hujjat retirementdan himoyalangan. Physical
purge avtomatik emas; retained original’lar production retention/backup siyosatini
talab qiladi. Lokal storage tekshiruvi production object-storage dalili emas.

ProductImportController/ProductCsv: 500-row/2-MB UTF-8 preview, mapping, row errors,
explicit atomic/idempotent confirm va history. Parent SKU avval alohida saqlangan
bo‘lsin. Aliases/variant_attributes JSON list. Eksport formula-safe va 500-row DB
batchlari bilan ishlaydi. Preview SKU/barcode’ni batch querylarda tekshiradi.

Katalog/supplier lookuplari auth/tenant ichida 20 tadan sahifalanadi; first-500
cheklovi olib tashlandi. Query sort/direction allowlist. CatalogSession yordamchi
lookup/media/download so‘rovlarining previous URL va flash xabarlarni buzishini
oldini oladi; Inertia GET sahifasi validatsiyaning qaytish nuqtasini yangilaydi.

Tayanch: https://laravel.com/docs/13.x/filesystem va
https://inertiajs.com/docs/v3/the-basics/file-uploads .

## 3-bosqich: price intelligence

LatestOffers window query va expression index bilan history hydrationni chegaralaydi.
CompareOffers faqat latest snapshotni hisoblaydi; PriceHistory oldingi valid quote,
normalized change va same-cohort product minimumlarini chiqaradi. As-of observation
va transaction vaqtini birga cheklaydi; later correction originalni overwrite qilmaydi.
PriceVerification — supplier verificationdan alohida append-only ikki-scope lifecycle.
SupplierAvailability arxiv/restore/suspension eventlarini as-of vaqtida tiklaydi;
profil nomi va supplier tasdig‘i joriy ma’lumot sifatida ko‘rsatiladi.

ExchangeRates organizationga tegishli manbali rate va independent Owner approvalni
boshqaradi. 1 foreign unit = rate UZS; yakuniy summa half-up 2, unit price half-up
12 kasr, BCMath. Kurs/evidence yetishmasa unavailable. Actual live FX/API/OCR adapter
ulanmagan. Source metadata extraction bajarilganligi dalili sifatida talqin qilinmaydi.

PriceIntelligenceController details/provenance/review/history/rates sahifalarini
boshqaradi. Har query current membership va tenantga bog‘liq. File retire/replace
hamda price review bir xil evidence row lock bilan serializatsiya qilinadi.
Barcha vaqt, ranking, baseline va performance qoidalari phase-3-price-intelligence.md da.
## 4-bosqich: import markazi va AI ajratish

`ManageImports` yagona hayotiy sikl boshqaradi (uploaded → validating → preview_ready
→ awaiting_confirmation → queued → processing → completed/completed_with_errors/failed
→ cancelled). Fayl private `catalog` diskda tasodifiy UUID nom bilan saqlanadi;
satrlar va xatolar `import_rows` da, har bir holat o'tishi `import_events` da.
CSV/XLSX `SpreadsheetReader` orqali o'qiladi (formula/macro/external-link/unsafe-path/
zip-bomb rad etadi). PDF/PNG/JPG uchun `HttpDocumentExtractor` vendor-neytral HTTPS
gatewayga structured output so'raydi; `ExtractionSchema` confidence/page/region/usage
qiymatlarini qat'iy validatsiya qiladi. Kredensial yo'q bo'lsa ajratish aniq
`provider_unavailable` bilan ishlamaydi — fake javob yo'q. `PrepareImport`/
`CommitImport` joblari DB lease + token fence bilan retry-safe; narx yozuvlari
tranzaksiya ichida, AI chaqiruvi tranzaksiyadan tashqarida. XLSX uchun tashqi paket
o'rnatilmadi: cheklangan parser ZIP/XML kengaytmalari bilan qurilgan.
Batafsil: phase-4-import-and-extraction.md.
