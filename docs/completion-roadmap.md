# Ishni yakunlash yo‘l xaritasi

2026-10-30. Audit: avvalgi 49 test / 533 assertion muvaffaqiyatli (2.930 s).
Git repository hali yo‘q. `.env` o‘qilmadi va o‘zgartirilmaydi. Stack va lock fayllar
saqlanadi. Laravel Boost MCP bu sessiyada ulanmagan; rasmiy Laravel 13/Inertia 3
hujjatlari hamda o‘rnatilgan framework kodi asosida ishlanadi.

## Auditda aniqlangan kamchiliklar

Email verification yo‘q; faolsiz hisob va sessiya bekor qilish siyosati yo‘q;
profil/tashkilotni tahrirlash, a’zolar va taklifnomalar yo‘q; uchta eski rol bor.
Parol tiklash sessiyalarni faqat database backendda o‘chiradi. Market CRUD,
import lifecycle, analytics, procurement va AI bosqichlari to‘liq emas. /up faqat
bootni tekshiradi. PostgreSQL hamda browser testlari bajarilmagan.

## Ketma-ket bosqichlar

1. Hisob/tashkilot: verification, profil, parol, sessiyalar, o‘z hisobini
   faolsizlantirish/qayta faollashtirish, tashkilot tahriri, beshta rol,
   taklifnoma muddati/qabul/bekor qilish, a’zolarni boshqarish va audit.
2. Mahsulot/yetkazib beruvchi: CRUD, media, variant/alias, verifikatsiya dalillari.
3. Narxlar: manbalar, valyuta kurslari, cheklangan comparison query, remote search.
4. Import: preview/mapping/tarix/xatolar/retention, Excel va nazoratli OCR.
5. Alert/notification: kanallar, preferences, retry va dublikatdan himoya.
6. Analytics/report: haqiqiy kuzatuvlar va ma’lumot to‘liqligi.
7. Forecast: demand kuzatuvlari, baseline, evaluation va model versiyasi.
8. Group procurement: ishtirok, majburiyatlar, MOQ va yetkazish taqsimoti.
9. Proposal/approval: aniq scope, rad sababi va o‘zgarmas dalillar.
10. AI: provider, schema/tool registry, persistent tasks, risk/budget nazorati.
11. Reliability: queue, schedule, outbox/recovery va monitoring.
12. Administration: alohida platform permission scope va operatsion nazorat.

Har bosqich uchun backend/UI, authorization, validatsiya, regressiya testlari,
haqiqiy natija va keyingi continuation holati qayd etiladi. Production holati
PostgreSQL/browser/staging/restore dalillarisiz tasdiqlanmaydi.
