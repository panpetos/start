<?php
/**
 * schema_util.php — выравнивание кодировки/сортировки новых таблиц под существующую схему.
 *
 * Зачем: таблицы, создаваемые нашим кодом через "CREATE TABLE ... DEFAULT CHARSET=utf8mb4",
 * на MySQL 8 получают сортировку по умолчанию utf8mb4_0900_ai_ci, а старые таблицы проекта
 * (users, psychologists и т.п.) используют utf8mb4_unicode_ci. Любой JOIN по строковому ключу
 * между такими таблицами MySQL отвергает целиком:
 *   SQLSTATE[HY000] 1267 Illegal mix of collations (utf8mb4_unicode_ci) and (utf8mb4_0900_ai_ci)
 * Из-за этого, например, общая лента (feed с JOIN на psychologists/users) падала и выглядела
 * пустой, хотя посты в канале (запрос без JOIN) прекрасно отдавались.
 *
 * Функция подтягивает сортировку указанных таблиц к сортировке эталонной таблицы (по умолчанию
 * users) и делает это только при реальном расхождении, чтобы не перестраивать таблицы на каждый
 * запрос. Любая ошибка не критична — молча пропускаем, вызывающий код от этого не зависит.
 */

if (!function_exists('psy_table_collation')) {
    function psy_table_collation(PDO $pdo, $table) {
        try {
            $st = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
            $st->execute([$table]);
            $c = $st->fetchColumn();
            return $c ? (string)$c : null;
        } catch (Exception $e) { return null; }
    }
}

if (!function_exists('psy_align_collation')) {
    /**
     * @param array  $tables    таблицы, которые нужно привести к эталону
     * @param string $reference эталонная таблица (её сортировку считаем правильной)
     */
    function psy_align_collation(PDO $pdo, array $tables, $reference = 'users') {
        $target = psy_table_collation($pdo, $reference);
        if (!$target) return; // не смогли определить эталон — ничего не трогаем
        $charset = (strpos($target, 'utf8mb4') === 0) ? 'utf8mb4' : 'utf8';
        foreach ($tables as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
            $cur = psy_table_collation($pdo, $t);
            if ($cur === null || $cur === $target) continue; // таблицы нет или уже совпадает
            try {
                $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET $charset COLLATE $target");
            } catch (Exception $e) { /* нет прав на ALTER — остаёмся как есть */ }
        }
    }
}
