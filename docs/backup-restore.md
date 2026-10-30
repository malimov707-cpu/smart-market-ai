# Backup va restore rejasi

Holat: production backup sozlanmagan, restore testi bajarilmagan. PostgreSQL
integratsiya testlarining o‘tishi backup/restore tekshirildi degani emas.

Taklif etilgan boshlang‘ich RPO 24 soat, RTO 4 soat; o‘lchanmagan. Mas’ul operator
har kecha PostgreSQL custom-format dump, private fayllar va secret-manager recovery
jarayonini boshqarishi kerak. 7 kunlik, 4 haftalik, 3 oylik nusxa; alohida hisobda
encryption va cheklangan kirish. Nusxalarni repo yoki public/ ga joylamang.

Quyidagi buyruqlar tayyorlangan, productionda bajarilmagan. Credentialni 0600
huquqli PGPASSFILE bilan bering; shell historyga parol yozmang.

```sh
pg_dump --format=custom --file=/secure/backup/smart-market.dump --dbname=smart_market_ai
pg_restore --exit-on-error --no-owner --dbname=smart_market_restore /secure/backup/smart-market.dump
```

Restore target alohida bo‘sh muhit bo‘lishi shart, production endpoint yoki worker
bo‘lmasin. Row count, CHECK/FK, a’zolik izolyatsiyasi, login va nazorat hisoblarini
tekshiring; backup yoshi va restore davomiyligini qayd eting. Secretlarni dump bilan
bir joyda saqlamang. Infrastructure/account access bo‘lmagani uchun bu release gate ochiq.
