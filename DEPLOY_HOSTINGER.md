# Деплой Task Tracker AVANT на Hostinger (task.avant.od.ua)

Инструкция для Hostinger Web/Cloud hosting с hPanel. Нужны SSH, MySQL,
PHP 8.4+ и cron.

Важно: PHP 8.4 или новее обязателен, не рекомендуется. В `composer.lock`
зависимости (в т.ч. Symfony 8.1) требуют `>=8.4.1` — на PHP 8.3
`composer install` и artisan падают.

Конкретика этого деплоя (уже подготовлено и проверено через Hostinger API):

- Аккаунт Hostinger: `u715639661`.
- Сайт `task.avant.od.ua` создан как отдельный сайт (тип `addon`),
  корень web root: `/home/u715639661/domains/task.avant.od.ua/public_html`.
- PHP на сайте — 8.4.21, все нужные расширения включены.
- MySQL база и пользователь созданы (см. раздел 2).
- ⚠️ DNS домена `avant.od.ua` обслуживается Cloudflare, не Hostinger
  (см. раздел «DNS и SSL»).

## Главное решение по структуре

Не кладём весь Laravel-проект в `public_html`.

```text
/home/u715639661/domains/task.avant.od.ua/
├── public_html/          # web root, сюда только файлы из public
└── tasktracker_app/      # Laravel app: app, bootstrap, config, vendor, .env, storage
```

Так браузер не сможет скачать `.env`, `composer.json`, `storage/logs/*` или
исходники приложения. Приложение `tasktracker_app` — сосед `public_html`, вне
web root, поэтому шаблонный `index.php` с `../tasktracker_app` работает без правок.

Шаблоны уже подготовлены в проекте:

```text
deploy/hostinger/public_html/index.php
deploy/hostinger/public_html/.htaccess
deploy/hostinger/.env.example
```

## DNS и SSL (важно: домен на Cloudflare)

Домен `avant.od.ua` обслуживается **Cloudflare** (NS `matteo.ns.cloudflare.com`,
`marlowe.ns.cloudflare.com`), а не Hostinger. Значит DNS для поддомена
настраивается **в Cloudflare**; изменения DNS-зоны в hPanel на публичное
разрешение имён не влияют.

Сейчас `task.avant.od.ua` не резолвится (NXDOMAIN). Чтобы сайт открывался:

1. В Cloudflare (зона `avant.od.ua`) добавь запись для `task`:
   - `A`: `task` → IP сервера Hostinger (hPanel → Hosting → Details, поле
     Server IP / Website IP), либо
   - `CNAME`: `task` → `task.avant.od.ua.cdn.hstgr.net`.
2. SSL:
   - Если запись проксируется (оранжевое облако) — в Cloudflare
     `SSL/TLS → Overview` поставь **Full** или **Full (strict)**. Режим
     **Flexible** даст цикл редиректов, т.к. `.htaccess` форсит HTTPS.
   - В Hostinger выпусти SSL-сертификат для `task.avant.od.ua` (после того как
     домен начнёт указывать на сервер).
   - Для первичной проверки можно временно «серое облако» (DNS only) +
     Hostinger-сертификат.

### За Cloudflare: доверие прокси (TrustProxies)

За обратным прокси Cloudflare Laravel должен доверять заголовкам прокси, иначе
возможны неверные `https`-ссылки и редирект-циклы. Добавь в `bootstrap/app.php`
внутри `->withMiddleware(...)`:

```php
$middleware->trustProxies(at: '*');
```

(При желании можно ограничить списком IP Cloudflare.) После правки очисти кэш:
`php artisan config:clear`.

## 1. Подготовить Hostinger

Уже сделано (проверено через API):

- Сайт `task.avant.od.ua` создан, корень
  `/home/u715639661/domains/task.avant.od.ua/public_html`.
- Выбран PHP 8.4; включены расширения `pdo_mysql`, `mbstring`, `fileinfo`,
  `gd`, `zip`, `dom`, `xml`, `simplexml`, `xmlreader`, `xmlwriter`, `openssl`,
  `curl` (плюс `bcmath`, `intl`).

Осталось:

1. Включить SSH: `Websites → Dashboard → SSH Access` (запиши host, port, user).
2. SSL — см. раздел «DNS и SSL».

## 2. MySQL-база (уже создана)

База и пользователь для `task.avant.od.ua` уже созданы:

```dotenv
DB_HOST=localhost
DB_DATABASE=u715639661_tasktracker
DB_USERNAME=u715639661_task
DB_PASSWORD=<пароль, заданный при создании базы>
```

База пустая (без таблиц). Если нужно пересоздать — `Websites → Dashboard →
Databases Management`.

## 3. Загрузить файлы

Через SSH, SFTP или File Manager создай папку:

```text
/home/u715639661/domains/task.avant.od.ua/tasktracker_app
```

В `tasktracker_app` загрузи проект, но не загружай:

```text
node_modules
.env
.phpunit.result.cache
storage/logs/*.log
storage/framework/cache/*
storage/framework/views/*
storage/framework/sessions/*
storage/app/private/livewire-tmp/*
storage/app/private/import/*
```

Если есть реальные вложения задач, перенеси:

```text
storage/app/private/attachments
```

В `public_html` загрузи содержимое локальной папки:

```text
public/
```

Затем замени в `public_html` два файла подготовленными шаблонами:

```text
deploy/hostinger/public_html/index.php
deploy/hostinger/public_html/.htaccess
```

Папка приложения называется `tasktracker_app`, поэтому строку в
`public_html/index.php` менять не нужно:

```php
$appBasePath = realpath(__DIR__.'/../tasktracker_app');
```

## 4. Создать production `.env`

На Hostinger создай файл:

```text
/home/u715639661/domains/task.avant.od.ua/tasktracker_app/.env
```

Возьми шаблон `deploy/hostinger/.env.example` (в нём уже проставлены `APP_URL`,
`DB_DATABASE`, `DB_USERNAME`). Минимально задай/проверь:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://task.avant.od.ua

DB_HOST=localhost
DB_DATABASE=u715639661_tasktracker
DB_USERNAME=u715639661_task
DB_PASSWORD=<пароль, заданный при создании базы>

ALLOWED_EMAIL_DOMAINS=tcsavant.com
ADMIN_EMAIL=crm.manager@tcsavant.com
ADMIN_PASSWORD=...
MAIL_FROM_ADDRESS="noreply@tcsavant.com"
```

Для HTTPS оставь:

```dotenv
SESSION_SECURE_COOKIE=true
```

Если временно проверяешь без SSL по `http://`, поставь `false`, но для людей
нужно вернуть `true`.

## 5. Команды по SSH

Зайди по SSH и перейди в приложение:

```bash
cd /home/u715639661/domains/task.avant.od.ua/tasktracker_app
```

Проверь PHP:

```bash
php -v
```

На этом сервере PHP 8.4 CLI обычно доступен по пути
`/opt/alt/php84/usr/bin/php`. Если `php` в SSH показывает версию ниже 8.4,
используй полный путь `/opt/alt/php84/usr/bin/php` вместо `php` в командах и cron.

Установи зависимости:

```bash
composer install --no-dev --optimize-autoloader
```

Если `APP_KEY` пустой:

```bash
php artisan key:generate
```

Создай нужные папки:

```bash
mkdir -p storage/app/private/attachments
mkdir -p storage/app/private/import
mkdir -p storage/app/private/livewire-tmp
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

Примени миграции:

```bash
php artisan migrate --force
php artisan tasks:backfill-plain-text
```

Миграция уже заполняет `description_text` / `body_text` при применении, но
команду стоит прогнать после деплоя (идемпотентна; `--dry-run` покажет,
сколько строк было бы обновлено без записи).

База новая и пустая — заполни базовыми данными (справочники + админ):

```bash
php artisan db:seed --force
```

Закешируй production:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Проверь:

```bash
php artisan about
php artisan migrate:status
php artisan schedule:list
```

## 6. (Опционально) перенести текущую локальную базу

Мы выбрали чистый старт (раздел 5), поэтому этот раздел нужен только если
захочешь перенести локальные данные. В этом случае `db:seed` не запускай.

На локальном компьютере:

```powershell
mysqldump --default-character-set=utf8mb4 -u root -p tasktracker > C:\Apache24\htdocs\tasktracker\tasktracker.sql
```

Загрузи `tasktracker.sql` на Hostinger, например в:

```text
/home/u715639661/domains/task.avant.od.ua/tasktracker_app/tasktracker.sql
```

На Hostinger:

```bash
mysql -h localhost -u u715639661_task -p u715639661_tasktracker < tasktracker.sql
php artisan migrate --force
php artisan tasks:backfill-plain-text
```

После импорта удали SQL-файл:

```bash
rm tasktracker.sql
```

## 7. Собрать фронт

На локальной машине уже проверено:

```powershell
npm run build
```

После сборки содержимое локальной папки `public/build` должно попасть в:

```text
/home/u715639661/domains/task.avant.od.ua/public_html/build
```

`node_modules` на Hostinger загружать не нужно, если фронт собирается локально.

## 8. Cron для напоминаний

В проекте есть Laravel Scheduler:

```text
tasks:send-reminders -> каждые 30 минут
```

В hPanel открой:

```text
Websites -> Dashboard -> Cron Jobs
```

Создай cron (можно также через Hostinger MCP):

```text
Type: Custom
Command: /opt/alt/php84/usr/bin/php /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan schedule:run
Schedule: every minute
```

Cron лучше создавать после заливки кода — иначе задача будет падать каждую
минуту, пока нет `artisan`. Если путь к PHP 8.4 другой, проверь `php -v` и
уточни путь в hPanel.

Hostinger считает расписание cron в UTC+0. Для Laravel это нормально: cron
запускает scheduler каждую минуту, а приложение само использует
`APP_TIMEZONE=Europe/Kyiv`.

Уведомления Email/Telegram ставятся в очередь (`ShouldQueue`). На shared hosting
добавь второй cron (каждую минуту):

```text
Type: Custom
Command: /opt/alt/php84/usr/bin/php /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan queue:work --stop-when-empty --tries=3 --timeout=90
Schedule: every minute
```

Постоянные фоновые процессы на обычном shared hosting лучше не держать.

### Telegram webhook (после выдачи токена бота)

В `.env` задай `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`
(случайная строка). Затем один раз зарегистрируй webhook:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://task.avant.od.ua/telegram/webhook" \
  -d "secret_token=<TELEGRAM_WEBHOOK_SECRET>"
```

Для SMTP в `.env` замени `MAIL_MAILER=log` на реальные SMTP-реквизиты Workspace.

## 9. Google SSO

Если включаешь Google SSO, в Google Cloud Console укажи Redirect URI:

```text
https://task.avant.od.ua/auth/google/callback
```

В `.env`:

```dotenv
GOOGLE_SSO_ENABLED=true
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

После изменения `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
```

## 10. Финальная проверка в браузере

Открой:

```text
https://task.avant.od.ua/login
```

Проверь рабочие страницы:

```text
https://task.avant.od.ua/dashboard
https://task.avant.od.ua/tasks
https://task.avant.od.ua/admin/users
https://task.avant.od.ua/admin/settings
https://task.avant.od.ua/admin/audit
```

Проверь, что служебные файлы недоступны:

```text
https://task.avant.od.ua/.env
https://task.avant.od.ua/composer.json
https://task.avant.od.ua/storage/logs/laravel.log
https://task.avant.od.ua/vendor/autoload.php
```

Должно быть `404` или `403`, но не `200`.

Проверь пользовательский сценарий:

1. Войти админом.
2. Создать пользователя.
3. Создать задачу.
4. Загрузить вложение.
5. Добавить комментарий.
6. Проверить уведомления.
7. Проверить импорт Excel в `/admin/import`, если он нужен.

## Быстрый чек-лист

- [ ] Laravel app лежит вне `public_html` (в `tasktracker_app`).
- [ ] В `public_html` только публичные файлы и шаблонный `index.php`.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] `APP_URL=https://task.avant.od.ua` без `/public`.
- [ ] DNS-запись `task` добавлена в Cloudflare, SSL в режиме Full/Full (strict).
- [ ] `TrustProxies` настроен (за Cloudflare).
- [ ] PHP 8.4+ в SSH/cron (`/opt/alt/php84/usr/bin/php` при необходимости).
- [ ] MySQL база создана (`u715639661_tasktracker` / `u715639661_task`).
- [ ] `composer install --no-dev --optimize-autoloader` выполнен.
- [ ] `php artisan migrate --force` выполнен.
- [ ] `php artisan tasks:backfill-plain-text` выполнен (можно сначала `--dry-run`).
- [ ] `php artisan db:seed --force` выполнен (новая пустая база).
- [ ] `public/build` загружен в `public_html/build`.
- [ ] Cron `schedule:run` запускается каждую минуту.
- [ ] Cron `queue:work --stop-when-empty` запускается каждую минуту (Email/Telegram).
- [ ] `TELEGRAM_BOT_TOKEN` / webhook на `/telegram/webhook` настроены (если нужен Telegram).
- [ ] SMTP настроен (`MAIL_MAILER` не `log` в production).
- [ ] `/.env`, `/composer.json`, `/storage/logs/laravel.log`, `/vendor/autoload.php`
      не доступны из браузера.
