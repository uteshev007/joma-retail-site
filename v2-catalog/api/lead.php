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

// Доработка 16.10.2026: раньше здесь каждая строка корзины обрезалась до
// {category, qty} — артикул, размер, цвет терялись ещё на этом прокси,
// даже если cart.js их прислал (найдено при разборе потерянных данных по
// сделке №23, см. CRM app/helpers.php/public/index.php доработка (13)).
// Теперь пробрасываем всё, что реально есть в строке корзины.
$payload = [
    'phone' => $normalizedPhone,
    'name' => $name !== '' ? $name : null,
    'cart' => array_map(static function ($line) {
        return [
            'article' => (string) ($line['article'] ?? ''),
            'model' => $line['model'] ?? null,
            'name' => (string) ($line['name'] ?? ''),
            'category' => (string) ($line['category'] ?? ''),
            'color' => $line['color'] ?? null,
            'size' => $line['size'] ?? null,
            'qty' => (int) ($line['qty'] ?? 0),
            'unitPrice' => (float) ($line['unitPrice'] ?? 0),
        ];
    }, $cart),
    'printNeeded' => !empty($input['printNeeded']),
    'printComment' => !empty($input['printComment']) ? (string) $input['printComment'] : null,
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

// Доработка: при сетевом сбое/обрыве на полпути CRM_getinfo иногда отдаёт
// 0 (не полноценный HTTP-код) — http_response_code() в PHP 8 кидает
// ValueError на коде вне 100-599, необработанное исключение после того,
// как Content-Type уже отправлен (строка 11) — пустой ответ 500 без тела,
// без эха в лог, именно это и ловили ("не удаётся отправить заявку").
if ($httpCode < 100 || $httpCode > 599) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'crm_bad_response', 'detail' => "http_code={$httpCode}"]);
    exit;
}

// Пробрасываем код и тело ответа CRM как есть — лендингу не нужно гадать
// по отдельному полю, успех это был или нет.
http_response_code($httpCode);
echo $response;
