# HTTP marshrutlari

Hozir cookie session va CSRF asosidagi Inertia browser marshrutlari mavjud.
Public/versioned token API hali yo‘q. Aniq ro‘yxat: `php artisan route:list --except-vendor`.

## Identity

- GET/POST /register va /login; POST /logout.
- GET/POST /forgot-password; GET /reset-password/{token}; POST /reset-password.
- GET /verify-email; GET /verify-email/{id}/{hash} (signed);
  POST /email/verification-notification.
- GET /account.
- POST /account/profile: name,email,locale,current_password.
- POST /account/password: current_password,password,password_confirmation.
- POST /account/sessions/revoke: current_password.
- POST /account/sessions/{key}/revoke: current_password; faqat o‘z database sessiyasi.
- POST /account/deactivate: current_password.
- POST /account/reactivate: email,password; guest, rate-limit bilan.

## Tashkilot

- POST /organization: organization_id; faqat mavjud a’zolik.
- GET /settings; POST /settings: name,city, faqat Owner.
- POST /settings/invitations: email,role; faqat Owner.
- POST /settings/invitations/{invitation}/revoke.
- GET /invitations/{token}; POST /invitations/{token}/accept.
- POST /settings/members/{member}/role: role.
- POST /settings/members/{member}/remove.

Taklif qabul qilish tanlangan tashkilotga bog‘liq emas; recipient email tasdiqlangan
bo‘lishi kerak. Orgga oid boshqaruv amallari tanlangan tashkilot bilan scope qilinadi.

## Bozor

GET /dashboard, /products, /suppliers, /offers, /comparison, /imports, /ai.
POST /products, /suppliers, /offers; POST /products/{product}/archive.
GET /compare?product_id=…&quantity=…; POST /imports; GET /imports/template.
GET /up hozircha faqat bootni tekshiradi.

Ma’lumot validatsiyasi Inertia form errors orqali qaytadi. Begona tenant ID — 404,
ruxsat yo‘q — 403, eskirgan/bekor taklifnoma — 410, rate-limit — 429. Guest
himoyalangan sahifadan login sahifasiga yo‘naltiriladi. Xom token/sessiya ID
ro‘yxat APIlari orqali qaytarilmaydi.

## 2-bosqich marshrutlari

- GET /products/{product}; POST /products/{product}; POST /products/{product}/restore.
- GET /suppliers/{supplier}; POST /suppliers/{supplier}; POST .../archive va .../restore.
- GET /catalog/products/lookup va /catalog/suppliers/lookup: q/page, 20 tadan natija.
- POST /products/{product}/images va /suppliers/{supplier}/documents: multipart file,
  ixtiyoriy replace_id. GET /catalog/files/{file}; POST .../{file}/retire.
- POST /suppliers/{supplier}/verification: scope,action,reason,evidence_ids[].
- POST /suppliers/{supplier}/reviews: offer_id,rating,body.
- POST /suppliers/{supplier}/reviews/{review}/dispute: reason.
- POST /suppliers/{supplier}/disputes/{dispute}/resolve: resolution,hide_review.
- GET/POST /catalog/product-imports (history/upload); GET .../{import} preview;
  POST .../{import}/mapping; POST .../{import}/confirm; GET .../{import}/errors.
- GET /catalog/products/export: formula-safe CSV, tenant ichidagi metadata.

Import mapping name/sku/unit va ixtiyoriy category/description/barcode/package_quantity/
package_unit/aliases/variant_attributes/parent_sku’ni header indekslariga bog‘laydi.
JSON metadata list bo‘lsin; parent SKU avval mavjud bo‘lsin. Confirm butun batchni
qayta validatsiya qiladi; existing SKU o‘zgartirilmaydi, conflicts bloklanadi.
Sort maydonlari product uchun name/sku/created_at, supplier uchun name/city/created_at;
direction asc/desc. Status active/archived/all, supplier verification filter organization
scope’ga tegishli. Qoidalar ProductRequest/SupplierRequest va policy/servislarda.

## 3-bosqich browser/session marshrutlari

- GET /compare?product_id=…&quantity=…&as_of=… — optional ISO as-of.
- GET /prices/{offer} — calculation, provenance, correction, private evidence va review tarixi.
- POST /prices/{offer}/review — scope/status/reason/evidence_file_id; backend independent review.
- GET /products/{product}/price-history?from=…&as_of=…&supplier_id=… — 50-row history/trend/change.
- GET/POST /exchange-rates — 30-row history yoki Owner source/rate/interval submission.
- POST /exchange-rates/{rate}/approve — boshqa Ownerning bir martalik approval’i.

Barchasi web session/CSRF/active/verified/tenant himoyasi ichida; public API emas.
Offer create qo‘shimcha source_type, source_identifier, task_reference, evidence_file_id,
correction_of_id/reasonni qabul qiladi; UZS/USD/EUR/RUB registry. Writer/tenant,
submitter/recording/approval qiymatlari server tomonidan tekshiriladi.
