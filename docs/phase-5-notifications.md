# Bildirishnomalar: amaldagi doira va cheklovlar

2026-10-31. Ushbu sprint yangi biznes bosqichini boshlamadi; mavjud modulning
recipient, preference va status xatolarini tuzatadi.

Har alert — uning `user_id` egasining shaxsiy obunasi. Boshqa a’zo qoidani tahrirlay
olmaydi. Bir voqeaga bir foydalanuvchi uchun tenant-qualified SHA-256 dedupe ishlaydi.
Bir necha foydalanuvchi faqat har biri alohida obuna bo‘lsa oladi. `global` barcha
mahsulotlar qamrovi; tashkilotga broadcast emas. Tashkilot bo‘ylab recipient audience
hali qo‘llab-quvvatlanmaydi; u avtomatik taxmin qilinmaydi.

Recipient faol, emaili verified, tashkilotda hozir a’zo va view huquqli bo‘lishi
kerak. Unsubscribed, revoked va deactivated hisoblar chiqariladi. `channels` shu
obunaning email preference’i: `email` bo‘lmasa email yuborilmaydi. Ilova ichidagi
bildirishnoma email failure’dan mustaqil saqlanadi. Recipients alohida To bilan,
CC/BCC yoki boshqa a’zolar emailini ochmasdan yuboriladi.

Email statuslari:

- not_requested — matching obunada email yo‘q.
- unavailable — SMTP transport yoki majburiy konfiguratsiya yo‘q; log/array ham shu.
- sent — real SMTP transport return qiymati xabar qabul qilinganini bildirgan;
  UI “SMTP qabul qildi, delivery tasdiqlanmagan” deydi.
- failed — transport exception yoki xabar qabul qilinmagan.

Bu modulda queued/processing/delivered email holatlari yo‘q. `sent` delivery emas;
provider webhook tasdig‘i ulanmagan. Real SMTP delivery tashqi release gate.
Historical sent yozuvlari qayta interpretatsiya/backfill qilinmadi: eski log transport
muvaffaqiyati haqiqiy delivery dalili sifatida ishlatilmasin.

Instant qoidalar ishlaydi. Daily scheduler/digest va string kategoriya qamrovi
amaldagi schema/worker bilan to‘liq ishlamagani uchun yangi create’da taklif qilinmaydi;
daily eski yozuvlari instant deb yuborilmaydi. Rule event enumining mavjudligi har
bir event hook implement qilinganini anglatmaydi. Import completion hook bor;
barcha price/verification eventlarining end-to-end deliverysi alohida scope.

Threshold decimal tekshiriladi; float orqali abs qilish olib tashlandi. Alert
recipient testlari, tenant/ownership HTTP testlari va browser preference/read
regressiyalari corrective sprint hisobotida qayd etiladi.
