# 3-bosqich — narx intelligence

Baholash sanasi: 2026-10-30. Bu hujjat implementatsiya qoidalari va real tekshiruv
dalillarini ajratadi. Production readiness butun mahsulot uchun tasdiqlanmagan.

## Audit va boshlang‘ich baseline

Ish boshida readiness, Phase 2, architecture, schema, routes, Offer/RecordOffer va
CompareOffers ko‘rib chiqildi. PostgreSQL baseline **139 passed / 1038 assertions /
13.407 s**; desktop/mobil Chromium baseline **10 passed / 31.8 s**. Bular shu sessiyada
qayta bajarilgan. Eski engine barcha product tarixini yuklagan, observationlar
orasida latest semantikasi va transaction-time cutoff bo‘lmagan; faqat UZS ishlagan.

## Kuzatuv va vaqt semantikasi

Har organization/product/supplier uchun **bitta quote stream** bor. Source, currency,
qadoq yoki MOQ o‘zgarishi alohida parallel taklif oqimini yaratmaydi. Bir supplier
bir vaqtda turli kontrakt takliflarini taqqoslash kerak bo‘lsa, kelajakda explicit
contract/offer-stream ID talab qilinadi; source_reference yashirin stream ID emas.

Latest tartibi: observed_at DESC, COALESCE(recorded_at, created_at) DESC, id DESC.
Avval observed_at <= asOf va recorded_at <= asOf; keyin ROW_NUMBER rank=1 tanlanadi.
Eng oxirgi narx qimmat, expired, rejected yoki incompatible bo‘lsa ham oldingi
arzon kuzatuv joriy taklif sifatida qaytmaydi. Narxga qarab latest tanlanmaydi.

- observed_at = kuzatilgan vaqt va valid_from; kirishda kelajak rad qilinadi.
- Offer observation/expiry avvalgi schema bilan sekund aniqligida UTCga yoziladi.
- recorded_at yangi yozuvlarda UTC, 6 kasrli sekund; client uni belgilay olmaydi.
- Eski observationlarda recorded_at NULL: avvalgi created_at transaction time
  sifatida ishlatiladi. Eski sekundlardan mikrosekund aniqligi ixtiro qilinmaydi.
  created_at ham yo‘q legacy row tarixiy comparisonga kirmaydi.
- expires_at <= asOf — expired; observed_at < asOf−7 kun — stale. Aynan 7 kunlik
  observation stale emas. Expiry bo‘shligi perpetual price verification emas.
- As-of UTC instantni oladi; ISO offset qabul qilinadi, offsetsiz vaqt UTC deb
  talqin qilinadi. UI Toshkent +05:00 va vaqtni sekundgacha ko‘rsatadi; form/response
  as-of mikrosekundlarini saqlaydi. Kelajak/invalid as-of rad qilinadi.
- Tuzatish yangi immutable observation: correction_of_id + majburiy sabab,
  ayni tenant/product/supplier, observed_at originaldan oldin emas. Original,
  dalil va audit o‘chirilmaydi. Kech yozilgan tuzatish oldingi as-ofni o‘zgartirmaydi.
  Yangi narx originalning verification tasdig‘ini meros olmaydi.
- Price review, supplier suspension va archive/restore tarixlari ham as-ofgacha
  yozilgan eventlardan olinadi. Joriy restore eski archive holatini yo‘qotmaydi.
  Supplier profil nomi/kontaktlari joriy ko‘rsatiladi; tarixiy contact snapshot emas.
  Supplierning profil tasdig‘i narx tasdig‘iga tenglashtirilmaydi.
- Product arxivlangan bo‘lsa joriy compare rad etiladi; tarix sahifasi saqlanadi.
  Authorization har safar joriy membership bo‘yicha: eski membership tarixiy kirish
  huquqini qayta bermaydi.

## Manba va dalillar

source_type: manual, supplier, csv, excel, api, ocr. Legacy source_type NULL bo‘lsa
provenance(manual/csv) fallback. source_identifier, original source_reference,
task_reference, submitted_by, supplier/tenant, observation/recording/expiry va
optional private evidence_file_id saqlanadi. Excel/API/OCR metadata uchun manba ID
va task reference talab qilinadi. **Bu extraction/import adapteri yoki live API
bajarilganligi emas**: tashqarida olingan kuzatuv manbasini hujjatlashtiradi.
Haqiqiy Excel/OCR processing Phase 4 scope’ida.

CSV loader source_type=csv’ni serverda belgilaydi. Manba havolalari serverdan
avtomatik ochilmaydi. Content fingerprint bir xil ma’lumotni qayta yozmaydi;
submitting user almashishi duplicate yaratmaydi, birinchi submitter saqlanadi.
Manbasi/dalili o‘zgargan yangi kuzatuv alohida saqlanishi mumkin. Original source
reference majburiy; legacy submitter noma’lum bo‘lsa UI buni aniq ko‘rsatadi.

## Alohida narx verification lifecycle

Unverified → pending → verified/rejected; pending/verified → disputed;
rejected/disputed → pending. Expired observation uchun derived expired state,
review holati esa append-only tarixda saqlanadi. Expired taklif review qilinmaydi.
Har event scope, actor, sabab, private supplier evidence va vaqtni saqlaydi.

Owner/Manager/Buyer ariza yoki dispute yuboradi; faqat mustaqil Owner qaror beradi.
Reviewer offer submitteri, pending actor yoki evidence uploaderining o‘zi bo‘lmasin;
review aynan pending dalilga tegishli bo‘lsin. Platform scope qo‘shimcha **price.verify**
grantini talab qiladi; supplier.verify yetarli emas, Ownerga avtomatik berilmaydi.
Grant HTTP orqali self-service berilmaydi. AI tasdig‘i mavjud emas.

Pending/unverified narx dolzarb bo‘lsa ogohlantirish bilan solishtiriladi; bu xarid
qarori emas. Har qanday scope’dagi rejected/disputed narx rankingdan chiqariladi.
Tasdiq evidence/reviewer/reason/history tafsilot sahifasida bor. Dalil ishlatilgan
faylni retire/replace qilish taqiqlanadi; oddiy private download tenant policy bilan.

## Valyuta va kurslar

Controlled registry: UZS, USD, EUR, RUB. Base UZS; 1 foreign unit = rate UZS.
UZS uchun konversiya identity, tashqi kurs kerak emas. Boshqa kod rad qilinadi.
Kurs decimal(28,12), price decimal(18,2); barcha hisoblar BCMath decimal string.
Float moliyaviy hisob uchun ishlatilmaydi.

Owner real source va reference, effective_at va expires_at bilan rate kiritadi;
boshqa Owner bir marta tasdiqlaydi. Source dalilining to‘g‘riligi human reviewga
bog‘liq; tashqi registry avtomatik tekshirilmaydi. Rate va source overwrite qilinmaydi;
tuzatish yangi rate yozuvi. Approval actor/time auditda. Rate interval va tasdiq
vaqtlari 6 kasrli sekund aniqligida UTC.

Tanlash: effective_at <= asOf, created_at <= asOf, approved_at <= asOf;
effective_at DESC, created_at DESC, id DESC. Eng so‘nggi approved rate muddati tugasa
eski rate qayta tirilmaydi. Missing/unapproved/expired rate → conversion unavailable,
quote rankingdan tashqarida. Boshqa tenantning kursi ishlatilmaydi.

Original subtotal + ma’lum fee original valyutada hisoblanadi, so‘ng bir marta
UZSga konvertatsiya qilinadi. Yakuniy money half-up 2 kasr, unit price half-up
12 kasr, ichki ko‘paytirish 24 kasr. Live kurs manbasi sozlanmagan va sinovdan
o‘tgan deb da’vo qilinmaydi. Productionga fake rate/price seed kiritilmagan.

## Birlik va landed cost

Controlled unit registry: kg→kg×1, g→kg×0.001; l→l×1, ml→l×0.001; pcs→pcs×1.
Package — explicit package_quantity × unit; masalan 12 pcs bir qadoq. Noma’lum
qadoq metadata kiritishda rad qilinadi; legacy malformed row ineligible. Dimension
mos bo‘lmasa conversion yo‘q. Qo‘shimcha birliklar avtomatik taxmin qilinmaydi.

normalized_package = quantity × unit factor (6 kasr).
packages = max(ceil(requested / normalized_package), MOQ).
supplied = packages × normalized_package; excess = supplied − requested.
subtotal = packages × package_price; known_total = subtotal + known delivery.
Fee original offer valyutasida butun buyurtma uchun. Boshqa soliq/boj/sug‘urta
manbali fieldlari bo‘lmagani sabab taxminan qo‘shilmaydi.

Missing delivery — null; ma’lum subtotal ko‘rsatiladi, **to‘liq jami emas**. Unit
price package_price / normalized_package, yetkazishsiz. Mos, fresh, unexpired,
review-blocksiz, FX mavjud quote eligible; delivery ham ma’lum bo‘lsa complete.

Uchta alohida ko‘rsatkich:

1. Lowest nominal package price — faqat ayni original currency ichida; qadoqlar
   turli bo‘lishi mumkinligi yashirilmaydi.
2. Lowest normalized unit price — eligible quote, UZS/base unit, yetkazishsiz.
3. Cheapest complete landed cost — eligible + delivery known, ayni requested
   quantity uchun UZS. Hech biri to‘liq bo‘lmasa explicit insufficient-data.

Tie-break ID ASC. Sorted result: eligible, delivery known, known_total, ID.
Yashirin savings baseline yoki xarid majburiyati yaratilmaydi. Natija faqat mavjud
shu product/supplier quote’lari orasida, barcha bozorni qamrab olgani emas.

## Tarix, trend va price changes

History filter: product, optional tenant supplier, from (default asOf−30 kun) va
asOf (davr yakuni). Observation/recording cutoff bir xil. 50 row/sahifa, stable
ordering. Har sahifa supplier bo‘yicha alohida trend; 50 nuqta butun tarix sifatida
ko‘rsatilmaydi. Har nuqtaning aniq qiymati va observation IDsi matnda ham bor.

Changes: har supplier uchun as-of latest quote hamda davr ichidagi eng so‘nggi
oldingi **valid, compatible, non-rejected/non-disputed** quote. Current quote
rejected/disputed yoki normalizatsiya yo‘q bo‘lsa insufficient-data. Eski quote’ning
hozir stale/expired bo‘lishi avvalgi observed-price baseline’ni yo‘q qilmaydi;
baseline joriy xarid uchun taklif sifatida qayta ishlatilmaydi.

Turli qadoqlar base unitga normalizatsiya qilinadi; valyutalar **bir xil davr yakuni
as-of kursi** bilan UZSga keltiriladi. Bu constant-FX observation change, tarixiy FX
foyda/zarari yoki realized cost emas. Absolute = latest−previous (12 kasr);
percentage = absolute/previous×100 (4 kasrga truncate, zero baseline unavailable).
Tuzatishlar ham kuzatuv tarixi bo‘lgani sabab change dalillarida original/revision
IDlar mavjud; bu o‘z-o‘zidan bozor harakati deb tasdiqlanmaydi.

Product change faqat ikkala baseline’i bor bir xil supplier cohortidagi normalized
minimumlar orasida hisoblanadi; cohort soni va minimumlar ko‘rsatiladi. Yetarli
baseline bo‘lmasa hisob o‘ylab topilmaydi.

## Database, chegaralar va o‘lchovlar

Yangi forward-only migration offers provenance/correction/recorded_at, price_reviews,
exchange_rates va tenant composite evidence FKlardan iborat. Mavjud timestamp
aniqligi audit/supplier verificationda 6 kasrga kengaytirildi; eski dalil o‘zgarmaydi.
Expression index: tenant, product, supplier, observed_at DESC,
COALESCE(recorded_at,created_at) DESC, ID DESC. SQLite legacy timestamp matnlarini
bir xil 6-kasrli format bilan taqqoslaydi. Rate/review/supplier-state temporal
indexlar ham yangi querylarga mos.

Latest PHP hydration maksimum 500 supplier; 501-supplier aniqlansa hisob validatsiya
xatosi bilan to‘xtaydi, truncated global winner qaytarilmaydi. History 50, review
history 20, rate history 30 row/sahifa. Latest/previous va review/state snapshots
SQL window queries; supplier eager-load, N+1 yo‘q. DB window query tarixdagi matching
qatorlarni baribir ko‘radi: PHP bounded bo‘lishi constant-time DB degani emas.
Katta production history uchun real query plan/load monitoring talab qilinadi.

Yakuniy haqiqiy PostgreSQL EXPLAIN ANALYZE (20 000 observation / 20 supplier):

```text
Limit (rows=20)
  Incremental Sort (supplier_id, observation_rank; rows=20)
    Subquery Scan (rows=20)
      WindowAgg (row_number <= 1; rows=20)
        Index Scan using offers_latest_lookup (rows=20000)
Planning Time: 0.668 ms
Execution Time: 34.636 ms
Shared hit blocks: 11098; temp read/write: 0/0
```

Xuddi shu testning keyingi service execution’i: 9 query, DB time 33.20 ms,
wall 51.77 ms, 285 744 byte qo‘shimcha peak PHP memory, faqat 20 observation hydrate.
EXPLAIN va service alohida execution; bular bitta lokal namuna va warmed/shared
bufferlarga ega. Test va browser bir hostda ishlagan; hardware/production/concurrency
capacity xulosasi yo‘q. Maqsad — bounded hydration, query count va haqiqiy plan.
Full JSON artifact storage/framework/testing/phase3-performance.json da.

## Yakuniy tekshiruvlar — shu sessiya

| Tekshiruv | Natija |
| --- | --- |
| PostgreSQL 17 | 175 passed, 1273 assertions, 24.042 s |
| SQLite in-memory | 162 passed, 13 PG-only skipped, 1257 assertions, 6.782 s |
| Chromium desktop/mobil | 12 passed, final 48.3 s |
| App/browser TypeScript | Passed |
| Production Vite build | Passed, 875 ms, 2625 modules |
| Pint, Composer validate, Composer/npm audits | Passed; execution paytida advisory/vulnerability yo‘q |
| Lokal additive migration | 36.32 ms; jami 8/8 Ran |
| Config/route/view caching | Yaratish/tozalash passed; cachelar tozalandi |

Testlar eski 139 backend va 10 browser oqimini saqlaydi. Invalid-currency testi
qo‘llab-quvvatlanadigan USD o‘rniga XXXni, ranking fixture esa alohida supplierlarni
ishlatadi. Yangi regressiyalar cutoff/tie/dedupe/large-history/501-cap/source/evidence/
review/platform/FX/rounding/landed/change/tenant/role/guest/upgrade va real CHECKlarni
qamraydi. Same-second yozuv va lower date-range boundary ham tekshirildi.

Dastlabki yangi browser lokatori subtotal/jami bir xil bo‘lganda ambiguous edi;
aniq .total maydoniga bog‘landi, keyingi full runlar passed. PostgreSQL’da rate
expiry sekundga rounding qilinishi aniqlandi va yangi rate timestamp’lari 6 kasrga
kengaytirildi. Expired label oldingi rejectionni analyticsda yashirmasligi testlandi.
Price evidence retire/replace va review file row lock bilan serializatsiya qilinadi;
DB-level concurrent stress/pentest alohida o‘tkazilmagan.

Vaqtinchalik PG container to‘xtatildi/--rm qilindi, browser server/temp DB/storage
teardown bo‘ldi; 8137 portda listener yo‘q. .env/key/secretlar saqlandi, mavjud biznes
bazasi reset/replace qilinmadi. Production fake rate yoki price seed qilinmadi.
Git remote/CI, real SMTP/live FX, hosting/TLS/monitoring/backup/restore va load/security
release gate’lari ochiq. 4-bosqich implementatsiyasi boshlanmadi.

## Rasmiy texnik tayanch

[Laravel 13 query builder](https://laravel.com/framework/docs/13.x/queries),
[HTTP tests](https://laravel.com/framework/docs/13.x/http-tests),
[Inertia 3 forms](https://inertiajs.com/docs/v3/the-basics/forms),
[PostgreSQL 17 indexes](https://www.postgresql.org/docs/17/indexes.html).
Boost MCP search-docs ushbu sessiyada mavjud emas; o‘rnatilgan source va rasmiy
hujjatlar tekshirildi. Yangi runtime dependency kiritilmadi.
