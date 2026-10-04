<?php
/**
 * settings_lib.php — единственное место, которое знает, как устроена таблица настроек.
 *
 * ЗАЧЕМ. Схема на этом хостинге отличается от той, что предполагал код: колонки
 * называются `k` и `v`, а не `key_name` и `value`. Часть файлов читала настройки
 * жёстким запросом `SELECT value FROM settings WHERE key_name = ?` — на проде он
 * падает с «Unknown column 'value'». Там, где ошибку глушил try/catch, настройка
 * молча читалась как пустая (промокод бесплатной записи так и не включался
 * никогда, сколько бы админ его ни сохранял), а где не глушил — эндпоинт падал
 * целиком (тестовая оплата).
 *
 * Админка (admin_ext.php) при этом определяла колонки сама и ПИСАЛА правильно —
 * поэтому со стороны выглядело, будто настройка сохраняется, а её никто не видит.
 *
 * Колонки определяем один раз за запрос и кэшируем: SHOW COLUMNS на каждое
 * чтение настройки — лишний поход в базу.
 *
 * ВАЖНО: SHOW COLUMNS вызываем через query(), а не prepare(). На этом хостинге
 * PDO::ATTR_EMULATE_PREPARES выключен, и `SHOW COLUMNS ... LIKE ?` падает с
 * ошибкой 1064 — на этом уже горели раньше в messages_page.php.
 */

if (!function_exists('psySettingsCols')) {
    /**
     * Найти таблицу настроек и имена колонок ключ/значение.
     * @return array [таблица|null, колонка-ключ|null, колонка-значение|null]
     */
    function psySettingsCols(PDO $pdo): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        foreach (['settings', 'site_settings', 'options', 'config'] as $table) {
            try { $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Exception $e) { continue; }
            if (!$cols) continue;
            $lc = array_map('strtolower', $cols);
            $k = null; $v = null;
            foreach (['setting_key', 'key_name', 'key', 'name', 'k', 'option_name', 'param', 'param_name'] as $c) {
                $i = array_search($c, $lc, true); if ($i !== false) { $k = $cols[$i]; break; }
            }
            foreach (['setting_value', 'value', 'val', 'v', 'option_value', 'data'] as $c) {
                $i = array_search($c, $lc, true); if ($i !== false) { $v = $cols[$i]; break; }
            }
            if ($k && $v) return $cache = [$table, $k, $v];
        }
        return $cache = [null, null, null];
    }
}

if (!function_exists('psySetting')) {
    /** Значение настройки строкой. Нет таблицы, нет строки, ошибка — вернём $default. */
    function psySetting(PDO $pdo, string $key, string $default = ''): string {
        list($t, $kc, $vc) = psySettingsCols($pdo);
        if (!$t) return $default;
        try {
            $st = $pdo->prepare("SELECT `$vc` FROM `$t` WHERE `$kc` = ? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? $default : (string)$v;
        } catch (Exception $e) { return $default; }
    }
}
