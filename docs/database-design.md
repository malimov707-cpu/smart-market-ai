# Ma’lumotlar bazasi

Production maqsadi — PostgreSQL 17; lokal ilova mavjud SQLite bazasida ishlaydi.
UTC saqlash va Toshkent vaqti bo‘yicha ko‘rsatish saqlangan.

## Mavjud jadvallar

`users`, `sessions`, `password_reset_tokens`: Laravel identity infratuzilmasi.
`organizations` va `organization_user`: tashkilot hamda composite kalitli a’zolik.
`products`: tashkilotga xos unique SKU, birlik va arxiv vaqti.
`suppliers`: tashkilotga tegishli profil, kontakt va shahar.
`offers`: immutable kuzatuv, decimal(18,2) pul, decimal(12,3) qadoq, MOQ,
yetkazish, observation/expiry, manba va tenant-unique fingerprint.
`audit_logs`: actor/organization/action/subject va nullable metadata.
`jobs`, `failed_jobs`, cache jadvallari: framework infratuzilmasi.

## Identity migratsiyasi

`2026_10_29_215043_extend_identity_and_organization_management`:

- users.is_active: default true, eski hisoblarni saqlaydi.
- users.security_version: default 0, barcha backend sessiyalari uchun bekor qilish.
- users.locale: default uz, tanlov uz/ru/en; to‘liq tarjimalar hali yo‘q.
- organization_user.role: owner/manager/buyer/analyst/auditor.
- audit_logs.metadata: nullable JSON; eski yozuvlar o‘zgarmaydi.
- invitations: tashkilot, taklif yuboruvchi, email, rol, token_hash, expires_at,
  accepted_at/revoked_at va timestamps. (organization_id,pending_email) unique.
- security_events: user/action/created_at, maxfiy payloadsiz.

PostgreSQL’da role CHECK kengaytiriladi. SQLite’da Laravel jadvalni qayta qurib
rol cheklovini yangilaydi; eski user/a’zolikni saqlash alohida upgrade testi bilan
tekshirilgan. Mavjud migratsiyalar tahrirlanmadi. Ushbu yangi migratsiyaning down()
amali ma’lumot yo‘qotish o‘rniga xato qaytaradi: productionda forward fix qo‘llang.

Offer price/package/MOQ/delivery va expiry CHECK cheklovlari haqiqiy PostgreSQL
integratsiya testlarida tekshirildi. SQLite testlari bu tekshiruvni almashtirmaydi.
Foreign keylarda restrictOnDelete audit va biznes tarixini saqlaydi; account
deactivate va membership remove user yoki narxlarni o‘chirmaydi.

## 2-bosqich migratsiyasi

`2026_10_29_220901_extend_phase_two_catalog` — additive, forward-only:

- products: description/barcode, aliases JSON list, parent_product_id composite
  self-FK, variant_attributes JSON list, package_quantity/unit, tenant barcode unique,
  organization/archived/name indeksi. Mavjud category qayta ishlatiladi.
- suppliers: description/email/archived_at; verification_status va
  platform_verification_status (unverified/pending/verified/rejected/suspended).
- platform_permissions: (user_id,permission) composite key; supplier.verify alohida.
- catalog_files: tenant/product yoki supplier, uploader, original nom, private path,
  MIME/size/retired_at va timestamps; path API javobidan yashirilgan.
- supplier_verifications: scope, old/new state, actor, reason, evidence_ids, timestamps.
- supplier_reviews: user/supplier/offer, rating/body/hidden_at; tenant/supplier/user unique.
- supplier_disputes: bir review uchun bitta dispute, opener/reason/resolver/resolution/time.
- product_imports: headers/rows/mapping/summary, creator, expiry/completed timestamps.
- offers: composite (organization_id,id) unique indeks, review FK uchun.

PostgreSQL CHECK: package pair, self-parent taqiqi, faylning aynan bitta egasi,
review rating 1–5. Tenant FKlar yangi parent/file/review bog‘lanishlarida saqlangan.
Eski products/suppliers/offers’ni saqlash uchun alohida SQLite upgrade testi bor.
Physical source fayllari va biznes yozuvlari hard-delete qilinmaydi.

## 3-bosqich migratsiyasi

`2026_10_30_115437_extend_phase_three_price_intelligence` — forward-only qo‘shimcha:

- offers: recorded_at timestamp(6) nullable, source_type/identifier/task_reference,
  submitted_by, evidence_file_id, correction_of_id/reason; source_type NULL bo‘lsa
  eski provenance, recorded_at NULL bo‘lsa eski created_at ishlatiladi.
- Self correction va private evidence uchun organization-qualified composite FK;
  catalog_files (organization_id,id) unique. Supplierga moslik service’da ham tekshiriladi.
- offers_latest_lookup expression indeksi: organization/product/supplier,
  observed_at DESC, COALESCE(recorded_at,created_at) DESC, id DESC. SQLite legacy
  sekund matni 6 kasr bilan normallashtiriladi. Oldingi indekslar o‘chirilmagan.
- price_reviews: offer/tenant/actor, organization/platform scope, status,
  reason, evidence_file_id, created_at(6). Status append-only; expired derived.
- exchange_rates: tenant/currency, decimal(28,12) rate, source/reference,
  effective_at/expires_at/created_at/approved_at(6), submitter va independent approver.
- PostgreSQL: rate > 0, expiry > effective, approver != submitter CHECKlari.
- Audit va supplier verification timestamp aniqligi 6 kasrga kengaytirildi va
  temporal indexlar qo‘shildi. Eski timestamp/dalillarni ixtiro qilib backfill qilmaydi.

Moliyaviy price, package, fee va original observationlar tahrirlanmaydi.
Correction/rate change yangi row; approval faqat bir marta serverda yoziladi.
SQLite populated-upgrade testi original narx/reference/ID/time saqlanishini hamda
foreign_key_check natijasini tekshiradi. Domain table down() history yo‘qotish
amalini bajarmaydi; reviewed forward fix ishlating.
## 4-bosqich: import markazi jadvallari

- `import_batches`: organization, user, type(products/suppliers/offers),
  format(csv/xlsx/pdf/png/jpg), file_name/path/mime/size/sha256, idempotency_key
  (unique, org ichida), status/stage/generation, lease_token/lease_expires_at,
  sheets/headers/mapping/header_row/timezone, row/error counts, timestamps,
  duplicate_policy, scan_status, expires_at (retention), reviewed_by/reviewed_at,
  purged_at, attempts/error_code.
- `import_rows`: batch+row_number unique, raw JSON, data/overrides/errors/extraction
  (confidence/page/region/method)/match JSON, status (pending..failed), result_type/id.
- `import_events`: append-only audit (actor, action, from/to status, metadata).
- `extraction_attempts`: request_key unique (idempotent AI chaqiruv), provider/model,
  status/error_code, transport_attempts, input/output tokens, cost/currency.
- Migratsiya `preserve_sqlite_exact_financial_values`: SQLite'da yangi yozuvlar uchun
  exact matn qiymat ustunlari (PostgreSQL NUMERIC aniqligi saqlanadi; eski qiymatlar
  backfill qilinmaydi).
