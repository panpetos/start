<?php
/**
 * sber_fail.php — куда банк возвращает человека, если оплата не прошла.
 * Отдельный файл по той же причине, что и sber_return.php: адрес без «?».
 */
$_GET['action'] = 'fail';
require __DIR__ . '/sber_acquiring.php';
