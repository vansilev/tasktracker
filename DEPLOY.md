# Деплой Task Tracker AVANT

## Что проверено

- Проект: Laravel 13.17.0, Livewire 3, Vite, MySQL.
- Локально установлено: PHP 8.4.9, Composer 2.9.2, Node.js 24.14.1.
- `composer check-platform-reqs` проходит.
- `php artisan migrate:status` показывает, что все миграции применены.
- `php artisan test` проходит: 180 tests, 433 assertions.
- `npm run build` проходит, assets собраны в `public/build`.
- Apache config syntax: `Syntax OK`.

Важный блокер перед открытием людям: сейчас приложение доступно на
`http://127.0.0.1:8000/tasktracker/public/login`, но этот способ нельзя
использовать как production. На `:8000` Apache отдает весь каталог проекта из
`htdocs`, включая `.env`, `composer.json`, `storage/logs/laravel.log` и
`vendor/autoload.php`. Production-доступ должен вести только в:

```text
C:/Apache24/htdocs/tasktracker/public
```

## Рекомендуемая схема

Лучше дать приложению отдельное имя, например:

```text
http://tasktracker.local/login
```

или, если нет DNS:

```text
http://192.168.1.242/login
```

Текущий LAN IP на Wi-Fi: `192.168.1.242`. Если сервер будет в другой сети,
проверь актуальный IP командой:

```powershell
Get-NetIPAddress -AddressFamily IPv4
```

## 1. Подготовить базу

Если это новая production-база:

```sql
CREATE DATABASE tasktracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tasktracker_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON tasktracker.* TO 'tasktracker_user'@'localhost';
FLUSH PRIVILEGES;
```

Если база уже рабочая, перед любыми миграциями сделай бэкап:

```powershell
mysqldump -u root -p tasktracker > C:\backup\tasktracker.sql
```

Не используй на рабочей базе:

```powershell
php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe
```

## 2. Настроить `.env`

Открой:

```text
C:\Apache24\htdocs\tasktracker\.env
```

Для production должны быть такие значения:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://tasktracker.local
APP_TIMEZONE=Europe/Kyiv
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tasktracker
DB_USERNAME=tasktracker_user
DB_PASSWORD=STRONG_PASSWORD_HERE

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@tcsavant.com"
MAIL_FROM_NAME="${APP_NAME}"

ALLOWED_EMAIL_DOMAINS=tcsavant.com
PASSWORD_LOGIN_ENABLED=true
GOOGLE_SSO_ENABLED=false
```

Если используешь IP вместо имени, поставь:

```dotenv
APP_URL=http://192.168.1.242
```

`APP_KEY` должен быть заполнен. На новой установке с пустым `.env` выполни:

```powershell
php artisan key:generate
```

На уже работающем production не генерируй `APP_KEY` заново без причины.

## 3. Установить зависимости и собрать фронт

```powershell
cd C:\Apache24\htdocs\tasktracker
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 4. Применить миграции и сиды

Для рабочей базы:

```powershell
php artisan migrate --force
```

Только для новой пустой базы:

```powershell
php artisan db:seed --force
```

Первый админ создается из `.env`:

```dotenv
ADMIN_EMAIL=crm.manager@tcsavant.com
ADMIN_NAME="CRM Manager"
ADMIN_PASSWORD=...
```

После первого деплоя лучше хранить реальный пароль в менеджере паролей и не
передавать `.env` пользователям.

## 5. Настроить Apache

Открой:

```text
C:\Apache24\conf\extra\httpd-vhosts.conf
```

Добавь отдельный virtual host:

```apache
<VirtualHost *:80>
    ServerName tasktracker.local
    ServerAlias 192.168.1.242

    DocumentRoot "C:/Apache24/htdocs/tasktracker/public"

    <Directory "C:/Apache24/htdocs/tasktracker/public">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "C:/Apache24/logs/tasktracker-error.log"
    CustomLog "C:/Apache24/logs/tasktracker-access.log" combined
</VirtualHost>
```

Проверь, что в `C:\Apache24\conf\httpd.conf` включены:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
Include conf/extra/httpd-vhosts.conf
```

Проверь конфиг:

```powershell
C:\Apache24\bin\httpd.exe -t
C:\Apache24\bin\httpd.exe -S
```

Перезапусти Apache:

```powershell
Restart-Service Apache2.4
```

Если пользователи будут заходить по имени `tasktracker.local`, добавь DNS-запись
на сервере/роутере. Для ручной проверки можно добавить на клиентском ПК в
`C:\Windows\System32\drivers\etc\hosts`:

```text
192.168.1.242 tasktracker.local
```

## 6. Закрыть опасный доступ через `:8000`

Сейчас `Listen 8000` в Apache открывает `C:/Apache24/htdocs`, поэтому
`http://SERVER:8000/tasktracker/.env` может быть доступен.

Для production выбери один вариант:

1. Убери или закомментируй `Listen 8000` в `C:\Apache24\conf\httpd.conf`.
2. Или добавь отдельный `<VirtualHost *:8000>` с `DocumentRoot` строго на
   `C:/Apache24/htdocs/tasktracker/public`.
3. Или закрой порт `8000` в firewall и не публикуй его в сеть.

После правки обязательно проверь:

```powershell
C:\Apache24\bin\httpd.exe -t
Restart-Service Apache2.4
```

## 7. Открыть firewall

Для LAN-доступа открой входящий HTTP-порт:

```powershell
New-NetFirewallRule -DisplayName "TaskTracker HTTP" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
```

Если приложение будет доступно из интернета, используй HTTPS, нормальный домен,
сертификат и ограничь админский доступ. Без HTTPS наружу не выкатывать.

## 8. Включить Laravel scheduler

Напоминания отправляются командой `tasks:send-reminders`, она запланирована
каждые 30 минут через Laravel Scheduler. На Windows нужно создать задачу,
которая запускает scheduler каждую минуту.

В Task Scheduler:

- Program/script: путь к `php.exe`, например
  `C:\php\php-8.4.8-nts-Win32-vs17-x64\php.exe`
- Add arguments:
  `artisan schedule:run`
- Start in:
  `C:\Apache24\htdocs\tasktracker`
- Trigger: каждые 1 минуту, indefinitely.

Проверка:

```powershell
php artisan schedule:list
php artisan tasks:send-reminders --dry-run
```

Уведомления Email/Telegram идут через очередь (`ShouldQueue`). Добавь второй
Scheduled Task (каждые 1 минуту) или держи постоянный worker:

```powershell
php artisan queue:work --stop-when-empty --tries=3 --timeout=90
```

Для постоянной службы:

```powershell
php artisan queue:work --tries=3 --timeout=90
```

Telegram: задай `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`
в `.env` и зарегистрируй webhook на `https://<host>/telegram/webhook`.
SMTP: замени `MAIL_MAILER=log` на реальные реквизиты.
## 9. Закешировать production-конфиг

После всех правок `.env`:

```powershell
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

После изменения `.env` снова выполняй:

```powershell
php artisan optimize:clear
php artisan config:cache
```

## 10. Финальная проверка

С сервера:

```powershell
php artisan about
php artisan migrate:status
php artisan schedule:list
```

В браузере:

```text
http://tasktracker.local/login
```

или:

```text
http://192.168.1.242/login
```

Проверь, что эти адреса не отдают служебные файлы:

```text
http://tasktracker.local/.env
http://tasktracker.local/composer.json
http://tasktracker.local/storage/logs/laravel.log
```

Должно быть `404` или `403`, но не `200`.

Проверь рабочий сценарий:

1. Войти админом.
2. Создать пользователя.
3. Создать задачу.
4. Добавить комментарий и вложение.
5. Проверить уведомления.
6. Зайти в `/admin/settings`, `/admin/users`, `/admin/audit`.

## Google SSO

Сейчас Google SSO выключен:

```dotenv
GOOGLE_SSO_ENABLED=false
```

Чтобы включить:

1. Создай OAuth Client в Google Cloud.
2. Redirect URI:

   ```text
   http://tasktracker.local/auth/google/callback
   ```

3. Заполни `.env`:

   ```dotenv
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   GOOGLE_SSO_ENABLED=true
   ```

4. Выполни:

   ```powershell
   php artisan optimize:clear
   php artisan config:cache
   ```

## Короткий production-чеклист

- [ ] Apache `DocumentRoot` указывает только на `public`.
- [ ] Порт `8000` закрыт или тоже ведет только в `public`.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] `APP_URL` без `/public`.
- [ ] База не под `root`, а под отдельным MySQL-пользователем.
- [ ] `php artisan migrate --force` выполнен.
- [ ] `npm run build` выполнен.
- [ ] `php artisan config:cache` выполнен.
- [ ] Windows Scheduler запускает `artisan schedule:run` каждую минуту.
- [ ] Firewall открыт только на нужные порты.
- [ ] `/.env`, `/composer.json`, `/storage/logs/laravel.log` не доступны из браузера.
