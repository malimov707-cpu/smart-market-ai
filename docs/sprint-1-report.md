# Sprint 1 — 4, 5 va 6-bosqichlar (Import, AI hujjat intellekti, Bildirishnomalar, Tahlil)

Sana: 2026-10-30. Holat: Sprint 1 ning asosiy funksional yadrosi implement qilindi va testlandi.

## 1. Sprint 1 umumiy ko‘rinishi

Ushbu sprintda uch modul bitta integral siklda ishlab chiqildi:

- **4-bosqich (Import + AI hujjat intellekti):** avvalgi sessiyalarda yadrosi tayyor edi (yagona import lifecycle, CSV/XLSX parser, cheklangan XLSX o‘qish, AI extraction provayder abstraksiyasi, inson tekshiruvi). Bu sprintda regressiya tekshirildi va buzilishsiz saqlandi.
- **5-bosqich (Bildirishnomalar va aqlli ogohlantirishlar):** yangi implement qilindi — `alert_rules` va `app_notifications` jadvallari, `ManageAlerts` servisi, narx pasayishi/oshishi/yangi taklif/import holatlari bo‘yicha event dispatch, idempotent dedupe, halol email holati.
- **6-bosqich (Tahlil va hisobotlar):** yangi implement qilindi — haqiqiy ma’lumotlardan hisoblanadigan analitika dashboard (`/analytics`), narx harakati, yetkazib beruvchi faolligi, yaqin importlar, va formula-injection’dan himoyalangan CSV eksport (`/analytics/export`).

## 2. 4-bosqich natijalari — holat: Implemented (oldingi sessiyalar), Verified (regressiya)

- CSV/XLSX import: preview, mapping, xato satrlar, tasdiqlash, bekor qilish, retry, idempotent commit — mavjud va testlangan.
- PDF/JPG/PNG/WEBP AI extraction: provayder sozlanmagan bo‘lsa `provider_unavailable`, soxta javob ishlatilmaydi; inson tasdig‘isiz narx yozilmaydi.
- Live AI provayder: **sozlanmagan** — hech qanday haqiqiy API kalit yo‘q, shuning uchun "AI extraction ishlaydi" deb da’vo qilinmaydi.

## 3. 5-bosqich natijalari — holat: Implemented, Tested

- `alert_rules`: event_type, scope (product/supplier/category/global), threshold (percent/absolute), frequency, channels, enabled.
- `app_notifications`: user+dedupe_key unikal indeksi — bir voqea uchun takroriy bildirishnoma yaratilmaydi.
- `ManageAlerts::dispatch()` — scope va threshold filtrlari bilan; audit yozuvlari.
- Integratsiya: yangi taklif yozilganda `RecordOffer::afterRecorded()` narx pasayishi/oshishi va yangi taklif eventlarini yuboradi; import tugaganda `import_completed`/`import_completed_with_errors` eventlari yuboriladi.
- Email: SMTP sozlanmagan bo‘lsa `email_status = unavailable` — halol holat, soxta "sent" yozilmaydi.
- Frontend: `/notifications` (markaz, o‘qilmagan filtri, barchasini o‘qilgan qilish) va `/notifications/rules` (qoida yaratish/yoqish-o‘chirish) sahifalari — o‘zbek tilida.

## 4. 6-bosqich natijalari — holat: Implemented, Tested

- `/analytics` dashboard: mahsulotlar, faol yetkazib beruvchilar, jami/yangi kuzatuvlar, kutilayotgan tekshiruvlar — barchasi haqiqiy DB agregatlari; bo‘sh datasetlarda helpful empty state.
- Narx harakati: mahsulot bo‘yicha birinchi/oxirgi kuzatuv taqqoslash faqat bir xil valyuta ichida (valyuta aralash taqqoslashga yo‘l qo‘yilmaydi).
- Yetkazib beruvchi faolligi va yaqin importlar jadvallari.
- CSV eksport: tenant izolyatsiyasi, sana/mahsulot/yetkazib beruvchi/valyuta filtrlari, `safeCsv()` orqali formula injection (`=`, `+`, `-`, `@`, TAB boshlanishli qiymatlar) neytrallashga solinadi.
- Ishlash: agregatsiyalar DB tomonda (`selectRaw`, `groupBy`), `chunk(500)` bilan eksport, limit 1000 movement so‘rovi; N+1 yo‘q.

## 5. Yangi migratsiyalar

- `2026_10_30_150000_create_alert_rules_and_app_notifications.php` — additive, faqat yangi jadvallar; mavjud jadvallar o‘zgarmadi.

## 6. Yangi backend fayllar

- `app/Services/ManageAlerts.php`
- `app/Models/AlertRule.php`, `app/Models/AppNotification.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/AnalyticsController.php`

O‘zgargan: `app/Services/RecordOffer.php` (afterRecorded hook), `app/Services/ManageImports.php` (import completion alert + recentFor), `routes/web.php` (6 yangi route).

## 7. Yangi frontend fayllar

- `resources/js/Pages/Notifications.tsx`
- `resources/js/Pages/AlertRules.tsx`
- `resources/js/Pages/AnalyticsDashboard.tsx`
- `resources/css/app.css` — stat-grid, notification list, tab, up/down stillari.

## 8. Yangi API endpointlar

- GET `/notifications`, POST `/notifications/{id}/read`, POST `/notifications/read-all`
- GET/POST `/notifications/rules`, POST `/notifications/rules/{rule}`
- GET `/analytics`, GET `/analytics/export`

## 9. Ruxsatlar

- Alert rule boshqaruv: faqat `owner`/`manager`/`buyer` (`manage` policy), audit yozuvi bilan.
- Bildirishnomalar: faqat o‘z foydalanuvchisi (`user_id` filtri) va o‘z tashkiloti (`organization_id` filtri).
- Analytics: `verified + ResolveOrganization` middleware orqali; barcha so‘rovlar `organization_id` bilan chegaralangan.

## 10. Test natijalari

- **SQLite:** 187 passed, 13 skipped (PostgreSQL-only testlar), 0 failed — 1407 assertion.
- **PostgreSQL 17 (Docker, port 55979):** to‘liq suite 200 passed, 0 failed — 1423 assertion (SQLite’da skip qilingan 13 ta PostgreSQL-only test ham shu yerda yashil).
- Yangi testlar: `tests/Feature/NotificationsAnalyticsTest.php` — 5 test: qoida yaratish+audit+begona scope rad etilishi, dedupe+scope+threshold, email halol holat, import notification+o‘qilgan holat, analytics dashboard+CSV injection+tenant izolyatsiya.

## 11. Build natijalari

- `npm run build` ✓, `npm run typecheck` ✓, `vendor/bin/pint` ✓.

## 12. Xavfsizlik tekshiruvlari

- Tenant izolyatsiya testi: ikkinchi tashkilot foydalanuvchisi analytics’da birinchi tashkilot ma’lumotini ko‘rmaydi.
- CSV formula injection: `=cmd|test` qiymati eksportda prefiks bilan neytrallanadi.
- Audit: `alert_rule.created/updated`, `notifications.read_all` audit yozuvlari.

## 13. Ishlash kuzatislari

- Dashboard agregatlari SQL tomonda; priceMovement so‘rovi `MIN(id) GROUP BY product, supplier` bilan oxirgi qatorlarni tanlaydi va 1000 qator bilan cheklanadi.
- Eksport `chunk(500)` — butun jadvalni xotiraga yuklamaydi.

## 14. Sozlanmagan tashqi integratsiyalar

- Live AI extraction provayderi (kalit yo‘q) — arxitektura tayyor, live da’vo yo‘q.
- Email SMTP — `mail.default` bo‘sh; in-app kanal to‘liq ishlaydi, email holati halol ko‘rsatiladi.
- Telegram — faqat arxitektura kengaytirilishi; faol emas.

## 15. Ma’lum cheklovlar

- Daily frequency hozircha faqat qiymat sifatida saqlanadi; kunlik digest scheduler keyingi sprintga.
- `lowest_price_changed`, `price_verification_*`, `supplier_verification_changed`, `import_failed` event turlari ro‘yxatda mavjud va dispatch qilinadigan, lekin ba’zi Phase 3 eventlaridan avtomatik trigger hali ulanmagan (qo‘lda dispatch API’si mavjud).
- PDF eksport implement qilinmagan (mos kütüphane yo‘q) — CSV asosiy eksport.

## 16. Production readiness

Lokal testlar to‘liq yashil, lekin bu production-ready ekanini isbotlamaydi: live AI, SMTP, real yuk va browser testlari production gate’lari ochiq qoladi.

## 17. Sprint 2 uchun aniq qolgan vazifalar

1. Phase 3 eventlaridan (verification approved/rejected, dispute, supplier holati) avtomatik alert triggerlarini ulash.
2. Daily digest scheduler (`schedule:run` integratsiyasi).
3. Telegram kanali (haqiqiy kredential va integratsiya testi bilan).
4. Chromium desktop/mobile browser testlari yangi sahifalar uchun.
5. Excel eksport (agar xlsx yozish kutubxonasi xavfsiz qo‘shilsa) va PDF eksport qarori.
6. Cache invalidation siyosati katta datasetlar uchun.
