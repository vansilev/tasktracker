# Деплой Task Tracker AVANT на Hostinger VPS

Сервер:

```text
IP: 217.196.49.242
OS: Ubuntu 22.04 with MERN Stack
Nginx: 1.18.0
PHP: не установлен
Composer: не установлен
MySQL/MariaDB: не установлен
Certbot: установлен
```

На VPS уже работает много сайтов через Nginx. Не ставь Apache и не
перезаписывай существующие файлы в `/etc/nginx/sites-available`. Task Tracker
нужно добавить отдельным сайтом, например:

```text
task.avant.od.ua
```

## 1. DNS

Создай A-запись:

```text
task.avant.od.ua -> 217.196.49.242
```

Проверка после обновления DNS:

```bash
dig +short task.avant.od.ua
```

Должно вернуть:

```text
217.196.49.242
```

## 2. Бэкап Nginx перед изменениями

```bash
mkdir -p /root/backups
tar -czf /root/backups/nginx-before-tasktracker-$(date +%F-%H%M).tar.gz /etc/nginx
nginx -t
```

## 3. Установить PHP 8.4, Composer и MariaDB

Не ставь стандартный `php-cli` из Ubuntu 22.04: это PHP 8.1, а проект требует
PHP 8.4+.

Важно: PHP 8.4 или новее обязателен. Зависимости в `composer.lock`
(в т.ч. Symfony 8.1) требуют `>=8.4.1` — ниже 8.4.1 проект не установится
и не запустится.

```bash
apt update
apt install -y software-properties-common ca-certificates lsb-release apt-transport-https unzip git curl
add-apt-repository ppa:ondrej/php -y
apt update

apt install -y \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath \
  php8.4-readline php8.4-opcache

apt install -y mariadb-server mariadb-client

curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
php8.4 /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
```

Проверка:

```bash
php8.4 -v
php -v || true
composer --version
systemctl status php8.4-fpm --no-pager
systemctl status mariadb --no-pager
```

Если `php` не указывает на 8.4, можно сделать:

```bash
update-alternatives --set php /usr/bin/php8.4
```

## 4. Создать базу

```bash
mariadb
```

Внутри MariaDB:

```sql
CREATE DATABASE tasktracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tasktracker_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON tasktracker.* TO 'tasktracker_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 5. Загрузить проект

Рекомендуемый путь:

```text
/var/www/tasktracker
```

Создать папку:

```bash
mkdir -p /var/www/tasktracker
chown -R root:www-data /var/www/tasktracker
```

Загрузи туда проект. Не загружай `node_modules`. `vendor` можно не загружать,
Composer установит зависимости на сервере.

После загрузки:

```bash
cd /var/www/tasktracker
composer install --no-dev --optimize-autoloader
```

Права:

```bash
mkdir -p storage/app/private/attachments
mkdir -p storage/app/private/import
mkdir -p storage/app/private/livewire-tmp
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

chown -R root:www-data /var/www/tasktracker
chmod -R 775 storage bootstrap/cache
```

## 6. Production `.env`

Создай:

```bash
nano /var/www/tasktracker/.env
```

Пример:

```dotenv
APP_NAME="Task Tracker AVANT"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://task.avant.od.ua
APP_TIMEZONE=Europe/Kyiv

APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
APP_FAKER_LOCALE=ru_RU

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tasktracker
DB_USERNAME=tasktracker_user
DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="noreply@tcsavant.com"
MAIL_FROM_NAME="${APP_NAME}"

VITE_APP_NAME="${APP_NAME}"

ALLOWED_EMAIL_DOMAINS=tcsavant.com
PASSWORD_LOGIN_ENABLED=true
GOOGLE_SSO_ENABLED=false
ADMIN_EMAIL=crm.manager@tcsavant.com
ADMIN_NAME="CRM Manager"
ADMIN_PASSWORD=CHANGE_THIS_ADMIN_PASSWORD

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=
```

Потом:

```bash
php8.4 artisan key:generate
php8.4 artisan migrate --force
php8.4 artisan tasks:backfill-plain-text
php8.4 artisan db:seed --force
php8.4 artisan optimize:clear
php8.4 artisan config:cache
php8.4 artisan route:cache
php8.4 artisan view:cache
php8.4 artisan event:cache
```

Миграция уже заполняет `description_text` / `body_text` при применении, но
команду стоит прогнать после деплоя (идемпотентна; `--dry-run` покажет,
сколько строк было бы обновлено без записи).

`db:seed --force` запускай только на новой пустой базе.

## 7. Nginx config

Создай новый файл. Не редактируй существующие сайты.

```bash
nano /etc/nginx/sites-available/task.avant.od.ua
```

Содержимое:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name task.avant.od.ua;

    root /var/www/tasktracker/public;
    index index.php index.html;

    access_log /var/log/nginx/tasktracker-access.log;
    error_log /var/log/nginx/tasktracker-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* \.(env|log|sql|sqlite|bak|backup)$ {
        deny all;
    }
}
```

Включи сайт:

```bash
ln -s /etc/nginx/sites-available/task.avant.od.ua /etc/nginx/sites-enabled/task.avant.od.ua
nginx -t
systemctl reload nginx
```

## 8. SSL

После того как DNS уже указывает на VPS:

```bash
certbot --nginx -d task.avant.od.ua
```

Выбери redirect HTTP -> HTTPS, если certbot спросит.

Проверка:

```bash
nginx -t
systemctl reload nginx
certbot renew --dry-run
```

## 9. Cron для Laravel Scheduler

```bash
crontab -e
```

Добавь строку:

```cron
* * * * * cd /var/www/tasktracker && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

Проверка:

```bash
cd /var/www/tasktracker
php8.4 artisan schedule:list
php8.4 artisan tasks:send-reminders --dry-run
```

## 10. Финальная проверка

```bash
cd /var/www/tasktracker
php8.4 artisan about
php8.4 artisan migrate:status
curl -I https://task.avant.od.ua/login
curl -I https://task.avant.od.ua/.env
curl -I https://task.avant.od.ua/composer.json
curl -I https://task.avant.od.ua/storage/logs/laravel.log
```

Для служебных файлов должен быть `403` или `404`, но не `200`.

## Важно

- Не устанавливать Apache.
- Не менять `/etc/nginx/sites-available/default`.
- Не удалять MERN/Mongo/Node.
- Не трогать существующие symlink в `/etc/nginx/sites-enabled`.
- Перед каждым `systemctl reload nginx` выполнять `nginx -t`.
