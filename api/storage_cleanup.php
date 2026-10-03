<?php
/**
 * storage_cleanup.php — уборка места на диске: истёкшие истории и старые вложения.
 *
 * ЗАЧЕМ. Файлы на reg.ru копились и никогда не удалялись: истории пропадали из
 * ленты через 24 ч, но файл оставался навсегда; старые вложения тоже лежали без
 * срока. Диск шейред-хостинга не резиновый.
 *
 * ЧТО ДЕЛАЕТ (безопасно, ничего «живого» не ломает):
 *  • Истёкшие истории (старше срока + запас) — удаляет запись и файл.
 *  • Ретеншн: вложения старше media_retention_days — удаляет ФАЙЛ, а ссылку в
 *    сообщении обнуляет (текст остаётся, битых картинок не появляется).
 *  • Перед удалением файла считает ссылки на него по всем таблицам: если файл
 *    ещё где-то используется (пересланное сообщение и т.п.) — файл не трогаем.
 *
 * Работает и как подключаемая библиотека (psyStorageCleanup), и как эндпоинт для
 * админа: ?action=report (посчитать) / ?action=run (выполнить).
 */

if (!function_exists('psy_schema_once')) require_once __DIR__ . '/schema_util.php';
if (!function_exists('psySetting'))     require_once __DIR__ . '/settings_lib.php';

/** Таблицы с вложениями: [таблица, колонка-ссылка]. */
function psyAttachmentRefs() {
    return [
        ['messages', 'attachment_url'],
        ['chat_group_messages', 'attachment_url'],
        ['favorite_messages', 'attachment_url'],
        ['support_messages', 'attachment_url'],
        ['homework', 'attachment_url'],
        ['stories', 'media_url'],
    ];
}

/** Сколько всего ссылок на этот файл во всех таблицах (чтобы не удалить нужный). */
function psyFileRefCount(PDO $pdo, $url) {
    $n = 0;
    foreach (psyAttachmentRefs() as [$t, $c]) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
            $st->execute([$url]);
            $n += (int)$st->fetchColumn();
        } catch (Exception $e) { /* нет такой таблицы — пропускаем */ }
    }
    return $n;
}

/** Удалить файл, только если он лежит внутри /uploads и физически существует. */
function psySafeUnlink($url, &$bytes) {
    if (!is_string($url) || strpos($url, '/uploads/') !== 0) return false;
    $base = realpath(__DIR__ . '/../uploads');
    $path = realpath(__DIR__ . '/..' . $url);
    if (!$base || !$path) return false;
    if (strncmp($path, $base, strlen($base)) !== 0) return false;   // защита от «выхода» за uploads
    if (!is_file($path)) return false;
    $sz = @filesize($path);
    if (@unlink($path)) { $bytes += (int)$sz; return true; }
    return false;
}

/**
 * Основная уборка. $opts: ['stories_grace_days'=>2, 'retention_days'=>null, 'limit'=>500].
 * Возвращает статистику. Не бросает исключений наружу.
 */
function psyStorageCleanup(PDO $pdo, array $opts = []) {
    $graceDays = isset($opts['stories_grace_days']) ? (int)$opts['stories_grace_days'] : 2;
    $limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 500;
    $retention = $opts['retention_days'] ?? null;
    if ($retention === null) $retention = (int)psySetting($pdo, 'media_retention_days', 365);
    $retention = (int)$retention;

    $stats = ['stories_removed' => 0, 'attachments_cleared' => 0, 'files_deleted' => 0, 'bytes_freed' => 0, 'retention_days' => $retention];

    // 1) Истёкшие истории: сама запись больше не нужна, файл — тоже.
    try {
        $st = $pdo->prepare("SELECT id, media_url FROM stories
                             WHERE expires_at < (NOW() - INTERVAL ? DAY) LIMIT ?");
        $st->bindValue(1, $graceDays, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            try { $pdo->prepare("DELETE FROM stories WHERE id = ?")->execute([$r['id']]); } catch (Exception $e) { continue; }
            $stats['stories_removed']++;
            $url = (string)$r['media_url'];
            if ($url !== '' && psyFileRefCount($pdo, $url) === 0) {
                if (psySafeUnlink($url, $stats['bytes_freed'])) $stats['files_deleted']++;
            }
        }
    } catch (Exception $e) {}

    // 2) Ретеншн: старые вложения. Файл удаляем, ссылку в сообщении обнуляем —
    //    текст остаётся, битых картинок не появляется. 0 или меньше — ретеншн выключен.
    if ($retention > 0) {
        $tables = [
            ['messages', 'attachment_url', 'attachment_type', 'attachment_name', 'created_at'],
            ['chat_group_messages', 'attachment_url', 'attachment_type', 'attachment_name', 'created_at'],
            ['favorite_messages', 'attachment_url', 'attachment_type', 'attachment_name', 'created_at'],
            ['support_messages', 'attachment_url', 'attachment_type', 'attachment_name', 'created_at'],
            ['homework', 'attachment_url', 'attachment_type', 'attachment_name', 'created_at'],
        ];
        foreach ($tables as [$t, $cu, $ct, $cn, $cc]) {
            try {
                $sel = $pdo->prepare("SELECT id, `$cu` AS url FROM `$t`
                                      WHERE `$cu` LIKE '/uploads/%' AND `$cc` < (NOW() - INTERVAL ? DAY) LIMIT ?");
                $sel->bindValue(1, $retention, PDO::PARAM_INT);
                $sel->bindValue(2, $limit, PDO::PARAM_INT);
                $sel->execute();
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { continue; }
            foreach ($rows as $r) {
                $url = (string)$r['url'];
                // Сначала обнуляем ссылку в этой строке…
                try {
                    $pdo->prepare("UPDATE `$t` SET `$cu` = NULL, `$ct` = NULL, `$cn` = NULL WHERE id = ?")
                        ->execute([$r['id']]);
                } catch (Exception $e) { continue; }
                $stats['attachments_cleared']++;
                // …а файл удаляем, только если на него больше никто не ссылается.
                if ($url !== '' && psyFileRefCount($pdo, $url) === 0) {
                    if (psySafeUnlink($url, $stats['bytes_freed'])) $stats['files_deleted']++;
                }
            }
        }
    }

    return $stats;
}

/** Раз в сутки — вызывается из часто дёргаемого stories.php (см. там psy_schema_once). */
function psyStorageCleanupTick(PDO $pdo) {
    try { psy_schema_once('storage_cleanup_daily_v1', 86400, function () use ($pdo) { psyStorageCleanup($pdo); }); }
    catch (Exception $e) {}
}

// ── Режим эндпоинта (только когда файл открыт напрямую, не при include) ───────
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/config.php';
    if (!function_exists('getDB') && !function_exists('getDbConnection') && !function_exists('getPDO')) {
        require_once __DIR__ . '/db.php';
    }
    $pdo = function_exists('getDB') ? getDB()
         : (function_exists('getDbConnection') ? getDbConnection()
         : (function_exists('getPDO') ? getPDO() : null));
    if (!$pdo) { http_response_code(500); echo json_encode(['error' => 'Нет подключения к БД']); exit; }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? null;
    $isAdmin = false;
    if ($uid) {
        try { $st = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1"); $st->execute([$uid]); $isAdmin = ((string)$st->fetchColumn() === 'admin'); } catch (Exception $e) {}
    }
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Только администратору']); exit; }

    $action = $_GET['action'] ?? 'report';
    if ($action === 'run') {
        echo json_encode(['ok' => true, 'result' => psyStorageCleanup($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // report — что накопилось (без удаления)
    $out = ['ok' => true, 'retention_days' => (int)psySetting($pdo, 'media_retention_days', 365)];
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM stories WHERE expires_at < (NOW() - INTERVAL 2 DAY)");
        $out['expired_stories'] = (int)$st->fetchColumn();
    } catch (Exception $e) { $out['expired_stories'] = 0; }
    $ret = $out['retention_days']; $old = 0;
    if ($ret > 0) foreach ([['messages','attachment_url','created_at'],['chat_group_messages','attachment_url','created_at'],['favorite_messages','attachment_url','created_at'],['support_messages','attachment_url','created_at'],['homework','attachment_url','created_at']] as [$t,$cu,$cc]) {
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$cu` LIKE '/uploads/%' AND `$cc` < (NOW() - INTERVAL ? DAY)"); $q->bindValue(1,$ret,PDO::PARAM_INT); $q->execute(); $old += (int)$q->fetchColumn(); } catch (Exception $e) {}
    }
    $out['old_attachments'] = $old;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
