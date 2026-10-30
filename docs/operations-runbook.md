# Operatsion qo‘llanma

## Identity nosozliklari

- /verify-email ga redirect: email tasdiqlanmagan. /account orqali manzilni tuzating,
  haqiqiy mail transportini sozlang va tasdiqlash xatini yuboring.
- Log mailer bloklangan: xavfsizlik talabi; tokenlarni logdan olish yo‘li yo‘q.
- Sessiya bekor qilingan: security_version o‘zgargan yoki hisob faolsiz.
- Tanlangan tashkilot 403: a’zolik bekor qilingan; /account dan boshqa orgga o‘ting.
- Oxirgi Owner xatosi: avval boshqa faol foydalanuvchini Owner qiling.
- Invitation 410: muddati tugagan, qabul qilingan, bekor qilingan yoki yuboruvchi
  Owner huquqini yo‘qotgan. Yangi taklif kerak.
- Mail xatosi: invitation bekor qilinadi; mail transportini tiklab yangisini yuboring.
- Hisobni qayta faollashtirish: login sahifasidagi alohida formadan email/parol bilan.

## Operatsion tekshiruv

`php artisan migrate:status`, `php artisan route:list --except-vendor` va
request ID bilan application logni ko‘ring. Body, token, parol, cookie yoki SMTP
credentialni diagnostika hisobotiga qo‘shmang. /up faqat boot; dependency health
endpointlari keyingi hardening vazifasi. External monitoring hali ulanmagan.

Domain job/schedule hali yo‘q. Ular qo‘shilgach supervise queue:work,
schedule:run cron, timeout/backoff, failed-job review va queue:restart zarur.
Bu buyruqlar ishlayotgan servis sifatida e’lon qilinmaydi.

Incidentda mas’ul operator zararni cheklaydi, request/security/audit eventlarni
tekshiradi, kerak bo‘lsa sessiyalarni bekor qiladi, mailni qayta sozlaydi va hodisani
hujjatlaydi. Backup, recovery va deployment hujjatlaridagi ochiq gate’larni kuzating.
