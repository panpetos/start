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
- Пути вида `admin*` защищены анти-ботом reg.ru (кука RCPC). **Голым curl не пройти, но с браузерным User-Agent — проходит:** `curl -s -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36" "https://psytalk.pro/dashboard-admin.html?x=$RANDOM"`. Так что правки в админке проверяемы на бою — не списывай их в «непроверяемо». Единая админка — `dashboard-admin.html`.

## Секреты и server-only файлы (ИХ НЕТ В РЕПО — не пытайся читать/деплоить)
- `api/config.php`, `api/db.php`, `api/auth.php`, `api/messages.php`, `api/payment.php`, `api/settings.php`, `api/users.php` — живут только на сервере.
- Секреты **никогда не коммитить**. Ключи — в конфигах вне git: `api/robokassa_config.php`, `api/dev_tasks_config.php` (см. `.gitignore`). Есть `*_config.sample.php` как шаблоны.

## Соглашения по коду
- API-эндпоинты — самостоятельные PHP-файлы: подключают `config.php` (+ `db.php` через function_exists-резолвер `getDB/getDbConnection/getPDO`), всё в try/catch, `CREATE TABLE IF NOT EXISTS` для своих таблиц.
- Таблица настроек: **`settings`**, но колонки на проде называются **`k`/`v`**, а НЕ `key_name`/`value`. Никогда не писать запрос по именам колонок напрямую — читать через `psySetting($pdo,$key,$default)` из **`api/settings_lib.php`** (он же определяет таблицу и колонки). Жёсткий `SELECT value ... WHERE key_name = ?` падает с «Unknown column 'value'»: где ошибку глушил try/catch, настройка молча читалась пустой, где не глушил — эндпоинт падал целиком. Комиссия платформы — `platform_commission` (%). Цены — `price_self/couple/teen`, промо — `promo_*`, промокод бесплатной записи — `free_promo_code`/`free_promo_limit`.
- ⚠️ **Служебная запись НИКОГДА не должна стоять внутри `try`, который возвращает данные.** Отметки о прочтении, счётчики, аудит, обновление схемы — это побочная работа; если она падает, человек всё равно должен получить то, за чем пришёл. Ставить их ПОСЛЕ `out()`/`echo` нельзя (до них не дойдёт), поэтому выносить в отдельную функцию со своим `try/catch`, которая молчит при любой ошибке, — как `gcMarkRead()` в `api/group_chat.php`.
  Эта ошибка случалась дважды за один день, оба раза данные «пропадали» у живых людей:
  1. `messages_page.php` — `prepare("SHOW COLUMNS ... LIKE ?")` падал на проде (`emulate_prepares=false`), `catch` глушил, и из выборки молча выпадали `attachment_url/type/name`: в переписке исчезли фото, голосовые и кружки.
  2. `group_chat.php` — `UPDATE ... last_read_at = NOW()` падал, потому что `ALTER` ещё не выполнился (`psy_schema_once` — раз в час), а стоял он внутри `try`, возвращающего сообщения: `catch` отдавал `data: []`, и во ВСЕХ группах пропали сообщения, хотя в базе они лежали целыми.
  Отсюда же: **добавил колонку — подними ключ `psy_schema_once`** (`..._v1` → `..._v2`), иначе `ALTER` не выполнится ещё час, а запросы на новую колонку пойдут сразу. И пиши запрос так, чтобы он работал и без новой колонки (проверка наличия + запасной вариант).
- Ключи настроек, которые админка вправе сохранять, перечислены в `allowedSettingKeys()` в `api/admin_ext.php`. **Новую настройку обязательно добавить туда**, иначе `save-settings` молча её выбросит, а админка покажет «Сохранено».
- Таблицы: `users(id,role,first_name,last_name,email,...)`, `psychologists(id,user_id,price,is_approved,...)`, `appointments(id[UUID],client_id,psychologist_id,date_time,duration,format,status,price)`, `payments(id[UUID],appointment_id,amount,status,paid_at)`.
- Стиль/бренд: фиолетовый `#7C3AED`, шрифт Inter. Единая шапка/подвал — `js/layout.js` (`psyWriteHeader()`, `psyWriteFooter()`).
- Каждый коммит заканчивать трейлерами Co-Authored-By и Claude-Session (как в истории).

## Автономная обработка задач (Routine «Задачи Клоду»)
Очередь доработок ведётся в админке (вкладка «🤖 Задачи Клоду») через `api/dev_tasks.php`.
Алгоритм прогона (токен передаётся в промте расписания, НЕ хранить в репозитории):
1. `heartbeat` state=running.
2. Забрать задачи: `GET /api/dev_tasks.php?action=list&status=pending&token=<TOKEN>`.
   - У задачи могут быть поля `attachments` (файлы к задаче) и `admin_attachments` (файлы к доработке, статус `rework`) — JSON-массивы ссылок. Это могут быть картинки, PDF или DOC/DOCX/TXT. ОБЯЗАТЕЛЬНО скачай каждый (`curl -s -o <файл> "https://psytalk.pro<url>?x=$RANDOM"`) и изучи перед реализацией — часто суть задачи/правки именно во вложении. Картинки и PDF смотри через Read; для .docx извлеки текст (`unzip -p <файл> word/document.xml` или pandoc, если доступен). Для `rework` также учитывай `admin_comment`.
3. По каждой: реализовать в ветке, добавить новые файлы в `deploy-ftp.yml`, закоммитить+запушить, проверить на бою.
4. Вернуть статус: `POST action=claude-update {id, status:'done'|'in_progress', claude_comment}`. Если задача рискованная/непонятная — НЕ гадать: `in_progress` + комментарий-вопрос.
5. В конце — `heartbeat` state=idle (или `limited`, если упёрлись в лимит), с note и processed.
