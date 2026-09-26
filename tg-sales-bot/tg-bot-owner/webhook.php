<?php
// Бот-аналитик (владелец). Закрыт whitelist'ом по chat_id (см. раздел 7 ТЗ).

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/telegram.php';
require_once __DIR__ . '/../shared/xlsx_reader.php';
require_once __DIR__ . '/../shared/x2pos_import.php';
require_once __DIR__ . '/../shared/reports.php';

$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    if (!in_array($chatId, OWNER_CHAT_IDS, true)) {
        http_response_code(200);
        exit;
    }
    route_callback($chatId, $callback['message']['message_id'], $callback['id'], $callback['data'] ?? '');
    exit;
}

if (!$update || !isset($update['message'])) {
    http_response_code(200);
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = trim($message['text'] ?? '');

if (!in_array($chatId, OWNER_CHAT_IDS, true)) {
    // Чужой chat_id — тихо игнорируем, не отвечаем и не логируем содержимое.
    http_response_code(200);
    exit;
}

// Если пришёл документ (X2POS-выгрузка) — обрабатываем отдельно от команд.
if (isset($message['document'])) {
    handle_document_upload($chatId, $message['document']);
    exit;
}

route_command($chatId, $text);
exit;

// Главное меню — кнопки вместо необходимости печатать команды. callback_data
// короткие коды ("m:report"), не текст команд — у Telegram лимит 64 байта.
function main_menu_keyboard(): array {
    return [
        [['📊 Дашборд', 'm:dashboard']],
        [['💡 Где теряю продажи', 'm:growth']],
        [['📊 Отчёт', 'm:report'], ['📦 Размеры', 'm:sizes']],
        [['🏆 ABC', 'm:abc'], ['📉 Аутсайдеры', 'm:outliers']],
        [['⏳ Сток', 'm:stock'], ['💰 Маржа', 'm:margin']],
        [['👥 Клиенты', 'm:clients'], ['🔗 Связки', 'm:basket']],
        [['📈 Тренд', 'm:trend'], ['🏷 Цены конкурентов', 'm:competitors']],
        [['🔄 Обновить из Kaspi', 'm:update']],
    ];
}

function period_keyboard(string $reportCode): array {
    return [
        [['7 дней', "p:$reportCode:7"], ['15 дней', "p:$reportCode:15"]],
        [['30 дней', "p:$reportCode:30"], ['60 дней', "p:$reportCode:60"]],
        [['90 дней', "p:$reportCode:90"]],
        back_row(),
    ];
}

function back_row(): array {
    return [['⬅️ Меню', 'm:menu']];
}

// $callbackBase — всё, что должно быть в callback_data кнопок пред/след, без
// номера страницы (сама страница добавляется через ":N"). Например
// "p:abc:30" или "m:stock".
function pagination_rows(string $callbackBase, int $page, int $totalPages): array {
    if ($totalPages <= 1) {
        return [back_row()];
    }
    $nav = [];
    if ($page > 1) {
        $nav[] = ['◀️', "$callbackBase:" . ($page - 1)];
    }
    $nav[] = ["$page/$totalPages", 'noop'];
    if ($page < $totalPages) {
        $nav[] = ['▶️', "$callbackBase:" . ($page + 1)];
    }
    return [$nav, back_row()];
}

function route_callback(int $chatId, int $messageId, string $callbackQueryId, string $data): void {
    tg_answer_callback_query(TG_OWNER_BOT_TOKEN, $callbackQueryId);

    if ($data === 'noop') {
        return;
    }

    $pdo = get_db();

    if ($data === 'm:menu') {
        edit($chatId, $messageId, "JOMA Retail — бот-аналитик\n\nВыберите отчёт:", main_menu_keyboard());
        return;
    }

    // Отчёты с периодом сперва показывают подменю 7/15/30/60/90 дней; после
    // выбора периода — "p:код:период[:страница]", страница по умолчанию 1.
    if (str_starts_with($data, 'p:')) {
        $parts = explode(':', $data);
        $code = $parts[1];
        $days = (int) $parts[2];
        $page = isset($parts[3]) ? (int) $parts[3] : 1;

        $result = match ($code) {
            'abc' => report_abc($pdo, $days, $page),
            'outliers' => report_outliers($pdo, $days, $page),
            'margin' => ['text' => report_margin($pdo, $days), 'page' => 1, 'total_pages' => 1],
            'growth' => ['text' => report_growth_opportunities($pdo, $days), 'page' => 1, 'total_pages' => 1],
            default => ['text' => 'Неизвестный отчёт.', 'page' => 1, 'total_pages' => 1],
        };
        edit($chatId, $messageId, $result['text'], pagination_rows("p:$code:$days", $result['page'], $result['total_pages']));
        return;
    }

    // Пункты меню без периода — "m:код[:страница]".
    $parts = explode(':', $data);
    $code = $parts[1] ?? '';
    $page = isset($parts[2]) ? (int) $parts[2] : 1;

    switch ($code) {
        case 'dashboard':
            edit($chatId, $messageId, report_dashboard($pdo, 7), [
                [['💡 Где теряю продажи', 'p:growth:30']],
                back_row(),
            ]);
            break;

        case 'report':
            edit($chatId, $messageId, report_summary($pdo), [back_row()]);
            break;

        case 'sizes':
            $result = report_size_matrix_list($pdo, $page);
            $text = $result['text'] . "\n<i>Чтобы посмотреть один артикул — напишите текстом: /размеры АРТИКУЛ</i>";
            edit($chatId, $messageId, $text, pagination_rows('m:sizes', $result['page'], $result['total_pages']));
            break;

        case 'abc':
            edit($chatId, $messageId, "За какой период посчитать ABC/XYZ?", period_keyboard('abc'));
            break;

        case 'outliers':
            edit($chatId, $messageId, "За какой период посчитать аутсайдеров?", period_keyboard('outliers'));
            break;

        case 'margin':
            edit($chatId, $messageId, "За какой период посчитать маржу?", period_keyboard('margin'));
            break;

        case 'growth':
            edit($chatId, $messageId, "За какой период проверить, где теряете продажи?", period_keyboard('growth'));
            break;

        case 'stock':
            $result = report_stock_forecast_list($pdo, 8, $page);
            $text = $result['text'] . "\n<i>Чтобы посмотреть один артикул — напишите текстом: /сток АРТИКУЛ</i>";
            edit($chatId, $messageId, $text, pagination_rows('m:stock', $result['page'], $result['total_pages']));
            break;

        case 'clients':
            $result = report_customers($pdo, $page);
            edit($chatId, $messageId, $result['text'], pagination_rows('m:clients', $result['page'], $result['total_pages']));
            break;

        case 'basket':
            $result = report_basket($pdo, $page);
            edit($chatId, $messageId, $result['text'], pagination_rows('m:basket', $result['page'], $result['total_pages']));
            break;

        case 'trend':
            edit($chatId, $messageId, "TODO: график по неделям/месяцам через QuickChart.io — данные уже есть в sales, отправка изображения не реализована.", [back_row()]);
            break;

        case 'competitors':
            edit($chatId, $messageId, "TODO: сверка с сохранёнными карточками/ссылками конкурентов (раздел 2.4 ТЗ).", [back_row()]);
            break;

        case 'update':
            edit($chatId, $messageId, "TODO: запуск синхронизации с Kaspi API (раздел 5 план реализации ТЗ, шаг 5).", [back_row()]);
            break;

        default:
            edit($chatId, $messageId, "Неизвестная кнопка.", [back_row()]);
    }
}

function edit(int $chatId, int $messageId, string $text, array $keyboardRows = []): void {
    tg_edit_message(TG_OWNER_BOT_TOKEN, $chatId, $messageId, $text, $keyboardRows);
}

function route_command(int $chatId, string $text): void {
    $parts = explode(' ', $text, 2);
    $command = strtolower($parts[0]);
    $arg = isset($parts[1]) ? trim($parts[1]) : null;

    switch ($command) {
        case '/start':
            tg_send_with_keyboard(
                TG_OWNER_BOT_TOKEN,
                $chatId,
                "JOMA Retail — бот-аналитик\n\nВыберите отчёт кнопками ниже, или отправьте файл выгрузки X2POS для импорта.",
                main_menu_keyboard()
            );
            break;

        case '/обновить':
            reply($chatId, "TODO: запуск синхронизации с Kaspi API (раздел 5 план реализации ТЗ, шаг 5).");
            break;

        case '/дашборд':
            reply($chatId, report_dashboard(get_db(), 7));
            break;

        case '/отчёт':
        case '/отчет':
            reply($chatId, report_summary(get_db()));
            break;

        case '/размеры':
            if ($arg !== null) {
                reply($chatId, report_size_matrix(get_db(), $arg));
            } else {
                $result = report_size_matrix_list(get_db(), 1);
                reply_paginated($chatId, $result, 'm:sizes');
            }
            break;

        case '/abc':
            $result = report_abc(get_db(), parse_period_arg($arg));
            reply_paginated($chatId, $result, 'p:abc:' . parse_period_arg($arg));
            break;

        case '/аутсайдеры':
            $result = report_outliers(get_db(), parse_period_arg($arg));
            reply_paginated($chatId, $result, 'p:outliers:' . parse_period_arg($arg));
            break;

        case '/сток':
            if ($arg !== null) {
                reply($chatId, report_stock_forecast(get_db(), $arg));
            } else {
                $result = report_stock_forecast_list(get_db());
                reply_paginated($chatId, $result, 'm:stock');
            }
            break;

        case '/клиенты':
            $result = report_customers(get_db());
            reply_paginated($chatId, $result, 'm:clients');
            break;

        case '/маржа':
            reply($chatId, report_margin(get_db(), parse_period_arg($arg)));
            break;

        case '/точки_роста':
            reply($chatId, report_growth_opportunities(get_db(), parse_period_arg($arg)));
            break;

        case '/связки':
            $result = report_basket(get_db());
            reply_paginated($chatId, $result, 'm:basket');
            break;

        case '/тренд':
            reply($chatId, "TODO: график по неделям/месяцам через QuickChart.io (раздел 4 ТЗ, шаг 10 плана) — данные для него уже есть в sales, не реализована отправка изображения.");
            break;

        case '/цены_конкурентов':
            reply($chatId, "TODO: сверка с сохранёнными карточками/ссылками конкурентов (раздел 2.4, шаг 12 плана ТЗ).");
            break;

        default:
            reply($chatId, "Команда не распознана. /start — список команд.");
    }
}

// "/abc 90" -> 90 дней; без аргумента или нераспознанный ввод -> 30.
function parse_period_arg(?string $arg): int {
    $allowed = [7, 15, 30, 60, 90];
    $value = (int) $arg;
    return in_array($value, $allowed, true) ? $value : 30;
}

function handle_document_upload(int $chatId, array $document): void {
    $fileName = $document['file_name'] ?? 'файл';

    if (!str_ends_with(strtolower($fileName), '.xlsx')) {
        reply($chatId, "Пока поддерживаются только .xlsx выгрузки X2POS. Получен: $fileName");
        return;
    }

    $fileInfo = tg_api_call(TG_OWNER_BOT_TOKEN, 'getFile', ['file_id' => $document['file_id']]);
    if (!($fileInfo['ok'] ?? false)) {
        reply($chatId, "Не удалось получить файл от Telegram: $fileName");
        return;
    }

    $filePath = $fileInfo['result']['file_path'];
    $downloadUrl = "https://api.telegram.org/file/bot" . TG_OWNER_BOT_TOKEN . "/$filePath";
    $tmpFile = tempnam(sys_get_temp_dir(), 'x2pos_') . '.xlsx';
    $bytes = file_get_contents($downloadUrl);
    if ($bytes === false) {
        reply($chatId, "Не удалось скачать файл: $fileName");
        return;
    }
    file_put_contents($tmpFile, $bytes);

    try {
        $rawRows = xlsx_read_rows($tmpFile);
        $located = x2pos_locate_header($rawRows);
        $type = $located['type'];

        if ($type === 'unknown') {
            reply($chatId, "Тип выгрузки не распознан ($fileName). Поддерживаются: номенклатура, платежи, контрагенты — остальные типы из раздела 2.2 ТЗ добавляются по мере поступления образцов.");
            return;
        }

        $rows = xlsx_rows_to_assoc($rawRows, $located['header_row']);

        switch ($type) {
            case 'nomenclature':
                $result = x2pos_import_nomenclature(get_db(), $rows, $fileName);
                reply($chatId, "Импортирована номенклатура из $fileName:\n"
                    . "товаров: {$result['products']}\n"
                    . "строк остатков: {$result['stock_rows']}\n"
                    . "пропущено (нет артикула): {$result['skipped']}");
                break;

            case 'payments':
                $result = x2pos_import_payments(get_db(), $rows, $fileName);
                reply($chatId, "Импортированы платежи из $fileName:\n"
                    . "строк: {$result['imported']}\n"
                    . "пропущено: {$result['skipped']}");
                break;

            case 'counterparties':
                $result = x2pos_import_counterparties(get_db(), $rows, $fileName);
                reply($chatId, "Импортированы контрагенты из $fileName:\n"
                    . "покупателей: {$result['imported']}\n"
                    . "поставщиков пропущено: {$result['skipped_suppliers']}");
                break;

            case 'sales_documents':
                $result = x2pos_import_sales_documents(get_db(), $rows, $fileName);
                reply($chatId, "Импортированы чеки из $fileName:\n"
                    . "чеков: {$result['imported']}\n"
                    . "пропущено: {$result['skipped']}\n"
                    . "(это чеки без состава товаров — ABC/XYZ, /сток, /маржа нужен построчный состав, файл «История продаж по товарам»)");
                break;

            case 'sales_lines':
                $result = x2pos_import_sales_lines(get_db(), $rows, $fileName);
                reply($chatId, "Импортирован построчный состав продаж из $fileName:\n"
                    . "строк: {$result['imported']}\n"
                    . "пропущено (\"Свободный товар\"): {$result['skipped']}");
                break;

            case 'pricelist':
                $result = x2pos_import_pricelist(get_db(), $rows, $fileName, $tmpFile, $located['header_row'], PHOTOS_DIR);
                reply($chatId, "Обновлены цены из $fileName:\n"
                    . "товаров: {$result['imported']}\n"
                    . "пропущено (нет артикула): {$result['skipped']}\n"
                    . "фото сохранено: {$result['photos_saved']}");
                break;
        }
    } catch (Throwable $e) {
        reply($chatId, "Ошибка импорта $fileName: " . $e->getMessage());
    } finally {
        @unlink($tmpFile);
    }
}

function reply(int $chatId, string $text): void {
    tg_send_message(TG_OWNER_BOT_TOKEN, $chatId, $text);
}

// Для отчётов из route_command (текстовые команды) — те же кнопки ◀️/▶️, что
// у кнопочного меню, чтобы листать можно было и начав с текста.
function reply_paginated(int $chatId, array $result, string $callbackBase): void {
    tg_send_with_keyboard(TG_OWNER_BOT_TOKEN, $chatId, $result['text'], pagination_rows($callbackBase, $result['page'], $result['total_pages']));
}
