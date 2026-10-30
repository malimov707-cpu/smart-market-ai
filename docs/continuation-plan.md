# Davom ettirish rejasi

## Sessiya yakuni — 3-bosqich

1–2-bosqich imkoniyatlari saqlandi. 3-bosqich price intelligence funksional scope’i
implement qilindi va ajratilgan muhitlarda tekshirildi:

- Bounded latest quote per tenant/product/supplier, stable observation/recording/ID
  tartibi; yangi qimmat/expired quote eski arzon narx bilan almashtirilmaydi.
- Observation + transaction-time as-of, UTC, subsecond recorded cutoff, append-only
  correction; price/supplier verification va archive/restore history snapshotlari.
- Source metadata, submitter/private evidence, alohida independent price review,
  price.verify platform granti va retirement/replace evidence lock himoyasi.
- UZS base, manbali history/independent approved USD/EUR/RUB rates; missing/expired
  rate unavailable. Live FX API integratsiyasi ulanmagan.
- Exact unit/package/MOQ/excess/delivery/landed calculation; nominal/unit/complete
  ranking alohida; partial quote complete winner bo‘lmaydi.
- Supplier/product history, previous valid changes, paginated trend va Uzbek UI.

Boshlang‘ich qayta baseline PG 139/139 va browser 10/10 edi. Yakuniy PostgreSQL
**175/175, 1273 assertions**; SQLite **162 passed / 13 PG-only skipped, 1257 assertions**;
Chromium desktop/mobil **12/12**. Typecheck/build/Pint/audit/cache tekshiruvlari o‘tdi.
Yangi migration lokal mavjud bazaga qo‘llandi: **8/8 Ran**. .env/key saqlandi; reset yo‘q.

20 000 kuzatuv/20 supplier PostgreSQL EXPLAIN’da yangi index ishladi; PHPga 20 quote,
9 query, 285 744 byte qo‘shimcha peak memory. Bu lokal namuna, production p95/capacity
emas. To‘liq qoidalar va real natijalar phase-3-price-intelligence.md/readinessda.
PG container/browser server/temp muhitlari tozalandi; lokal config/route/view cachelar
clear qilindi. Real CI remote yo‘q, production readiness tasdiqlanmagan.

## Saqlanadigan cheklovlar

Bir supplier/product bitta quote stream; parallel contract stream yo‘q. Global
comparison 500 suppliergacha; limitdan oshsa winner chiqarilmaydi. History/trend
50-row sahifa. DB matching historyni baribir skan qiladi. Eski timestampga mikrosekund
aniqligi ixtiro qilinmaydi. Current membership va profil kontaktlari joriy;
price verification/availability eventlari as-of. Rejected/disputed/expired holatlar
va baseline semantikasini soddalashtirib yubormang.

Review completed-purchase rating emas. Variant parent SKU importdan oldin mavjud
bo‘lsin. Arxiv/evidence/corrections hard-delete qilinmaydi. Platform supplier.verify
va price.verify Ownerga avtomatik berilmaydi. Physical purge/quota/retention va
production backup siyosati alohida vakolatli review talab qiladi.

## Keyingi ish — faqat alohida topshiriqda 4-bosqich

Ushbu sessiyada 4-bosqich boshlanmadi. Keyingi scope uchun:

1. Yangi readiness/architecture/schema va Phase 3 qoidalarini o‘qing; ajratilgan
   PostgreSQL/SQLite/browser baseline’ni qayta tekshiring.
2. Mavjud offer CSV all-or-nothing/dedupe va product CSV preview/mapping/historyni
   saqlang; offer import uchun preview/mapping/row errors/confirm/lifecycle qo‘shing.
3. Original source retention, private access, provenance/task identity, reconciliation,
   idempotency va approved physical retention/quota siyosatini belgilang.
4. Excel parser/OCR scope, supported format, credentials/budget va human confirmation
   talablarini alohida aniqlang. Source metadata mavjudligini extraction bajarildi
   deb talqin qilmang; AI confidence narx tasdig‘i bo‘lmasin.
5. As-of/latest/FX/review/tenant/file regressiyalarini saqlang. Backend/PostgreSQL,
   browser va katta-import failure/retry testlaridan keyin real natijalarni yozing.

5–9: notifications, analytics/demand/forecast, group buy, proposals/approvals.
10–12: controlled AI, jobs/recovery va platform administration. Keyingi bosqichlar
hozir tugallangan deb belgilanmaydi.

## Ochiq tashqi/release gate’lar

Real SMTP delivery, approved live data/FX sources, Git remote/real CI, hosting/TLS/
staging, production private storage/monitoring, encryption/retention/isolated restore,
load/security va rollback rehearsal hali ochiq. Oldingi Phase 2 login timeoutining
sababi tasdiqlanmagan; joriy runlar passed bo‘lsa ham CI/stagingda kuzatilsin.
Secretlarni chatga yubormang. Har sessiyada faqat haqiqiy dalil va qolgan ishni yozing;
.env yoki biznes bazasini reset/replace qilmang.
