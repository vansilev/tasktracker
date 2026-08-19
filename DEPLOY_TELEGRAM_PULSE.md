# Выкладка Telegram-уведомлений в топик Pulse

Runbook для разового выката уведомлений Task Tracker в тему **Task Tracker | Pulse**
форума ⚖ AVANT. Состояние прода проверено live **2026-08-13**.

Общий деплой приложения описан в [DEPLOY_HOSTINGER.md](DEPLOY_HOSTINGER.md); здесь
только то, что относится к уведомлениям.

> **Статус: выполнено 2026-08-13.** Код выложен, миграция применена, `.env` заполнен,
> webhook зарегистрирован, cron очереди создан, 14 сотрудникам проставлены Telegram ID.
> Документ оставлен как инструкция для повторного применения (например, на VPS) и как
> описание отката. Что осталось: сотрудники нажимают «Привязать Telegram», и нужно
> подтвердить 7 неоднозначных соответствий из раздела 11.

## 1. Что уже проверено на проде

| Что | Состояние |
|---|---|
| Хост | `ssh task-avant` → `185.210.234.44:65002`, пользователь `u715639661` |
| Каталог приложения | `/home/u715639661/domains/task.avant.od.ua/tasktracker_app` |
| Git на проде | нет, деплой = загрузка файлов (`rsync` установлен) |
| PHP | `/opt/alt/php84/usr/bin/php` 8.4.21 (системный `php` — 8.3, composer/artisan на нём падают) |
| Код Pulse | **не выложен**: нет `TelegramGroupNotifier`, `TelegramMentionFormatter`, `app/Jobs/` |
| `.env` | **нет ни одной** переменной `TELEGRAM_*` |
| Миграция `telegram_username` | **не применена**, колонки в `users` нет |
| Бот | Herald `@avant_herald_bot`, участник форума, писать в тему может (тест проходил) |
| Webhook бота | **не установлен** (`getWebhookInfo` → пустой `url`), `/start` не работает |
| Очередь | `QUEUE_CONNECTION=database`, **воркер не запускается**: 158 задач с 2026-08-10, `attempts=0` |
| Планировщик | работает, `tasks:send-reminders` отрабатывал 2026-08-13 00:00 |
| Telegram ID сотрудников | проставлены вручную 14 точным совпадениям (2026-08-13) |

Главный блокер — **мёртвый cron очереди**. Сообщения в Pulse отправляются джобой,
поэтому без воркера они просто лягут в `jobs` и не уйдут.

## 2. Что выливаем

Прод сейчас соответствует рабочей копии на 2026-08-07 (правки редактора уже там).
Расхождения проверены `rsync -rcn`, ниже — полный список файлов Telegram.

**Изменённые:**

```text
app/Http/Controllers/TelegramWebhookController.php
app/Models/User.php
app/Services/TaskNotificationService.php
app/Services/TaskService.php
app/Services/TaskWorkflowService.php
app/Services/TelegramLinkService.php
config/services.php
lang/ru/notification.php
lang/en/notification.php
lang/uk/notification.php
resources/views/livewire/profile/notification-preferences.blade.php
resources/views/livewire/profile/telegram-link.blade.php
```

**Новые:**

```text
app/Console/Commands/SendTelegramGroupTest.php
app/Jobs/SendTelegramGroupMessage.php
app/Services/TelegramGroupMessageBuilder.php
app/Services/TelegramGroupNotifier.php
app/Services/TelegramMentionFormatter.php
database/migrations/2026_08_13_000001_add_telegram_username_to_users.php
```

**Не выливаем:** `resources/js/rich-text-editor.js` — там незавершённая правка
редактора, к уведомлениям отношения не имеет и потребовала бы пересборки Vite.
Ассеты не трогаем вообще.

Локальные изменения ещё не в git. Перед выкладкой их стоит закоммитить, иначе на
проде окажется код, которого нет ни в одной ветке.

## 3. Подготовка

Прогнать тесты и линтер локально:

```bash
cd /Users/gm/AT/tasktracker
php artisan test
vendor/bin/pint --test app/Services/TelegramGroupMessageBuilder.php app/Services/TelegramGroupNotifier.php app/Services/TelegramMentionFormatter.php app/Jobs/SendTelegramGroupMessage.php
```

Ожидаем 337 passed и чистый Pint.

Telegram в тестах заглушён через `phpunit.xml` (`TELEGRAM_GROUP_ENABLED=false`,
пустой токен). Заглушки убирать нельзя: очередь в тестах синхронная, и часть тестов
создаёт задачи без подмены HTTP — без изоляции прогон отправляет десятки сообщений
в живой топик Pulse. Один раз так и случилось 2026-08-13, сообщения потом удалялись
ботом через `deleteMessages`.

Бэкап того, что перезапишем, и дамп базы:

```bash
ssh task-avant 'APP=/home/u715639661/domains/task.avant.od.ua/tasktracker_app
cd "$APP" && tar czf ~/backup_before_pulse_$(date +%F_%H%M).tar.gz \
  app/Http/Controllers/TelegramWebhookController.php app/Models/User.php \
  app/Services/TaskNotificationService.php app/Services/TaskService.php \
  app/Services/TaskWorkflowService.php app/Services/TelegramLinkService.php \
  config/services.php lang resources/views/livewire/profile/notification-preferences.blade.php .env
mysqldump -u u715639661_task -p u715639661_tasktracker users > ~/users_before_pulse_$(date +%F).sql'
```

## 4. Разобрать очередь и включить воркер

Делаем **до** включения группы, чтобы отделить старый долг от новых сообщений.

Сначала разово прогнать накопившиеся 158 задач:

```bash
ssh task-avant '/opt/alt/php84/usr/bin/php \
  /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan \
  queue:work --stop-when-empty --tries=3 --timeout=90'
```

Это долг за 10–13 августа: уведомления в колокольчик и почта. `MAIL_MAILER=log`,
поэтому письма никуда не уйдут, а личные Telegram-сообщения в этих задачах не
заложены — на момент их постановки ни у кого не было `telegram_chat_id`.
Если разбирать долг не хочется, альтернатива — `queue:clear database` (задачи
удалятся безвозвратно).

Затем в hPanel → **Advanced → Cron Jobs** добавить запуск каждую минуту:

```text
/opt/alt/php84/usr/bin/php /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan queue:work --sleep=1 --tries=3 --timeout=60 --max-time=55
```

Ключевой момент — **без** `--stop-when-empty`: воркер живёт 55 секунд и опрашивает
очередь раз в секунду, поэтому уведомление уходит примерно за 1 секунду, а не по
минутному расписанию. Hostinger сам оборачивает cron в `flock` по uid задания, так
что второй воркер параллельно не поднимется; между сменами воркеров остаётся окно
около 5 секунд — это худший случай задержки.

Проверить, что cron живой: создать задачу в трекере и через пару минут убедиться,
что `jobs` пустеет.

```bash
ssh task-avant '/opt/alt/php84/usr/bin/php \
  /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan \
  tinker --execute="echo DB::table(\"jobs\")->count();"'
```

Живой воркер видно в процессах:

```bash
ssh task-avant 'ps -eo etimes,cmd | grep "[q]ueue:work"'
```

## 5. Загрузить код

Сначала сухой прогон, он должен показать ровно 17 файлов из раздела 2:

```bash
cd /Users/gm/AT/tasktracker
rsync -avn --relative \
  app/Http/Controllers/TelegramWebhookController.php \
  app/Models/User.php \
  app/Services/TaskNotificationService.php \
  app/Services/TaskService.php \
  app/Services/TaskWorkflowService.php \
  app/Services/TelegramLinkService.php \
  app/Services/TelegramGroupMessageBuilder.php \
  app/Services/TelegramGroupNotifier.php \
  app/Services/TelegramMentionFormatter.php \
  app/Jobs/SendTelegramGroupMessage.php \
  app/Console/Commands/SendTelegramGroupTest.php \
  config/services.php \
  lang/ru/notification.php lang/en/notification.php lang/uk/notification.php \
  resources/views/livewire/profile/notification-preferences.blade.php \
  database/migrations/2026_08_13_000001_add_telegram_username_to_users.php \
  task-avant:/home/u715639661/domains/task.avant.od.ua/tasktracker_app/
```

Убрать `-n` и выполнить повторно.

## 6. Настроить `.env` на проде

Секреты не передаём через командную строку (видно в `ps`), правим редактором:

```bash
ssh task-avant
cd /home/u715639661/domains/task.avant.od.ua/tasktracker_app
nano .env
```

Добавить блок:

```dotenv
TELEGRAM_BOT_TOKEN=<токен Herald из BotFather>
TELEGRAM_BOT_USERNAME=avant_herald_bot
TELEGRAM_WEBHOOK_SECRET=<новый секрет только для прода>
TELEGRAM_DM_ENABLED=false
TELEGRAM_GROUP_ENABLED=true
TELEGRAM_GROUP_CHAT_ID=-1001970597297
TELEGRAM_GROUP_MESSAGE_THREAD_ID=11252
TELEGRAM_GROUP_TAG_ASSIGNEE_ON_COMMENT=true
```

Секрет для webhook сгенерировать так:

```bash
openssl rand -base64 32 | tr -dc 'A-Za-z0-9_-' | cut -c1-32
```

`TELEGRAM_DM_ENABLED=false` держим выключенным: бот не пишет в личку, только в тему.
Без `TELEGRAM_GROUP_MESSAGE_THREAD_ID` джоба не отправляет сообщение и пишет
предупреждение в лог — в General ничего не улетит.

## 7. Миграция и кеши

```bash
ssh task-avant 'APP=/home/u715639661/domains/task.avant.od.ua/tasktracker_app
PHP=/opt/alt/php84/usr/bin/php
$PHP "$APP/artisan" migrate --force
$PHP "$APP/artisan" optimize:clear
$PHP "$APP/artisan" config:cache
$PHP "$APP/artisan" route:cache
$PHP "$APP/artisan" view:cache
$PHP "$APP/artisan" event:cache
$PHP "$APP/artisan" migrate:status | tail -3'
```

Конфиг на проде кешируется, поэтому `config:cache` после правки `.env` обязателен —
иначе новые `TELEGRAM_*` приложение не увидит.

## 8. Webhook бота

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://task.avant.od.ua/telegram/webhook" \
  -d "secret_token=<TELEGRAM_WEBHOOK_SECRET>"

curl -s "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getWebhookInfo"
```

Секрет должен совпадать с `.env`: контроллер сверяет заголовок
`X-Telegram-Bot-Api-Secret-Token` и иначе отвечает 403. В `getWebhookInfo` ждём
непустой `url` и `pending_update_count: 0`.

Порядок важен: webhook включаем **после** миграции, иначе первый же `/start`
упадёт на отсутствующей колонке `telegram_username`.

## 9. Проверка

```bash
ssh task-avant 'APP=/home/u715639661/domains/task.avant.od.ua/tasktracker_app
/opt/alt/php84/usr/bin/php "$APP/artisan" telegram:group-test
sleep 5
grep -i "telegram" "$APP/storage/logs/laravel.log" | tail -5'
```

Отдельно гонять `queue:work` не нужно: постоянный воркер из шага 4 забирает задание
примерно за секунду.

- В Pulse появилось тестовое сообщение от Herald.
- В логе нет строк `Telegram group message failed` и `... skipped`.
- Создать реальную задачу на кого-то из 14 привязанных: в Pulse приходит одно
  сообщение, исполнитель именно упомянут (тап по имени открывает профиль), а не
  просто написан текстом.
- Оставить комментарий: постановщика и исполнителя тегает, автора — нет.
- Профиль → уведомления: галочка Telegram недоступна, подпись объясняет, что
  уведомления идут в общую группу.

## 10. Откат

Быстрое отключение без выкладки кода:

```bash
ssh task-avant 'APP=/home/u715639661/domains/task.avant.od.ua/tasktracker_app
sed -i "s/^TELEGRAM_GROUP_ENABLED=true/TELEGRAM_GROUP_ENABLED=false/" "$APP/.env"
/opt/alt/php84/usr/bin/php "$APP/artisan" config:cache'
```

Полный откат: распаковать `~/backup_before_pulse_*.tar.gz` поверх приложения,
удалить пять новых файлов и `app/Jobs/`, снять webhook
(`deleteWebhook`) и заново прогреть кеши. Миграция аддитивная — колонку
`telegram_username` можно оставить, она ничему не мешает.

## 11. Привязать Telegram ID сотрудникам

Выполнять **только после раздела 7**. На старом коде `resolveChannels()` включает
личный Telegram-канал по факту заполненного `telegram_chat_id`, поэтому проставленный
заранее ID приводит к падению задач с `CouldNotSendNotification: You must provide your
telegram bot token`. Выключатель `TELEGRAM_DM_ENABLED` появляется только с новым кодом.

Соответствия выверены по участникам форума ⚖ AVANT 2026-08-13:

```bash
ssh task-avant '/opt/alt/php84/usr/bin/php \
  /home/u715639661/domains/task.avant.od.ua/tasktracker_app/artisan tinker --execute="
\$map = [
    1 => \"358999630\",   // Максим Гольдт        @maxim_goldt
    2 => \"396881217\",   // Юлия Васильева       @rozhdestvo73
    4 => \"1296304788\",  // Колотуп Татьяна      без username
    6 => \"1302017665\",  // Григоренко Лиза      @lizagrigorenko
    9 => \"786556366\",   // Саламаха Сергей      @Salardo1
    10 => \"437241416\",  // Криворучко Анастасия @anastasiia_kry
    11 => \"579151940\",  // Фадеева Виктория     @vicctoryyy
    12 => \"582102299\",  // Петрова Яна          @yana_petr0va
    13 => \"390759483\",  // Зинаида Федоровна    без username
    16 => \"8863334506\", // Людмила Антоновна    без username
    17 => \"1754696277\", // Денис                @D_i_Rh
    19 => \"492015821\",  // Артем Сонько         @sonko_artem
    20 => \"358405694\",  // Дмитрий              @amithaver
    21 => \"283348659\",  // Павел                @bonddesign
    15 => \"1618102230\", // Буряковская Анна     @anna_belka_2806 (подтверждено 2026-08-13)
    18 => \"1060002379\", // Наумов Александр     @Alessandro90210 (подтверждено 2026-08-13)
];
foreach (\$map as \$id => \$chatId) {
    App\\Models\\User::query()->whereKey(\$id)->update([\"telegram_chat_id\" => \$chatId]);
}
echo App\\Models\\User::query()->whereNotNull(\"telegram_chat_id\")->count();
"'
```

Из неоднозначных пар владельцев подтвердил заказчик: аккаунт `@anna_belka_2806` — это
Буряковская Анна (id 15), `@Alessandro90210` — Наумов Александр (id 18). Значит вторые
из этих пар, Анна Николаевна (id 8) и Коханский Александр (id 3), в форуме отсутствуют.

Остаются без ID: Коханский Александр (3), Назарова Ирина (5), Бабкина Наталья (7),
Анна Николаевна (8), Ирина Викторовна (14). По Ирине в форуме один аккаунт на двоих,
по Наталье — три однофамильных кандидата; угадывать нельзя, потому что чужой ID
пингует не того человека. Правильный путь — «Привязать Telegram» в профиле: `/start`
заполняет и `telegram_chat_id`, и `telegram_username` без ручного сопоставления.
До привязки такие люди появляются в сообщениях обычным текстом, без пинга.

## 12. После выкладки

- Разослать сотрудникам ссылку «Привязать Telegram» в профиле: `/start` заполняет
  `telegram_chat_id` и `telegram_username`, после этого теги работают у всех.
- У 16 человек ID уже проставлены вручную, им `/start` нужен только для
  `telegram_username` (страховка, если сменят настройки приватности).
- Без ID остались пятеро: Коханский Александр (3), Назарова Ирина (5), Бабкина
  Наталья (7), Анна Николаевна (8), Ирина Викторовна (14) — им нужно привязаться
  самим через профиль. До привязки они появляются в сообщениях обычным текстом,
  без пинга.
- Напоминания о дедлайне, просрочка и SLA-эскалации в Pulse не пишутся — это
  по-прежнему только колокольчик и почта.

## 13. Формат сообщений

Собирается в `TelegramGroupMessageBuilder`. Эмодзи живут в коде, тексты — в
`lang/*/notification.php` (ключи `group.*`), поэтому перевод не может сломать значки.

Значок в заголовке зависит от события, а для смены статуса — от нового статуса:

| Событие / статус | Значок |
|---|---|
| Новая задача | 🆕 |
| Переназначение | 🔄 |
| Комментарий | 💬 |
| В работе | ▶️ |
| Ожидает данных | ⏳ |
| На проверке | 🔍 |
| На доработку | ↩️ |
| Выполнена | ✅ |
| Отложена | ⏸ |
| Отклонена | 🚫 |
| Отменена | ❌ |

Строки с тегами помечены ролью: 🙋 постановщик, 🎯 исполнитель, 👀 наблюдатель,
📣 упомянутый в комментарии.

Тело сообщения о новой задаче и о переназначении содержит текст задачи (📝, plain-text
из `description_text`, обрезка на 400 символов) и блок с постановщиком (👤),
приоритетом и дедлайном (🗓).

Приоритет размечен по уровням: 🔥 9–10 критический, 🟠 7–8 высокий, 🟡 4–6 средний,
🟢 1–3 низкий. В сообщениях о смене статуса и о комментарии строка приоритета
показывается только от 7 и выше (`PRIORITY_HIGHLIGHT_FROM`), чтобы рутинная
переписка не разрасталась.
