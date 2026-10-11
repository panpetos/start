# Переезд psytalk.pro на ВДС — runbook

Пошаговый план миграции с shared-хостинга reg.ru на собственный ВДС. Внутренний
документ, **не деплоится** (нет в `deploy-ftp.yml`). Файлы-заготовки к этому плану
лежат в `deploy/` (`nginx.conf.sample`, `crontab.sample`) и в `api/*_config.sample.php`.

> Заготовки безопасны для текущего прода: на reg.ru они не заливаются и ни на что
> не влияют. Всё «жёсткое» (security-заголовки, CORS-вайтлист, CSP, лимиты) живёт
> в nginx/`config.php` — их нет в git, поэтому и делается это на ВДС, а не сейчас.

---

## 0. Что уже готово в коде (можно не трогать)
- **Антифлуд приложения** — `api/rate_limit.php` (`psyRateLimit`), уже используется
  в опросах/SOS/автоответах. На ВДС ускорится с расширением **APCu**.
- **Снятие session-lock** — `session_write_close()` в read-only эндпоинтах.
- **Пуши пачками** — `pushSendMany` (curl_multi) в `push.php`.
- **Ретеншн файлов** — `api/storage_cleanup.php` (по refcount, не ломает пересланное).
- **Health-probe** — `api/health.php`: добавлены секции `ext` (расширения PHP) и
  `config_present` (наличие server-only конфигов) — по ним удобно проверять сервер
  сразу после переезда (см. §7).

## 1. Подготовка ВДС
- ОС: Ubuntu 22.04/24.04 LTS. Пользователь под сайт (не root).
- Пакеты: `nginx`, `php8.2-fpm`, `php8.2-mysql php8.2-mbstring php8.2-curl
  php8.2-gd php8.2-xml php8.2-zip php8.2-apcu`, `mariadb-server` (или внешний
  managed MySQL), `certbot python3-certbot-nginx`, `git`, `unzip`, `curl`.
- Проверить, что загружены расширения из `health.php → ext`: `pdo_mysql, mbstring,
  curl, openssl, json, gd, fileinfo` (+ желательно `apcu`).

## 2. База данных
1. На reg.ru снять дамп: `mysqldump --single-transaction --default-character-set=utf8mb4 <db> > dump.sql`.
2. На ВДС создать БД и пользователя (utf8mb4), залить дамп.
3. Проверить кодировку таблиц — весь проект на `utf8mb4`. Колонки настроек — `k`/`v`
   в таблице `settings` (читаются через `psySetting()`; напрямую по именам не ходить).

## 3. Код и файлы
1. Выкатывать из ветки `claude/psytalk-pro-dev-onsqnt` (или основной) в
   `/var/www/psytalk.pro`. Можно `git clone` + `git pull`, вместо SFTP-workflow.
2. **Server-only файлы, которых НЕТ в git** — создать вручную (ядро):
   `api/config.php`, `api/db.php`, `api/auth.php`, `api/messages.php`,
   `api/payment.php`, `api/settings.php`, `api/users.php`. Перенести с reg.ru.
3. **Конфиги-секреты** (из `.gitignore`) — перенести с reg.ru или пересоздать из
   шаблонов `api/*_config.sample.php`:
   `robokassa_config.php`, `yookassa_config.php`, `sber_acquiring_config.php`,
   `ai_chat_config.php`, `rtc_calls_config.php`, `notifications_config.php`,
   `notify_dispatch_config.php`, `dev_tasks_config.php`.
   Токены `notify_dispatch`/`dev_tasks` можно заново выставить из админки.
4. **Каталог загрузок** (`uploads/` или где лежат файлы чата/сторис) — перенести
   целиком, выставить владельца PHP-FPM и права на запись.
5. **Права**: код — только чтение для веб-пользователя; `uploads/` и каталог, куда
   пишутся `*_config.php` из админки, — на запись.

## 4. nginx + PHP + TLS
1. Взять `deploy/nginx.conf.sample`, поправить домен/пути/сокет PHP-FPM, создать
   `snippets/psy-php.conf` (пример в конце файла), `nginx -t`, перезагрузить.
2. В `nginx.conf` (http-контекст) добавить зоны лимитов `api`/`login` (см. шапку sample).
3. PHP (`php.ini`/пул FPM): `upload_max_filesize` и `post_max_size` синхронно с
   `client_max_body_size` (начать с 64M, **не 1G**); `session.cookie_secure=1`,
   `cookie_httponly=1`, `cookie_samesite=Lax`; включить `apcu`.
4. TLS: `certbot --nginx -d psytalk.pro -d www.psytalk.pro`. После проверки —
   включить **HSTS** (в sample закомментирован).

## 5. Плановые задачи (cron)
Взять `deploy/crontab.sample`. На shared-хостинге всё шло piggyback на трафике; на
ВДС завести настоящий cron: напоминания (`reminders.php?action=tick`, публично),
рассылка уведомлений (`notify_dispatch.php?action=run&token=…`), бэкапы БД.
`storage_cleanup`/`scheduled` требуют сессию — оставить piggyback или добавить token-путь.

## 6. Безопасность на ВДС (то, что было отложено)
Порядок — от простого к сложному, каждый шаг проверять в консоли браузера:
1. **Заголовки** — уже в `nginx.conf.sample` (X-Frame-Options, nosniff,
   Referrer-Policy, Permissions-Policy). Включить **HSTS** после стабильного HTTPS.
2. **CSP** — в sample закомментирована (у приложения много inline-обработчиков
   `onclick=…`; строгая политика без рефакторинга сломает сайт). Включать вариант с
   `'unsafe-inline'`, следя за консолью; строгую — отдельной задачей после выноса
   inline-хендлеров.
3. **CORS** — сейчас эндпоинты эхом отдают `Access-Control-Allow-Origin: <Origin>`
   с `credentials:true` (фактически «любой источник»). На ВДС сузить до `psytalk.pro`
   централизованно в `config.php` (он server-only — правится на месте), а из
   эндпоинтов убрать индивидуальные echo-заголовки. Запросы у нас same-origin, так
   что риска сломать нет — но тестировать чат/звонки после включения.
4. **Cloudflare** перед nginx — DDoS-щит, WAF, кэш. Не забыть `real_ip` (см. sample),
   иначе антифлуд и журнал входов увидят только IP Cloudflare.
5. **Скрытие текста в пушах** и прочий privacy-хардненинг — по желанию.

## 7. Смоук-тест после переезда
1. `GET https://psytalk.pro/api/health.php` — проверить:
   `ext.*` = true (все нужные расширения), `config_present.*` = true (все конфиги
   на месте), `db_ping_ms` маленький, `session.cookie_secure=1`.
2. Заголовки: `curl -sI https://psytalk.pro/` — есть X-Frame-Options, nosniff, HSTS.
3. Секреты закрыты: `curl -s -o /dev/null -w '%{http_code}' https://psytalk.pro/api/config.php`
   → 404; то же для `*_config.php`, `/.git/config`, `/ПАМЯТКА.md`.
4. Функции: вход, чат (сообщения/фото/голосовые/кружки), звонок (WebRTC),
   стикеры/опросы, оплата (тестовый платёж), пуши, админка `dashboard-admin.html`.
5. Cron: через несколько минут проверить, что напоминания/рассылка идут (логи).

## 8. Переключение и откат
- Уменьшить TTL DNS заранее. Переключить A-запись на ВДС (или включить Cloudflare
  proxy). Прогреть, проверить смоук-тест.
- reg.ru не выключать пару дней — быстрый откат по DNS, если что-то всплывёт.
- Автодеплой: заменить SFTP-workflow (`deploy-ftp.yml`) на `git pull` по SSH на ВДС
  (webhook/Action). До переезда workflow оставить как есть.

---

### Что из «отложенного до ВДС» закрывается этим планом
- security-заголовки, HSTS, CSP-заготовка, сужение CORS — §6 + `nginx.conf.sample`.
- защита от DDoS — Cloudflare + `limit_req` (§6, sample).
- лимит загрузки 1 ГБ → 64 МБ — §4.
- реальный cron вместо piggyback — §5 + `crontab.sample`.
- бэкапы БД — §5.
Инфраструктурное (managed MySQL, объектное хранилище, E2E-шифрование) — отдельными
задачами уже на ВДС, когда появится нагрузка.
