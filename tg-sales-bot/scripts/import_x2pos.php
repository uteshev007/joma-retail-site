<?php
// Ручной запуск импорта X2POS-файла (для теста без Telegram):
//   php scripts/import_x2pos.php путь/к/файлу.xlsx

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/xlsx_reader.php';
require_once __DIR__ . '/../shared/x2pos_import.php';

$path = $argv[1] ?? null;
if (!$path || !file_exists($path)) {
    fwrite(STDERR, "Использование: php scripts/import_x2pos.php путь/к/файлу.xlsx\n");
    exit(1);
}

$rawRows = xlsx_read_rows($path);
$located = x2pos_locate_header($rawRows);
$type = $located['type'];
echo "Определён тип выгрузки: $type (заголовок в строке " . ($located['header_row'] + 1) . ")\n";

if ($type === 'unknown') {
    fwrite(STDERR, "Тип выгрузки не распознан (первые строки не содержат известных сигнатур колонок)\n");
    exit(1);
}

$rows = xlsx_rows_to_assoc($rawRows, $located['header_row']);
$pdo = get_db();

switch ($type) {
    case 'nomenclature':
        $result = x2pos_import_nomenclature($pdo, $rows, basename($path));
        echo "Импортировано товаров: {$result['products']}\n";
        echo "Строк остатков (article+size): {$result['stock_rows']}\n";
        echo "Пропущено строк без артикула: {$result['skipped']}\n";
        break;

    case 'payments':
        $result = x2pos_import_payments($pdo, $rows, basename($path));
        echo "Импортировано платежей: {$result['imported']}\n";
        echo "Пропущено (нет # платежа): {$result['skipped']}\n";
        break;

    case 'counterparties':
        $result = x2pos_import_counterparties($pdo, $rows, basename($path));
        echo "Импортировано покупателей: {$result['imported']}\n";
        echo "Пропущено поставщиков: {$result['skipped_suppliers']}\n";
        break;

    case 'sales_documents':
        $result = x2pos_import_sales_documents($pdo, $rows, basename($path));
        echo "Импортировано чеков: {$result['imported']}\n";
        echo "Пропущено (нет # документа): {$result['skipped']}\n";
        break;

    case 'sales_lines':
        $result = x2pos_import_sales_lines($pdo, $rows, basename($path));
        echo "Импортировано строк продаж: {$result['imported']}\n";
        echo "Пропущено (\"Свободный товар\"): {$result['skipped']}\n";
        break;

    case 'pricelist':
        $result = x2pos_import_pricelist($pdo, $rows, basename($path), $path, $located['header_row'], PHOTOS_DIR);
        echo "Обновлено цен: {$result['imported']}\n";
        echo "Пропущено (нет артикула): {$result['skipped']}\n";
        echo "Фото сохранено: {$result['photos_saved']}\n";
        break;

    default:
        fwrite(STDERR, "Тип выгрузки '$type' пока не поддержан.\n");
        exit(1);
}
