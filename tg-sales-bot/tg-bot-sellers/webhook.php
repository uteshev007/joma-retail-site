<?php
// Бот-приёмщик сигналов (продавцы). Открыт для всех — без меню, без команд,
// без запроса имени/логина (раздел 5 ТЗ). Любой текст = "спросили, но не было".

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/telegram.php';
require_once __DIR__ . '/../shared/claude_client.php';

$update = json_decode(file_get_contents('php://input'), true);
$message = $update['message'] ?? null;

if (!$message || !isset($message['text'])) {
    http_response_code(200);
    exit;
}

$chatId = $message['chat']['id'];
$text = trim($message['text']);

if ($text === '' || str_starts_with($text, '/')) {
    // Команды сюда не приходят по смыслу бота, но на всякий случай не пишем их как сигнал.
    http_response_code(200);
    exit;
}

$pdo = get_db();
$catalog = load_catalog_for_matching($pdo);
$match = claude_match_product($text, $catalog);

$stmt = $pdo->prepare(
    'INSERT INTO demand_signals (raw_text, matched_article, matched_size, ai_confidence, created_at)
     VALUES (:raw_text, :article, :size, :confidence, :created_at)'
);
$stmt->execute([
    ':raw_text' => $text,
    ':article' => $match['article'] ?? null,
    ':size' => $match['size'] ?? null,
    ':confidence' => $match['confidence'] ?? 0,
    ':created_at' => date('c'),
]);

tg_send_message(TG_SELLERS_BOT_TOKEN, $chatId, '👍');
