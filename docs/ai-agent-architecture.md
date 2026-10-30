# AI arxitekturasi: keyingi bosqich talablari

AI hozir implement qilinmagan. /ai sahifasi buni ochiq ko‘rsatadi. Provider so‘rovi,
tool invocation, token/cost yoki autonomy natijasi mavjud emas.

10-bosqichda provider abstraction, structured planner, typed tool registry va
persistent task/step hayot sikli qo‘shiladi. Har tool tenant, actor, permission,
schema, risk, timeout, retry va idempotency shartlarini e’lon qilishi kerak.
Mavjud RecordOffer/CompareOffers kabi domain servis authorizationini chetlab o‘tish
mumkin emas. Arbitrary SQL/shell/HTTP/file access vositalari taqiqlanadi.

Read-only LOW risk avtomatik, MEDIUM risk tashkilot siyosati bilan, HIGH risk
aniq parametr/summa/muddati bilan bog‘langan inson tasdig‘idan keyin bajariladi.
Approval yoki AI yo‘qligi sababli binding xaridni muvaffaqiyatli deb ko‘rsatish mumkin
emas. Credential bo‘lmasa core market funksiyalari ishlashda davom etishi kerak.
Budgets, provider health, safe logs va recovery keyingi bosqich vazifalari.
