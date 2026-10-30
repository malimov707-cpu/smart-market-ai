# 2-bosqich: mahsulotlar va yetkazib beruvchilar

## Audit va qarorlar

Mavjud create/search/pagination, product archive, offer tarixlari saqlanadi.
Yangi additive migratsiyalar; Phase 1 qayta yozilmaydi. Bazani reset qilish va
.env o‘zgartirish yo‘q. Bootstrap hujjati yangi ishning dalili deb olinmaydi.

Kategoriya mavjud products.category orqali boshqariladi; ikkinchi parallel
kategoriya ustuni yaratilmaydi. Variant — mustaqil SKUli product, tenant-qualified
parent bog‘lanishi va attributes. Bir xil product ID comparison talabi saqlanadi.
Barcode har tashkilot doirasida unique, bo‘sh bo‘lsa null; aliases bounded array.

Supplier verification tashkilotga tegishli va platforma tekshiruvini ajratadi.
Owner faqat organization scope’ni ko‘radi/tasdiqlaydi. Platform scope alohida
supplier.verify permission talab qiladi; u Owner bo‘lgani uchun avtomatik berilmaydi.
Evidence, reason, reviewer va status history saqlanadi; self-approval taqiqlanadi.

Review: haqiqiy xarid moduli hali yo‘q. Shuning uchun review faqat faol Owner,
Manager yoki Buyer tomonidan, haqiqiy saqlangan supplier offeriga dalil bilan
bir marta yoziladigan **offer engagement** bahosi; completed purchase rating emas.
Bitta user/supplier uchun unique. Rating 1–5, fikr va offer dalili shart. Reliability
kamida 3 turli author va 3 ko‘rinadigan review bo‘lganda o‘rtacha; aks holda
ma’lumot yetarli emas. Purchase completion/savings hisoblanmaydi.

Review muallifi yoki catalog boshqaruvchisi dispute ochadi; faqat Owner boshqa
muallifning disputeni reason bilan resolve qiladi. Ochiq dispute paytida baho
reliabilitydan chiqariladi. Moderation history auditda saqlanadi.

Fayllar: private catalog disk, randomized paths, MIME/extension/size tekshiruvi,
rasm decode/re-encode, document attachment download, tenant authorization va audit.
O‘chirish/replacement logical retirement; originallar audit uchun saqlanadi va
foydalanuvchiga qayta berilmaydi. Verification evidence reference qilgan faylni
retire qilish bloklanadi. Physical purge avtomatik emas: retention/legal approval
alohida operator jarayoni; backup production gate ochiq.

CSV: private preview batch, mapping, har satr validatsiyasi/xatolari, explicit
confirm, atomic create-only import, idempotent confirm, history. Existing SKUni
update qilmaydi; exact duplicate alohida ko‘rsatiladi. Formula-safe eksport.

## Ish ketma-ketligi

1. Baseline SQLite/PostgreSQL/browser qayta tekshirish.
2. Katalog CRUD/metadata/archive/restore + test.
3. Private media + supplier verification/review/dispute + test.
4. Product CSV va remote lookup + test.
5. UI/browser, regressiya va hujjatlarni haqiqiy natijalar bilan yakunlash.

3-bosqichga ushbu scope tekshirilmaguncha o‘tilmaydi. Yakuniy natijalar quyida qayd etilgan.

## Yakuniy holat — 2026-10-30

2-bosqichning so‘ralgan funksional scope’i implement qilindi va quyidagi muhitlarda
tekshirildi. Bu butun mahsulot production-ready bo‘ldi degani emas.

- Product: create/read/update/archive/restore; kategoriya, barcode, aliases, mustaqil
  SKUli variant, parent/unit consistency, package metadata va private images.
- Supplier: CRUD/archive/restore, profile/contact, filter/sort/pagination, documents,
  organization/platform verification, evidence/reviewer/reason/time/status history.
- Reviews/disputes: offer-backed eligibility, bir author uchun bitta fikr, independent
  moderation va izohlangan feedback aggregate. Completed purchase/savings yo‘q.
- CSV: UTF-8 preview/mapping/row errors/duplicate conflicts/explicit atomic confirm,
  idempotent replay/history/error export va formula-safe metadata export.
- Lookups: product va supplier uchun 20-row remote search; 500-item limit yo‘q.
- Role/tenant/file authorization va audit serverda; JSON array/list shakli tekshiriladi.

### Haqiqiy tekshiruvlar

| Tekshiruv | Natija |
| --- | --- |
| PostgreSQL 17 | 139 passed, 1038 assertions, 16.207 s |
| SQLite in-memory | 130 passed, 9 PostgreSQL-only skipped, 1029 assertions, 7.964 s |
| Chromium desktop + Pixel 7 viewport | 10 passed, yakuniy rerun 30.5 s |
| TypeScript app + browser/config | Passed |
| Vite production build | Passed, 694 ms, 2621 module |
| Pint, Composer validate, Composer/npm audit | Passed; audit paytida advisory/vulnerability yo‘q |
| Lokal migration | Yangi migratsiya bajarildi, jami 7/7 Ran |
| Route/config/view cache | Yaratish/tozalash o‘tdi, lokal cachelar tozalandi |

PostgreSQL disposable container/tmpfs/loopback portda ishladi va to‘xtatildi.
Browser DB, storage va config/route/event cachelari OS temp katalogida ajratildi.
Test guard file-backed application bazasini rad etishi va faylni o‘zgartirmasligi
child-process testi bilan tasdiqlandi. .env, secretlar va eski biznes yozuvlari
almashtirilmadi; database reset faqat frameworkning alohida test bazasida bajarildi.
Eski migratsiyalar tahrirlanmadi, yangi migratsiya forward-only.

### Aniqlangan muammolar va chegaralar

Remote lookup/image requestlar session previous URL va flashni iste’mol qilishi
aniqlandi va regressiya testlari bilan tuzatildi. Fixed sidebar overflow sabab
logout viewportdan tashqarida qolishi tuzatildi. Browser testlari Inertia sahifa
almashishini kutadigan qilindi; forma latest qiymatlarini DOM orqali yuboradi.

Bitta oldingi browser run’da boshlang‘ich login request timeout bo‘ldi. O‘zgarmagan
kod bilan yakuniy to‘liq rerun 10/10 o‘tdi; timeout sababi tasdiqlanmagan. CI/stagingda
kuzatish kerak; bu holat yashirilmagan va production latency natijasi deb olinmaydi.

CSV parent mahsuloti avval saqlangan bo‘lsin; yangi parent+child bir batchda
avtomatik bog‘lanmaydi. Formula-safe apostrof matn ko‘rinishini o‘zgartirishi mumkin.
Verification hujjatlari haqiqiyligiga tashqi registry API tekshiruvi ulanmagan;
qaror vakolatli insonning hujjatlashtirilgan review’iga tayanadi. Platform grant
productionda alohida vakolatli operator provisioningini talab qiladi.
Physical fayl purgesi avtomatik emas; retained originallar uchun quota/retention,
malware scan, production backup va restore alohida release gate’lar.

3-bosqich boshlanmadi. Davomi docs/continuation-plan.md da.
