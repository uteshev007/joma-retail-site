<?php
declare(strict_types=1);

/**
 * Серверный прокси между корзиной (cart.js, браузер) и CRM (раздел 12.1
 * ТЗ CRM: POST /api/lead). Секрет для HMAC-подписи должен жить только
 * здесь — клиентский JS его никогда не видит, иначе любой посетитель
 * сайта смог бы подделывать заявки от чужого имени.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$config = require __DIR__ . '/../config.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$phone = trim((string) ($input['phone'] ?? ''));
$name = trim((string) ($input['name'] ?? ''));
$cart = is_array($input['cart'] ?? null) ? $input['cart'] : [];

if ($phone === '' || count($cart) === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_phone_or_cart']);
    exit;
}

// Нормализуем телефон к +7XXXXXXXXXX — не доверяем формату, который ввёл
// клиент в поле на странице.
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) === 11 && $digits[0] === '8') {
    $digits = '7' . substr($digits, 1);
}
if (strlen($digits) === 10) {
    $digits = '7' . $digits;
}
if (strlen($digits) !== 11 || $digits[0] !== '7') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_phone']);
    exit;
}
$normalizedPhone = '+' . $digits;

$payload = [
    'phone' => $normalizedPhone,
    'name' => $name !== '' ? $name : null,
    'cart' => array_map(static function ($line) {
        return [
            'category' => (string) ($line['category'] ?? ''),
            'qty' => (int) ($line['qty'] ?? 0),
        ];
    }, $cart),
    'utm' => $input['utm'] ?? null,
    'fbp' => $input['fbp'] ?? null,
    'fbc' => $input['fbc'] ?? null,
    'page' => $input['page'] ?? null,
];

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signature = hash_hmac('sha256', $body, $config['crm_lead_secret']);

$ch = curl_init($config['crm_lead_url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Signature: ' . $signature,
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'crm_unreachable', 'detail' => $curlError]);
    exit;
}

// Пробрасываем код и тело ответа CRM как есть — лендингу не нужно гадать
// по отдельному полю, успех это был или нет.
http_response_code($httpCode);
echo $response;
