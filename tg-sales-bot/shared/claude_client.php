<?php
// Минимальный клиент Claude API (Messages) для распознавания сигналов от
// продавцов. Модель видит компактный список "артикул — название" и должен
// вернуть JSON с найденным соответствием.

function claude_match_product(string $rawText, array $catalog): ?array {
    $catalogLines = array_map(
        fn($p) => "{$p['article']} — {$p['name']} ({$p['category']})",
        $catalog
    );

    $prompt = "Продавец в магазине спортивной одежды JOMA написал в свободной форме, "
        . "что спросили, но не было в наличии. Определи артикул товара и размер (если указан) "
        . "из списка ниже. Если однозначно определить не получается — верни article: null.\n\n"
        . "Сообщение продавца: \"" . $rawText . "\"\n\n"
        . "Каталог (артикул — название):\n" . implode("\n", $catalogLines) . "\n\n"
        . "Ответь строго JSON без пояснений: {\"article\": \"...\"|null, \"size\": \"...\"|null, \"confidence\": 0.0-1.0}";

    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 200,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Claude API curl error: $curlError");
        return null;
    }

    $decoded = json_decode($response, true);
    $text = $decoded['content'][0]['text'] ?? null;
    if ($text === null) {
        error_log("Claude API unexpected response: $response");
        return null;
    }

    $match = json_decode(trim($text), true);
    if (!is_array($match) || !array_key_exists('article', $match)) {
        error_log("Claude API returned non-JSON or malformed: $text");
        return null;
    }

    return [
        'article' => $match['article'],
        'size' => $match['size'] ?? null,
        'confidence' => (float) ($match['confidence'] ?? 0),
    ];
}

// Каталог из 500+ товаров на каждый сигнал — не бесплатно по токенам, но
// приемлемо для Haiku при разумном трафике магазина. Если объём вырастет,
// первый шаг оптимизации — предварительная фильтрация по ключевым словам
// перед отправкой в Claude.
function load_catalog_for_matching(PDO $pdo): array {
    return $pdo->query('SELECT article, name, category FROM products')->fetchAll(PDO::FETCH_ASSOC);
}
