# Production tayyorlik hisoboti

Baholash: 2026-10-30, Asia/Tashkent.

**1–3-bosqich funksional imkoniyatlari implement qilingan. Butun mahsulot hali
production-ready yoki release candidate emas. 4–12-bosqichlar tugallanmagan.**

## 1. Haqiqiy scope

1-bosqich: hisob/tashkilot, verification/reset/profile/password/session version,
deactivate/reactivate, 5 rol, invitation/membership va oxirgi faol Owner himoyasi.
2-bosqich: product/supplier CRUD, metadata/variants, private files, dalilli supplier
verification, offer feedback/disputes, product CSV preview/mapping/history/export,
remote lookups. Bular saqlandi; qayta yozilmadi.

3-bosqich: bounded latest quotes, transaction/observation-time as-of, source/correction
traceability, alohida price verification, manbali history/approval FX, exact landed
cost/ranking, previous-valid quote changes va history/trend UI. Excel/API/OCR source
metadata borligi extraction adapteri mavjudligini bildirmaydi. Live FX ulanmagan.

## 2. Narx hisobining chegaralari

Har tenant/product/supplier bitta quote stream. Latest observed_at, recorded_at, ID
DESC; future observation/recording chiqmaydi. Yangi qimmat/expired/rejected quote eski
arzon quote bilan almashtirilmaydi. As-ofga tegishli review va supplier availability
historysi olinadi; profil nomi va supplier tasdig‘i joriy, UI buni ajratadi.

PHPga ko‘pi bilan 500 supplier quote yuklanadi; 501 bo‘lsa validatsiya xatosi va
ranking yo‘q. SQL matching history qatorlarini baribir ko‘radi. History/trend 50 row
sahifalangan; trend faqat sahifadagi nuqtalar, butun tarix emas. Live bozorning barcha
supplierlari yoki parallel contract streams qamrab olingani da’vo qilinmaydi.

## 3. Arxitektura va dependencylar

Laravel 13.39 / PHP 8.4.22, Composer 2.10.3, Node 24.21.0, Inertia Laravel 3.5.2,
React/TypeScript, Tailwind 4/Vite 8. Lock fayllari authoritative. BCMath/GD/PDO talab
qilinadi. Yangi runtime dependency yoki Redis/server qo‘shilmadi. Mavjud Playwright
va Node types dev dependency bo‘lib qoldi. CI PHP extensions ro‘yxatiga GD qo‘shildi.
Boost MCP yo‘q; installed source va rasmiy framework hujjatlari tekshirildi.

## 4. Database va migratsiyalar

`2026_10_30_115437_extend_phase_three_price_intelligence` qo‘llandi:

- offers recorded_at(6), source metadata/submitter/private evidence va correction FK.
- price_reviews: alohida scope/status/actor/reason/evidence/time eventlar.
- exchange_rates: decimal(28,12), source/reference/effective/expiry/recording/approval.
- tenant-qualified correction/evidence FKlar va latest/temporal indekslar.
- audit/supplier verification vaqt aniqligi 6 kasrga kengaytirildi; eski dalil saqlandi.
- PostgreSQL rate positivity/interval/independent-approval CHECKlari.

Lokal mavjud SQLite’da yangi migration **36.32 ms**, jami **8/8 Ran**. Bu production
migration timing emas. Oldingi migrationlar o‘zgarmadi, forward-only down ma’lumotni
hard-delete qilmaydi. Alohida populated-upgrade testi eski narx/reference/time/ID
va FK consistency’ni tekshirdi. Production bazaga deployment bajarilmadi.

.env/key/credentiallar o‘zgartirilmadi; mavjud biznes bazasida reset/replace yoki
soxta price/rate seed bajarilmadi. Git repository/remote yo‘q; commit/Actions dalili yo‘q.

## 5. Fayllar va endpointlar

LatestOffers, CompareOffers, SupplierAvailability, PriceVerification, ExchangeRates,
PriceHistory servislar; PriceReview/ExchangeRate modellar va PriceIntelligenceController.
RecordOffer/MarketRequest provenance/currency/correctionni tekshiradi. File controller
price evidence retirement va parallel review/retire row locklarini qo‘llaydi.

GET /compare optional as_of; GET /prices/{id}; POST /prices/{id}/review;
GET /products/{id}/price-history; GET/POST /exchange-rates;
POST /exchange-rates/{id}/approve. Browser sessions/CSRF/active/verified/membership
himoyasida. Public/versioned API yo‘q. To‘liq inventar route:list/API hujjatida.

Frontend Comparison, PriceObservation, PriceHistory, ExchangeRates, PriceFacts;
offer/product/supplier sahifalaridagi manba/history linklari. Uzbek UI, real data,
calculation/exclusion/missing-cost va insufficient-history holatlari bor.

## 6. Authorization va verification

Owner/Manager/Buyer offer va pending/dispute; Analyst/Auditor read-only. Verified/
rejected qarori independent Ownerga tegishli. Platform price scope alohida price.verify
permission talab qiladi; supplier.verify va Owner roli buni avtomatik bermaydi.
Reviewer offer/pending/evidence muallifi bo‘lmasin; bir xil faol supplier evidence
tekshirilsin. Narx unverified bo‘lib boshlanadi; supplier tasdig‘i meros bo‘lmaydi.

Tarixiy kirish current membership bilan; cross-tenant IDs 404/validation error.
Source va correction related ownership service’da qayta tekshiriladi. Submitting actor,
recorded time va approvals clientdan olinmaydi. Audit va decision history saqlanadi.
AI approval yoki avtomatik real source-authenticity tekshiruvi mavjud emas.

## 7. Moliyaviy qoidalar

UZS base; USD/EUR/RUB uchun real source/reference, bounded effective/expiry va mustaqil
Owner tasdiqlagan tenant rate kerak. Missing/unapproved/expired rate unavailable.
Kech yozilgan/approved rate oldingi as-ofni o‘zgartirmaydi. Live kurs provider yo‘q.

g/kg, ml/l, pcs va explicit package metadata; dimensions aralashtirilmaydi.
Packages ceiling va MOQ, excess, subtotal, known delivery hisoblanadi. Money decimal
strings/BCMath; final landed total half-up 2 kasr, normalized unit half-up 12 kasr.
Intermediate unit ratio avval truncation qilinmaydi. Unknown delivery null, zero emas.

Cheapest nominal (ayni original currency), normalized unit va complete landed cost
alohida. Partial/ineligible quote complete winner bo‘lmaydi. Changes same-cohort
minimum va latest/previous valid normalized quote bilan; yetarli data bo‘lmasa
unavailable. Actual purchases/savings yoki bozor qamrovi ixtiro qilinmagan.

## 8. Backend testlar

Sessiya boshidagi qayta baseline: PG 139/1038 assertions/13.407 s; browser 10/31.8 s.
Yakuniy natijalar:

| Muhit | Yakuniy natija |
| --- | --- |
| PostgreSQL 17 | **175 passed / 1273 assertions / 24.042 s** |
| SQLite :memory: | **162 passed / 13 PG-only skipped / 1257 assertions / 6.782 s** |

Oldingi barcha testlar saqlandi. Eski currency-invalid misoli endi qo‘llab-quvvatlanadigan
USD o‘rniga XXX bo‘ldi; oldingi ranking testi turli supplierlarni haqiqiy alohida
supplierlar bilan tayyorlaydi. Yangi PriceIntelligenceTest, PriceQueryPerformanceTest,
PhaseThreeUpgradeTest va qo‘shimcha PostgreSQL CHECK regressiyalari.

Coverage: expensive latest, stable ties, 3000 history/501 supplier cap, UTC offset,
as-of/future/subsecond corrections va range boundary, source metadata/dedupe,
independent verification/dispute/platform grants, missing/expired/historical rates,
large decimal/rounding, nominal/unit/landed ranking, previous valid change,
five-role/direct-HTTP/guest/tenant/evidence, upgrade va actual DB CHECKlar.

## 9. Browser natijalari

**Chromium desktop + Pixel 7 viewport: 12/12 passed, final run 48.3 s.**

Mavjud 10 test va yangi desktop/mobile Phase 3 workflow: yangi qimmat narx, bir supplier
latest, oldingi as-of, source/details, trend, pending→independent verified, rate
self-approval denial va independent approval. Browser JS errors ham tekshirildi.
Mobil narx/trend sahifasi screenshot orqali vizual ko‘rildi.

DB/storage/cache/seeder OS temp katalogida. Real email yoki production record yo‘q.
Dastlabki yangi test bir summa subtotal/jamida ko‘ringani sabab ambiguous locator bilan
yiqildi; locator .total bilan to‘g‘rilandi. Bu application hisob xatosi emas edi.
Oldingi Phase 2 sessiyasidagi bitta login timeout sababi hanuz tasdiqlanmagan;
joriy baseline va final runlar uning qaytalanmaganini ko‘rsatadi, root cause yechimi emas.

## 10. Build, format, audit va cache

TypeScript app/browser typecheck, production build, Pint fix, Composer validate,
Composer audit va npm high audit bajarildi. Audit paytida advisory/vulnerability yo‘q.
Final Vite build **875 ms / 2625 module**; production latency emas.

Route/config/view cache yaratish va tozalash o‘tdi; lokal cachelar tozalandi.

PHPStan/Larastan hali yo‘q. Real CI remote hali bajarilmagan.

## 11. Performance dalili

20 000 kuzatuvdan 20 quote hydrate qilindi; **9 query / 33.20 ms DB / 51.77 ms
service wall / 285 744 byte qo‘shimcha PHP peak memory**. EXPLAIN planning 0.668 ms,
execution 34.636 ms; offers_latest_lookup Index Scan 20 000 row → WindowAgg 20 row;
11 098 shared-hit block, 0 temp read/write. Alohida EXPLAIN va service execution
namunalari; cold disk yoki production load natijasi emas.

Bu disposable PostgreSQL 17/tmpfs/loopbackdagi 20 000 test observation / 20 supplier
uchun bitta namuna. Query plan va to‘liq qoida phase-3-price-intelligence.md da.
Index ishlatilsa ham matching history qatorlari skan qilinadi; constant-time da’vosi
yo‘q. Concurrency, p95/throughput va production capacity bu testdan kelib chiqmaydi.

## 12. Private storage va imports

Oldingi logical retention/private upload/MIME/GD/png/PDF/authorization saqlandi.
Price source/review reference qilgan fayl retire/replace qilinmaydi. Physical purge,
quota, malware scanning, production object-storage backup/restore hali ochiq.
Product CSV preview/mapping/idempotent confirmation/history/export ishlaydi;
parent SKU oldin mavjud bo‘lsin. Offer CSV originali avvalgidek retained emas;
secure offer import lifecycle/Excel/OCR Phase 4da. Source metadata bu cheklovni yopmaydi.

## 13. Integratsiyalar, AI va jobs

Local DB/private storage faqat tekshirilgan darajada ishlaydi. Real SMTP delivery,
live FX/supplier API/OCR/payment/monitoring integratsiyasi tekshirilmagan yoki ulanmagan.
AI provider/tool/planner/task/budget/approval yo‘q; 0 AI task, autonomy metric yo‘q.
Domain jobs/schedules/outbox/recovery ham keyingi scope. /up faqat application boot.

## 14. Known limitations

500 supplier global comparison cap; paginated history/trend; one stream per supplier.
Legacy timestamps aniq bo‘lmaganidan ortiq tarixiy aniqlik da’vo qilinmaydi. Supplier
contact/name va profil certificationi joriy, narx verificationi tarixiy. Rate source
haqiqiyligi human reviewga bog‘liq; hech qanday avtomatik trusted live feed yo‘q.

Offer feedback completed-purchase performance emas. Current membership requirement
eski revoked a’zoni tarixga qaytarmaydi. Explicit tenant scoping doimiy review talab
qiladi. ru/en to‘liq localization, 2FA/SSO/RLS/external immutable audit/pentest yo‘q.

## 15. Lokal ishga tushirish

Mavjud installation uchun composer install, npm ci, php artisan migrate,
npm run build, php artisan serve. Yangi checkout environment/key qadamlari README’da.
Mavjud .env/APP_KEY almashtirilmasin. Default production user yoki rate yo‘q.
Tests faqat testing.md dagi isolated bazalarda; TestCase file-backed application
bazasini rad etadi. Platform grant vakolatli operator provisioningini talab qiladi.

## 16. CI/CD, staging va recovery

Git remote/real CI, staging/production host/TLS, protected releases, monitoring,
backup encryption/retention/isolated restore, load/security test va rollback rehearsal
bajarilmagan. Runbooklarning mavjudligi ushbu gate’larni yopmaydi. Productionga
migration/deployment bajarilmadi; lokal migration va disposable testlar ajratilgan.

## 17. Tashqi dalil va commercial readiness

SMTP/reset email delivery, real data-source approval va supplier/procurement biznes
jarayonlari alohida tekshiruv talab qiladi. RPO/RTO, peak production memory,
throughput, AI cost/success/autonomy yoki real savings bo‘yicha natija yo‘q.

## 18. Davomi

1–3-bosqichning so‘ralgan scope’i lokal backend/PostgreSQL/browser bilan tekshirildi.
4-bosqich ushbu sessiyada boshlanmadi. Alohida topshiriqda secure offer import,
preview/mapping/lifecycle/retention/Excel/OCR; keyin 5–12-bosqichlar. Aniq davom
rejasi continuation-plan.md da. Production release gate’lar ochiq qoladi.
