# psytalk.pro — контекст проекта для Claude

Онлайн-платформа психологической помощи (психологи для тех, кто работает удалённо).
Статические HTML-страницы + PHP-API + MySQL на хостинге reg.ru.

## Ветка и деплой
- Работать только в ветке **`claude/psytalk-pro-dev-onsqnt`**.
- Деплой автоматический: **push в эту ветку → GitHub Actions (`.github/workflows/deploy-ftp.yml`) заливает файлы по SFTP** на прод `https://psytalk.pro`.
- ⚠️ **Деплоятся только файлы, ЯВНО перечисленные в `deploy-ftp.yml`.** Создал новый файл (html/js/api) — обязательно добавь строку `put ...` в workflow, иначе он не попадёт на сервер.
- Коммить и пушь только когда изменения готовы. PR не создавать без явной просьбы.

## Проверка на бою
- reg.ru кэширует — проверяй с обходом кэша: `curl -s "https://psytalk.pro/<файл>?x=$RANDOM"`.
- Пути вида `admin*` защищены анти-ботом reg.ru (кука RCPC) — curl’ом не пройти, это нормально; единая админка — `dashboard-admin.html`.

## Секреты и server-only файлы (ИХ НЕТ В РЕПО — не пытайся читать/деплоить)
- `api/config.php`, `api/db.php`, `api/auth.php`, `api/messages.php`, `api/payment.php`, `api/settings.php`, `api/users.php` — живут только на сервере.
- Секреты **никогда не коммитить**. Ключи — в конфигах вне git: `api/robokassa_config.php`, `api/dev_tasks_config.php` (см. `.gitignore`). Есть `*_config.sample.php` как шаблоны.

## Соглашения по коду
- API-эндпоинты — самостоятельные PHP-файлы: подключают `config.php` (+ `db.php` через function_exists-резолвер `getDB/getDbConnection/getPDO`), всё в try/catch, `CREATE TABLE IF NOT EXISTS` для своих таблиц.
- Таблица настроек: **`settings(key_name, value)`**. Комиссия платформы — `platform_commission` (%). Цены — `price_self/couple/teen`, промо — `promo_*`.
- Таблицы: `users(id,role,first_name,last_name,email,...)`, `psychologists(id,user_id,price,is_approved,...)`, `appointments(id[UUID],client_id,psychologist_id,date_time,duration,format,status,price)`, `payments(id[UUID],appointment_id,amount,status,paid_at)`.
- Стиль/бренд: фиолетовый `#7C3AED`, шрифт Inter. Единая шапка/подвал — `js/layout.js` (`psyWriteHeader()`, `psyWriteFooter()`).
- Каждый коммит заканчивать трейлерами Co-Authored-By и Claude-Session (как в истории).

## Автономная обработка задач (Routine «Задачи Клоду»)
Очередь доработок ведётся в админке (вкладка «🤖 Задачи Клоду») через `api/dev_tasks.php`.
Алгоритм прогона (токен передаётся в промте расписания, НЕ хранить в репозитории):
1. `heartbeat` state=running.
2. Забрать задачи: `GET /api/dev_tasks.php?action=list&status=pending&token=<TOKEN>`.
   - У задачи могут быть поля `attachments` (картинки к задаче) и `admin_attachments` (картинки к доработке, статус `rework`) — JSON-массивы ссылок. ОБЯЗАТЕЛЬНО скачай каждую (`curl -s -o <файл> "https://psytalk.pro<url>?x=$RANDOM"`) и посмотри через Read перед реализацией — часто суть задачи/правки именно на скрине. Для `rework` также учитывай `admin_comment`.
3. По каждой: реализовать в ветке, добавить новые файлы в `deploy-ftp.yml`, закоммитить+запушить, проверить на бою.
4. Вернуть статус: `POST action=claude-update {id, status:'done'|'in_progress', claude_comment}`. Если задача рискованная/непонятная — НЕ гадать: `in_progress` + комментарий-вопрос.
5. В конце — `heartbeat` state=idle (или `limited`, если упёрлись в лимит), с note и processed.
