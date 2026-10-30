# Xavfsizlik va ruxsatlar

## Ruxsat matritsasi

| Amal | Owner | Manager | Buyer | Analyst | Auditor |
| --- | --- | --- | --- | --- | --- |
| Tashkilot bozor ma’lumotlarini o‘qish | Ha | Ha | Ha | Ha | Ha |
| Mahsulot/yetkazib beruvchini yaratish va mahsulotni arxivlash | Ha | Ha | Yo‘q | Yo‘q | Yo‘q |
| Narx taklifi va CSV import | Ha | Ha | Ha | Yo‘q | Yo‘q |
| Tashkilotni tahrirlash | Ha | Yo‘q | Yo‘q | Yo‘q | Yo‘q |
| A’zolar/taklifnomalar ro‘yxati va boshqaruvi | Ha | Yo‘q | Yo‘q | Yo‘q | Yo‘q |
| O‘z profili, paroli va sessiyalari | Ha | Ha | Ha | Ha | Ha |

Barcha domain ruxsatlari faol hisob, tasdiqlangan email va joriy a’zolikni
serverdagi policy orqali tekshiradi. Analyst va Auditorning mavjud modullardagi
ruxsatlari hozircha bir xil; yangi analytics moduli keyingi bosqichda.
Platforma administratori hali implement qilinmagan va tashkilot egasiga platforma
vakolati berilmaydi.

## Hisob hayot sikli

Yangi hisob email tasdiqlamaguncha ish maydoniga kira olmaydi. Imzolangan
verification havola 60 daqiqada eskiradi. Email o‘zgarganda tasdiqlash bekor
qilinadi. Profil/parol/sessiya/faolsizlantirish uchun joriy parol talab qilinadi.
12 belgili, harf va raqamli parol siyosati saqlangan. Password reset Laravel broker
orqali muddati cheklangan va bir martalik token bilan ishlaydi.

`security_version` eskirgan sessiyalarni backend turidan qat’i nazar rad qiladi.
Parol/email o‘zgarishi, reset, faolsizlantirish va barcha sessiyalarni bekor qilish
versiyani oshiradi; remember token aylantiriladi. Browserda boshqa sessiyalarni
birma-bir boshqarish faqat database session backend uchun mavjud. Raw sessiya ID
frontendga yuborilmaydi; HMAC identifikator qo‘llanadi.

Faolsizlantirish o‘z hisobiga tegishli; ma’lumotlarni o‘chirmaydi. Qayta faollashtirish
email va amaldagi parol bilan alohida, rate-limit qilingan amal. Bu mexanizm
kelajakdagi platforma administratorining suspend/ban siyosati emas.

## A’zolik va taklifnomalar

Faqat Owner taklif yuboradi, bekor qiladi va rolni o‘zgartiradi. Token 64 tasodifiy
belgidan iborat; bazada SHA256 xeshi saqlanadi. Taklif 7 kun amal qiladi; faqat
shu email tasdiqlangan hisob bir marta qabul qiladi. Taklif yuboruvchining joriy
Owner ruxsati qabul paytida qayta tekshiriladi. Takroriy pending email uchun DB
unique cheklovi bor. Mail yuborish xatosida taklif bekor qilinadi.

Oxirgi faol egani demote/remove/deactivate qilish mumkin emas. Tegishli user va
organization yozuvlariga transaction lock olinadi. Rol o‘zgarishi auditida oldingi
va yangi rol yoziladi. Bir vaqtdagi katta yuklama/stress test hali bajarilmagan.

## Ma’lumot himoyasi

Tenant ID server tomonidan belgilanadi. Offer uchun composite foreign key
begona tashkilot product/supplier bog‘lanishini bazada ham rad qiladi. CSRF Laravel
web middleware orqali saqlangan. React foydalanuvchi matnini escape qiladi.
So‘rov qiymatlari binding bilan ishlaydi. Account va maxfiy havolali javoblar
private/no-store; Referrer-Policy no-referrer. Loglar route/method/status/duration
va request ID bilan cheklangan; request body va tokenlar yozilmaydi.

Log mailer tokenli identity xatlari uchun bloklangan. Array mailer faqat testingda
ruxsat etilgan. Production uchun haqiqiy SMTP/API transporti, TLS, secure cookie,
SESSION_ENCRYPT=true, APP_DEBUG=false va alohida APP_KEY kerak. `.env` o‘zgartirilmadi.

## Chegaralar

2FA, SSO, platforma admini, RLS, tashqi o‘zgarmas audit sinki va to‘liq pentest
hali mavjud emas. Real SMTP yetkazib berish tekshirilmagan. Dependency auditlari
faqat bajarilgan paytdagi holatni bildiradi.

## 2-bosqich qoidalari

Product/supplier CRUD, metadata va fayl yozish — Owner/Manager. Buyer offer va
feedback yozadi; Analyst/Auditor yangi katalog/trust yozish amallarini bajarmaydi.
Barcha a’zolar o‘z tenantidagi ma’lumotlarni va private fayllarni o‘qiydi. Verification
qarori/dispute moderation — Owner; platform verification uchun qo‘shimcha
platform_permissions.supplier.verify va joriy tenantga a’zolik talab qilinadi.
Bu grantga o‘z-o‘zini ega qilish HTTP endpointi yo‘q; real operator provisioning
va platform admini keyingi tegishli bosqichga kiradi.

Submission dalilsiz o‘tmaydi; actor o‘z submissionini approve/reject qilmaydi.
Scope/status/reviewer/time/evidence o‘zgarmas history yozuvlarida saqlanadi. Supplier
arxivi offer tarixini o‘chirmaydi; yangi offer qabul qilinmaydi. Archived/suspended
supplier comparison’da eligible emas. Org/platform tasdiq scope’i narxning o‘zi
verified ekanligini bildirmaydi.

Upload MIME va extension hamda size tekshiradi; rasmlar decode/re-encode, SVG/
executable fayllar rad qilinadi. Product image 2 MB, supplier document 5 MB,
rasm o‘lchami 4096×4096 gacha. Random storage nomlari private diskda; download oldidan
tenant/policy, sensitive access uchun audit. Retirement kirishni darhol bekor qiladi;
originalning physical retentioni cheklanmagan, avtomatik purge yo‘q. Productiondan
oldin storage quota, retention/legal policy, backup va purge tartibi tasdiqlansin.
Malware scanning mavjudligi yoki hujjatning avtomatik haqiqiyligi da’vo qilinmaydi.

CSV UTF-8/NUL/size/rows tekshiriladi, mapping allowlist, aliases/attributes JSON list,
conflicting SKU/barcode row error. Barcha invalid rowlar confirm’ni bloklaydi.
Formula prefikslari (=,+,-,@ va bosh whitespace/control) eksportda apostrof bilan
neytrallanadi. Formula-safe escaping matn ko‘rinishini o‘zgartirishi mumkin; u SQL
execution yoki Excel formula bajarishga yo‘l bermaydi.

## 3-bosqich narx xavfsizligi

Current verified/active membership barcha /prices, price-history va exchange-rates
endpointlarida talab qilinadi. Owner/Manager/Buyer offer va pending/dispute yozadi;
Analyst/Auditor read-only. Verified/rejected price qarori independent Ownerga;
platform scope qo‘shimcha price.verify grantiga bog‘liq. supplier.verify bu huquqni
bermaydi. Platform grant tenant a’zoligini almashtirmaydi, HTTP self-grant yo‘q.

Source, task va correction related IDlari tenant/product/supplierga tekshiriladi.
Request submitted_by/recorded_at/verification statusni mass assignment qilmaydi.
Price verification supplier verificationdan mustaqil. Review evidence private,
shu supplierning faol fayli, reviewer offer/pending/evidence muallifi bo‘lmasin.
Review/reason/actor/time/scope append-only saqlanadi va audit event yaratiladi.
Evidence file row lock retire/replace bilan poygani serializatsiya qiladi; dalilga
reference yozilgach logical retirement ham taqiqlanadi. Physical purge siyosati ochiq.

As-of/future/date-range/supplier parametrlari validatsiya qilinadi. SQL window,
filter va expressionlar static; user qiymatlar binding bilan. Future recorded review,
correction yoki rate earlier comparisonni o‘zgartirmaydi. Joriy revoked membership
eski tarixni o‘qish uchun ishlamaydi. Source URL avtomatik fetch qilinmaydi.

Rate entry/approval Owner; source va interval majburiy, own approval rad qilinadi.
FX live adapter yoki avtomatik source-authenticity tekshiruvi yo‘q: human reviewer
productiondagi real dalil uchun javobgar. Unauthorized direct HTTP, besh rol,
cross-tenant evidence/history/rate va platform permission regressiyalari qo‘shildi.
Bu keng qamrovli pentest yoki tashqi audit tamper-resistance dalili emas.
## 4-bosqich import markazi

Upload MIME/extension/hajm (5 MB), rasm o'lchami va sha256 hash tekshiruvi bilan
qabul qilinadi; private diskda tasodifiy UUID nom saqlanadi. Original fayl hash
o'zgargan bo'lsa prepare rad etadi (`source_missing_or_changed`). XLSX parser zip
bomb, unsafe entry path, formula, macro, external link va XML entity'larni rad
etadi. AI gateway faqat HTTPS, kredensial yo'q bo'lsa chaqirilmaydi; kalitlar
log/frontend/reportga chiqmaydi. Error report CSV `safeCell` formula-injection
himoyasi bilan. Tenant izolyatsiya, unauthorized direct HTTP, evidence access
va cross-tenant regressiyalar test qilingan. Purge faqat muddati o'tgan,
tasdiqlanmagan failed/cancelled batchlarga dry-run/execute buyrug'i bilan.
