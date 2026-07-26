<?php
/**
 * robokassa-fail.php — FailURL для Робокассы (метод GET, БЕЗ параметров в базовом URL).
 *
 * Робокасса сама добавит свои параметры (InvId, OutSum, SignatureValue).
 * Логика — в общем robokassa.php (ветка fail): помечает заказ несостоявшимся и редиректит.
 *
 * В ЛК Робокассы укажите:  https://psytalk.pro/api/robokassa-fail.php  (GET)
 */
$_GET['action'] = 'fail';
require __DIR__ . '/robokassa.php';
