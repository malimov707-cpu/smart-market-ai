# Tahlil va eksportning amaldagi qoidalari

2026-10-31. Corrective sprint mavjud analyticsdagi noto‘g‘ri `MIN(id)` va supplierlar
orasini vaqt o‘zgarishi deb hisoblashni olib tashladi; yangi biznes bosqichi emas.

AnalyticsPrices mavjud CompareOffers/PriceHistory servislaridan foydalanadi.
Latest: observed_at DESC, COALESCE(recorded_at,created_at) DESC, ID DESC; kuzatilgan
va yozilgan vaqt as-ofgacha bo‘lsin. Correction immutable yangi kuzatuv. Latest
expired/rejected/disputed/incompatible bo‘lsa eski arzon quote qaytmaydi.

Bitta supplier + bitta productning latest va oldingi valid normalizatsiyalangan
birlik narxi solishtiriladi. Zero yoki yetishmagan baseline — insufficient_data,
percentage yo‘q. Barcha hisoblar BCMath; turli qadoqlar base unitga, FX esa Phase 3
as-of kursiga keltiriladi. Lowest/highest — joriy eligible UZS/base-unit narxlari,
yetkazishsiz. Bu landed cost emas. Landed-cost winner Phase 3 comparison sahifasida.

Mahsulot o‘zgarishi faqat ikkala baseline’i bor va current quote eligible bo‘lgan
bir xil supplier cohortining minimumlari orasida; cohort IDs ko‘rsatiladi.
Single-observation supplier global minimumga kirishi mumkin, ammo price-change
cohortiga kirmaydi. Supplierlar bir-birining oldingi narxi sifatida olinmaydi.

Dashboard 10 product/sahifa; har product maksimum 500 supplier, history SQL window
querylarda tanlanadi. Relationlar eager-loaded. Har product uchun ochiq hisoblash
querylari bor; page query soni product soni bilan o‘sadi, lekin history soni bilan
PHP hydration o‘smaydi. Constant-time yoki production throughput da’vosi yo‘q.
Query/memory namunalari corrective sprint hisobotida.

`from`, `to` ISO vaqtlar, `to` as-of instant, default 30 kun. Product/supplier filter
IDlari tenant ichida tekshiriladi. Stats current katalog va as-ofgacha saqlangan
tenant kuzatuvlarini bildiradi; mahsulot movement sahifasi alohida filterlanadi.
Price history/trend linki Phase 3ning sahifalangan dalillariga olib boradi.

CSV eksporti 500-row ID batch bilan; review snapshots ham faqat batch uchun.
Oldingi barcha IDlarni RAMga pluck qilish olib tashlandi. Source/product/supplier
matnlari ProductCsv::safeCell bilan whitespace/BOM-prefiksli formulalardan ham
himoyalangan. Org verification filtri exportga qo‘llanadi. Public API yoki cross-tenant
report yo‘q. Real browser CSV download va direct HTTP filter IDOR testlari bor.
