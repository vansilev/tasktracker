# Task Tracker AVANT

Корпоративный таск-трекер TCS AVANT.  
ТЗ: `../kommo/docs/tasktracker/TZ.md` (v1.3)

## Статус: фазы 0–6 + настройки, аудит, in-app уведомления, напоминания

Реализованы фазы 0–6 и дополнительные MVP-блоки (без доставки Email/Telegram и без полной production-настройки Google SSO):

| Фаза / блок | Содержание |
|-------------|------------|
| **0** | Laravel 13 + Livewire 3 + Breeze, MySQL, i18n RU/UA/EN, layout, Apache, заготовка Google SSO |
| **1** | Пользователи, подразделения, роли/права/видимость, категории, админка `/admin` |
| **2** | Задачи: CRUD, заголовок, workflow, приоритет, комментарии (@mentions), чек-листы, история, вложения к задаче, Markdown |
| **3** | Авто-распределение (round-robin), наблюдатели, импорт Excel (`tasks:import-excel`, команда) |
| **4** | Вложения: множественная загрузка при создании/в карточке/к комментариям, вывод под комментариями, удаление (uploader/редактор/админ) с файлом на диске и записью в историю |
| **5** | Список задач: фильтры (исполнитель, инициатор, период создания/дедлайна, только просроченные), сортировка (приоритет/дедлайн/дата/статус + направление), сброс, автораскрытие панели; кросс-отдельное переназначение в карточке с обязательным комментарием |
| **6** | Дашборд (`DashboardService`): открытые по статусам, просроченные, «на проверке у меня», срочные, мои задачи; Chart.js — по подразделениям (создано/выполнено) и по категориям; среднее время закрытия; фильтр периода (неделя/месяц/квартал/произвольный) |
| **Настройки** | `SettingsService` (БД → fallback `config/tasktracker.php`), страница `/admin/settings` |
| **Аудит** | Журнал admin-действий (роли, подразделения, категории, настройки, пользователи, импорт), страница `/admin/audit` |
| **In-app уведомления** | Database channel, колокольчик в navigation (desktop + mobile), тексты ru/uk/en |
| **Напоминания** | `tasks:send-reminders`, расписание каждые 30 минут (`routes/console.php`) |
| **Профиль** | Матрица предпочтений уведомлений 7×3 (In-app работает; Email/Telegram сохраняются, доставка позже); email read-only (меняет админ); форма самоудаления аккаунта удалена (ТЗ 3.4) |
| **Упоминания** | Кириллица и `@nick` в парсере; автодополнение сотрудников в комментариях (Alpine + Livewire) |
| **История** | `TaskHistoryPresenter` — имена вместо id, локализованные статусы |
| **Импорт UI** | `/admin/import` — загрузка .xlsx, обязательный dry-run, импорт после проверки, аудит `tasks.imported` |

**Не входит в текущую приёмку:** доставка Email/Telegram-каналов, production credentials Google SSO, планировщик Windows на production-сервере, фаза 9 (финальное тестирование, мобильная вёрстка, деплой на Hostinger).

## Аутентификация

- **Google SSO:** маршруты готовы; включение через `/admin/settings` или `GOOGLE_SSO_ENABLED` в `.env` (нужны OAuth credentials)
- **Логин/пароль:** включается в `/admin/settings`; guard «нельзя выключить оба способа входа»
- **Деактивированные пользователи:** middleware `EnsureUserIsActive` завершает живые сессии и remember-me
- **Открытая регистрация отключена** — новых пользователей создаёт админ или SSO-онбординг
- Первый админ: `crm.manager@tcsavant.com` (seeder, только для новой установки)

## Админка

| Маршрут | Назначение |
|---------|------------|
| `/admin/users` | Пользователи |
| `/admin/departments` | Подразделения, руководители, очередь auto-assign |
| `/admin/roles` | Роли доступа |
| `/admin/categories` | Категории задач |
| `/admin/settings` | SSO, пароль, домены email, SLA, лимит вложений |
| `/admin/import` | Импорт задач из Excel (.xlsx) с dry-run |
| `/admin/audit` | Журнал аудита с фильтрами и пагинацией |

## Требования

- PHP 8.3+
- Composer, Node.js
- MySQL 8.0 / MariaDB
- Apache 24 с `mod_rewrite`

## Быстрый старт

```bash
cd C:\Apache24\htdocs\tasktracker
composer install
npm install && npm run build
php artisan migrate
```

**Только для новой установки** (пустая БД):

```bash
php artisan db:seed
```

На рабочей БД с живыми данными **не** используйте `migrate:fresh`, `migrate:refresh` и `db:wipe`.

### Apache

```
Alias /tasktracker "C:/Apache24/htdocs/tasktracker/public"
<Directory "C:/Apache24/htdocs/tasktracker/public">
    AllowOverride All
    Require all granted
</Directory>
```

URL: http://localhost/tasktracker/public/login

Альтернатива: `php artisan serve` → http://127.0.0.1:8000/login

## Планировщик (Windows)

In-app напоминания (`tasks:send-reminders`) выполняются по расписанию Laravel — **каждые 30 минут** (`Schedule::command('tasks:send-reminders')->everyThirtyMinutes()`).

Без запущенного планировщика напоминания не отправляются. В **Планировщике задач Windows** создайте задание:

- **Программа:** `C:\path\to\php.exe` (ваш PHP 8.3+)
- **Аргументы:** `C:\Apache24\htdocs\tasktracker\artisan schedule:run`
- **Рабочая папка:** `C:\Apache24\htdocs\tasktracker`
- **Триггер:** каждую **1 минуту**

Проверка без отправки:

```bash
php artisan tasks:send-reminders --dry-run
php artisan schedule:list
```

## Вход

| Параметр | Значение |
|----------|----------|
| Email | `crm.manager@tcsavant.com` |
| Пароль | из `.env` → `ADMIN_PASSWORD` |

## Google SSO (опционально)

1. OAuth Client в [Google Cloud Console](https://console.cloud.google.com/)
2. Redirect URI: `http://localhost/tasktracker/public/auth/google/callback`
3. В `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
4. Включить в `/admin/settings` или `GOOGLE_SSO_ENABLED=true`

## Тесты

```bash
php artisan test
```

Покрыты: RBAC/видимость, workflow, инварианты задач, обновление полей, lifecycle пользователей, деактивация сессий, настройки, аудит, in-app уведомления, напоминания, вложения, дашборд, фильтры списка, переназначение, предпочтения уведомлений, @упоминания, история, импорт Excel UI, smoke-маршруты, REST API, MCP.

## Ключевая структура

```
app/Policies/TaskPolicy.php              — матрица прав задач (п. 2.5 ТЗ)
app/Services/TaskService.php             — CRUD, комментарии, чек-листы
app/Services/TaskWorkflowService.php     — переходы статусов
app/Services/TaskNotificationService.php — in-app уведомления и напоминания
app/Services/DashboardService.php        — виджеты и диаграммы дашборда
app/Services/TaskAttachmentService.php   — upload/download/delete вложений
app/Services/TaskHistoryPresenter.php    — человекочитаемая история
app/Services/MentionService.php          — @mentions (кириллица, @nick)
app/Services/ExcelTaskImportService.php  — импорт Excel (команда и UI)
app/Services/SettingsService.php         — настройки (БД → config)
app/Services/UserLifecycleService.php    — пользователи, перевод между отделами
app/Services/AuditLogService.php         — журнал админ-действий
app/Services/TaskApiService.php          — REST + MCP (список, карточка, create/comment/transition)
app/Mcp/Servers/TaskTrackerServer.php    — MCP-сервер `/mcp`
app/Http/Middleware/EnsureUserIsActive.php
app/Console/Commands/SendTaskReminders.php
app/Notifications/Task*.php              — классы уведомлений
config/tasktracker.php                   — fallback-настройки
resources/views/livewire/pages/dashboard.blade.php
resources/views/livewire/pages/admin/import.blade.php
resources/views/livewire/profile/notification-preferences.blade.php
resources/views/livewire/layout/notifications.blade.php
storage/app/private/attachments/           — файлы вне public
```

## API и MCP

Auth: Laravel Sanctum (Bearer). В URL задач — публичный **номер** (`#224`), не внутренний id.

```bash
php artisan tasktracker:issue-api-token you@tcsavant.com --name=cursor-mcp
```

Либо `POST /api/v1/auth/token` с email и паролем.

| Метод | Путь | Назначение |
|-------|------|------------|
| POST | `/api/v1/auth/token` | Выдать токен |
| GET | `/api/v1/me` | Текущий пользователь |
| GET | `/api/v1/tasks` | Список (`q`, `tab`, `status`, `open`, `assignee_email`, …) |
| GET | `/api/v1/tasks/{number}` | Карточка |
| POST | `/api/v1/tasks` | Создать |
| POST | `/api/v1/tasks/{number}/comments` | Комментарий |
| POST | `/api/v1/tasks/{number}/transition` | Сменить статус |
| GET | `/api/v1/users` | Сотрудники |
| GET | `/api/v1/catalogs` | Отделы, категории, статусы |
| POST | `/mcp` | MCP (тот же Bearer) |

Cursor → MCP:

```json
{
  "task-avant": {
    "url": "https://task.avant.od.ua/mcp",
    "headers": {
      "Authorization": "Bearer <token>"
    }
  }
}
```

Локально: `http://127.0.0.1:8000/mcp`. На прод API/MCP попадают только после выката.

## Следующие этапы

- **Email / Telegram** — каналы доставки (нужны SMTP и токен бота; предпочтения пользователей уже сохраняются)
- **Инфраструктура** — планировщик Windows на сервере, production credentials Google SSO
- **Фаза 9** — финальное тестирование, мобильная вёрстка, деплой на Hostinger (инструкция — в handoff)
