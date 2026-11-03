# Keyingi ishlar va ustuvorliklar

Yangilangan: 2026-11-02. Bu ro‘yxat amaldagi **Admin/User** rol modeli va joriy
funksiyalar uchun. Eski audit/timeline notlari chalkashlikka olib kelmasin: tarixiy
bosqichlar o‘sha paytdagi snapshot bo‘lib qoladi, amaldagi release holati
[production-readiness-report.md](production-readiness-report.md)da.

## Hozir mavjud imkoniyatlar

- User ommaviy mahsulot kataloglarini ko‘radi/qidiradi, narxlarni taqqoslaydi,
  trend/tejash signallarini oladi va narx kuzatuvini yoqishi mumkin.
- Mahsulot kameradan yoki rasm faylidan taniladi; AI nom/variantni qidiruvga
  aylantiradi. Yakuniy narx AI’dan emas, ko‘rsatilgan manbadan olinadi.
- Admin barcha eski partition’lardagi katalog/import/tahlilni global ko‘radi va
  boshqaradi. Legacy `organization_id` hamda composite foreign-key’lar o‘z holida.
- Telegram polling/briefing implement qilingan, ammo yangi token bilan real
  credential smoke-testi va workerning uzluksiz ishlashi deploymentda tekshirilishi shart.

## Eng ustuvor: production release gate

Quyidagilar lokal kod o‘zgarishi bilan yopilmaydi va ruxsatli staging hamda haqiqiy
provider/hosting credentiallarini talab qiladi:

1. HTTPS stagingda migration/release/rollback rehearsal; APP_DEBUG o‘chiq, secure
   cookie, proxy va domen sozlamalarini tekshirish.
2. PostgreSQLga CI va staging sinovi; mavjud migrationlarni amaldagi bazaga nisbatan
   xavfsiz rejalash.
3. Persistent queue worker va scheduler monitoringi, restart/recovery hamda failed-job
   alarm.
4. Haqiqiy, sinov uchun ruxsat berilgan manzilda SMTP delivery va reset/verification
   oqimi.
5. Real OpenRouter modeli orqali image va chat smoke-testi; latency, token xarajati,
   rate limit, fallback va budget alert.
6. Private file retention/limit, shifrlangan offsite backup, alohida muhitga restore
   mashqi va ma’lumotni qayta tiklash vaqti (RTO/RPO) tasdig‘i.
7. Tashqi monitoring/incident egasi, staging yuklama va xavfsizlik tekshiruvi.

Production hali **NOT READY**; gate’larning dalili [hisobotda](production-readiness-report.md).

## Mahsulot sifati — release’dan keyingi tavsiyalar

1. **Mahsulot moslashtirish:** barkod, alias, brend, qadoq va variant bo‘yicha
   deduplikatsiya; AI bergan nomzodga confidence va foydalanuvchi tasdig‘i.
2. **Narx yangiligi:** har bir manbada last-success va stale holati, xato/coverage
   ko‘rsatkichi; qidiruvda eski narxlarni yangisi kabi ko‘rsatmaslik.
3. **Narx kuzatuvi:** threshold (1/3/5/10%) va ilova/email kanallarini foydalanuvchi
   boshqarishi, kunlik duplicate suppression hamda umumiy inbox ko‘rinishi
   lokalda tekshirildi. Email status SMTP qabul qilganini ko‘rsatadi, inbox
   delivery emas. Telegram, quiet hours, retry va live provider delivery
   monitoringi hali ochiq.
4. **Qidiruv sifati:** o‘zbek/ruscha sinonim, typo-tolerant qidiruv, birlik/qadoqni
   normallashtirish va source filterlarning barqarorligi.
5. **Tezlik/xarajat:** real p50/p95 monitoring; katalog cache invalidation; AI
   so‘rov limiti, byudjet, result caching va provider latency hisobi.
6. **Kirish imkoniyati:** mobil kamera fallback, permission bekor qilish, klaviatura
   va screen-reader sinovlari; loading/error holatlarini kuzatish.

Yangi biznes scope (procurement workflow, forecast, group-buy) boshlashdan oldin har
biriga owner, data source, ruxsat va testable success criteria belgilang. Ushbu
funksiyalar hozirgi two-role market modeliga alohida tasdiqsiz qo‘shilmasin.
