<?php
/**
 * robokassa-result.php — ResultURL для Робокассы (метод POST/GET, БЕЗ параметров в базовом URL).
 *
 * Робокасса сама добавит к адресу свои параметры (OutSum, InvId, SignatureValue).
 * Логика — в общем robokassa.php (ветка result): проверка подписи Password#2,
 * отметка оплаты, ответ «OK<InvId>».
 *
 * В ЛК Робокассы укажите:  https://psytalk.pro/api/robokassa-result.php
 */
$_GET['action'] = 'result';
require __DIR__ . '/robokassa.php';
