<?php
/**
 * robokassa-success.php — SuccessURL для Робокассы (метод GET, БЕЗ параметров в базовом URL).
 *
 * Робокасса сама добавит к адресу свои параметры (InvId, OutSum, SignatureValue).
 * Логика — в общем robokassa.php (ветка success): проверка и редирект на страницу успеха.
 *
 * В ЛК Робокассы укажите:  https://psytalk.pro/api/robokassa-success.php  (GET)
 */
$_GET['action'] = 'success';
require __DIR__ . '/robokassa.php';
