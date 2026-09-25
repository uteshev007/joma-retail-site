<?php
// Универсальные функции для работы с Telegram Bot API. $token выбирает,
// от имени какого бота отправляется запрос (нужно и для алертов раздела 7 ТЗ,
// когда один бот шлёт уведомление в чат владельца через токен другого бота).

function tg_api_call(string $token, string $method, array $params): array {
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $error];
    }
    return json_decode($response, true) ?? ['ok' => false, 'error' => 'bad_json'];
}

function tg_send_message(string $token, int $chatId, string $text, array $extra = []): array {
    return tg_api_call($token, 'sendMessage', array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ], $extra));
}

// $rows — массив строк кнопок, каждая строка — массив [текст, callback_data].
// callback_data ограничен Telegram 64 байтами — используем короткие коды
// вида "m:report" / "p:abc:30", не текст команд.
function tg_inline_keyboard(array $rows): array {
    return [
        'inline_keyboard' => array_map(
            fn($row) => array_map(fn($btn) => ['text' => $btn[0], 'callback_data' => $btn[1]], $row),
            $rows
        ),
    ];
}

function tg_send_with_keyboard(string $token, int $chatId, string $text, array $keyboardRows): array {
    return tg_send_message($token, $chatId, $text, ['reply_markup' => tg_inline_keyboard($keyboardRows)]);
}

function tg_edit_message(string $token, int $chatId, int $messageId, string $text, array $keyboardRows = []): array {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if (!empty($keyboardRows)) {
        $params['reply_markup'] = tg_inline_keyboard($keyboardRows);
    }
    return tg_api_call($token, 'editMessageText', $params);
}

// Убирает "часики" на кнопке после нажатия. Не критично, но без этого
// Telegram показывает вечную загрузку до истечения таймаута.
function tg_answer_callback_query(string $token, string $callbackQueryId, string $text = ''): array {
    return tg_api_call($token, 'answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
    ]);
}
