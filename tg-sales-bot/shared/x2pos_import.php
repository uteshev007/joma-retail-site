<?php
require_once __DIR__ . '/norm_article.php';

// Тип выгрузки определяется по набору колонок в заголовке (раздел 2.2 ТЗ:
// бот сам понимает тип файла, без отдельной команды). Прайс-лист и продажи
// пока не реализованы — добавляются по тому же принципу, когда появятся
// образцы реальных файлов.
const X2POS_SIGNATURES = [
    'nomenclature' => ['Артикул', 'Код товара', 'Импортируемое количество', 'Цена закупа'],
    'payments' => ['# платежа', 'Приход', 'Расход', 'Остаток после'],
    'counterparties' => ['ФИО', 'Признак поставщика', 'БИН/ИИН'],
    'sales_documents' => ['# Документа', 'Подытог', 'Итого к оплате', 'Итого оплачено'],
    'sales_lines' => ['Документ', 'Товар', 'Количество', 'Цена продажа', 'Маржа'],
    'pricelist' => ['Наименование', 'Артикул', 'Цена розница', 'Цена со скидкой'],
];

function x2pos_detect_type(array $header): string {
    $columns = array_map('strval', array_filter($header, fn($v) => $v !== null));
    $has = fn(string $name) => in_array($name, $columns, true);

    foreach (X2POS_SIGNATURES as $type => $requiredColumns) {
        if (!array_diff($requiredColumns, array_filter($requiredColumns, $has))) {
            return $type;
        }
    }

    return 'unknown';
}

// X2POS-выгрузки часто начинаются со служебных строк ("Компания:", "Период:")
// перед настоящей строкой заголовков — ищем её по содержимому, а не по
// фиксированному индексу, чтобы не зависеть от точного числа служебных строк.
function x2pos_locate_header(array $rawRows, int $maxRowsToScan = 15): array {
    $limit = min($maxRowsToScan, count($rawRows));
    for ($i = 0; $i < $limit; $i++) {
        $type = x2pos_detect_type($rawRows[$i]);
        if ($type !== 'unknown') {
            return ['type' => $type, 'header_row' => $i];
        }
    }
    return ['type' => 'unknown', 'header_row' => 0];
}

// Артикул надёжнее брать из "Кода товара" (Артикул-Размер), а не из колонки
// "Артикул" напрямую: в реальных выгрузках она иногда содержит брак/задвоение
// (напр. "BF1448WBF1448W25032503-41" вместо "BF1448W2503"), тогда как "Код
// товара" оставался консистентным. norm_article() приводит оба представления
// float-артикулов ("902034.100" / "902034.1") к одному виду.
// Ручные правки для конкретных известных браков ввода в X2POS, которые
// невозможно вывести алгоритмически — кто-то вписал в "Код товара" Excel-
// формулу вместо кода (см. историю чата). Ключ — сырое значение "Код
// товара" из файла, как оно приходит из sharedStrings.xml (с двойным HTML-
// экранированием &/", как в оригинале).
const X2POS_MANUAL_SIZE_FIXES = [
    // 104440.322 "POLO С КОРОТКИМ РУКАВОМ MIMETIC СИНИЙ": формула
    // D15&"-"&M15 вместо кода товара. У этого цвета уже есть S/M/L/XL —
    // владелец подтвердил 2XL по аналогии с бежевым вариантом той же модели.
    'D15&amp;&#34;-&#34;&amp;M15' => '2XL',
];

function x2pos_split_code(?string $code, ?string $articleFallback): array {
    $code = trim((string) $code);
    if (isset(X2POS_MANUAL_SIZE_FIXES[$code])) {
        return [norm_article((string) $articleFallback), X2POS_MANUAL_SIZE_FIXES[$code]];
    }
    if ($code !== '' && str_contains($code, '-')) {
        $pos = strrpos($code, '-');
        $base = substr($code, 0, $pos);
        $size = substr($code, $pos + 1);
        return [norm_article($base), x2pos_normalize_size($size)];
    }
    if ($code !== '') {
        return [norm_article($code), null];
    }
    return [norm_article((string) $articleFallback), null];
}

// Детские двойные размеры "возраст (буквенный аналог)" непоследовательно
// оформлены в X2POS — один товар пишет "4года (6XS)", другой то же самое
// "4 (6XS)" без слова "года"/"лет" (и часть вообще без закрывающей скобки —
// источник её обрезает, не наш парсинг). Разные слова для одного и того же
// размера дробят агрегацию по размерам на лишние строки — приводим к одному
// виду "N (LETTER)".
function x2pos_normalize_size(string $size): string {
    $size = preg_replace('/^(\d+)\s*(?:лет|года|год)\b\s*/u', '$1 ', $size);
    if (str_contains($size, '(') && !str_contains($size, ')')) {
        $size .= ')';
    }
    return $size;
}

// $rows — результат xlsx_rows_to_assoc() с ключами-заголовками ровно как в файле.
function x2pos_import_nomenclature(PDO $pdo, array $rows, string $sourceFile): array {
    $products = [];   // article => [name, category, cost_price]
    $stock = [];      // article => size => qty (суммируется на случай дублей/строк без размера)
    $skipped = 0;

    foreach ($rows as $row) {
        [$article, $sizeFromCode] = x2pos_split_code(
            $row['Код товара'] ?? null,
            $row['Артикул'] ?? null
        );

        if ($article === '') {
            $skipped++;
            continue;
        }

        $size = $sizeFromCode;
        if ($size === null) {
            $razmery = trim((string) ($row['Размеры'] ?? ''));
            $size = $razmery !== '' ? $razmery : 'б/р';
        }

        $products[$article] = [
            'name' => (string) ($row['Товар'] ?? ''),
            'category' => (string) ($row['Группа товаров'] ?? ''),
            'cost_price' => (float) ($row['Цена закупа'] ?? 0),
        ];

        $qty = (int) ($row['Импортируемое количество'] ?? 0);
        $stock[$article][$size] = ($stock[$article][$size] ?? 0) + $qty;
    }

    $now = date('c');

    $pdo->beginTransaction();
    try {
        $upsertProduct = $pdo->prepare(
            'INSERT INTO products (article, name, category, brand, cost_price, updated_at)
             VALUES (:article, :name, :category, :brand, :cost_price, :updated_at)
             ON CONFLICT(article) DO UPDATE SET
                name = excluded.name,
                category = excluded.category,
                cost_price = excluded.cost_price,
                updated_at = excluded.updated_at'
        );
        foreach ($products as $article => $p) {
            $upsertProduct->execute([
                ':article' => $article,
                ':name' => $p['name'],
                ':category' => $p['category'],
                ':brand' => 'JOMA',
                ':cost_price' => $p['cost_price'],
                ':updated_at' => $now,
            ]);
        }

        $upsertStock = $pdo->prepare(
            'INSERT INTO stock (article, size, qty_on_hand, updated_at)
             VALUES (:article, :size, :qty, :updated_at)
             ON CONFLICT(article, size) DO UPDATE SET
                qty_on_hand = excluded.qty_on_hand,
                updated_at = excluded.updated_at'
        );
        foreach ($stock as $article => $sizes) {
            foreach ($sizes as $size => $qty) {
                $upsertStock->execute([
                    ':article' => $article,
                    ':size' => $size,
                    ':qty' => $qty,
                    ':updated_at' => $now,
                ]);
            }
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => sprintf(
                'номенклатура из %s: %d товаров, %d строк остатков, %d строк пропущено (нет артикула)',
                $sourceFile,
                count($products),
                array_sum(array_map('count', $stock)),
                $skipped
            ),
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'products' => count($products),
        'stock_rows' => array_sum(array_map('count', $stock)),
        'skipped' => $skipped,
    ];
}

// "01.05.2026" -> "2026-05-01". Возвращает null, если формат не распознан —
// лучше пустая дата, чем тихо неверная.
function x2pos_parse_date_dmy(?string $value): ?string {
    $value = trim((string) $value);
    if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $value, $m)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
}

// Раздел 2.2 ТЗ: платежи — не первый приоритет (сверка с эквайринг-комиссией
// отдельная тема), но сохраняем как есть — пригодится, когда до неё дойдут.
// "# платежа" — стабильный natural key из X2POS, поэтому upsert по нему
// делает повторный импорт того же файла идемпотентным.
function x2pos_import_payments(PDO $pdo, array $rows, string $sourceFile): array {
    $now = date('c');
    $imported = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO payments (id, payment_date, branch, payment_method, account, counterparty,
                income, expense, balance_after, purpose, employee, source_file, imported_at)
             VALUES (:id, :payment_date, :branch, :payment_method, :account, :counterparty,
                :income, :expense, :balance_after, :purpose, :employee, :source_file, :imported_at)
             ON CONFLICT(id) DO UPDATE SET
                payment_date = excluded.payment_date,
                income = excluded.income,
                expense = excluded.expense,
                balance_after = excluded.balance_after,
                purpose = excluded.purpose'
        );

        foreach ($rows as $row) {
            $id = $row['# платежа'] ?? null;
            if ($id === null || $id === '') {
                $skipped++;
                continue;
            }

            $upsert->execute([
                ':id' => (int) $id,
                ':payment_date' => x2pos_parse_date_dmy($row['Дата'] ?? null),
                ':branch' => (string) ($row['Филиал'] ?? ''),
                ':payment_method' => (string) ($row['Способ оплаты'] ?? ''),
                ':account' => (string) ($row['Счет'] ?? ''),
                ':counterparty' => $row['Контрагент'] ?? null,
                ':income' => $row['Приход'] !== null ? (float) $row['Приход'] : null,
                ':expense' => $row['Расход'] !== null ? (float) $row['Расход'] : null,
                ':balance_after' => $row['Остаток после'] !== null ? (float) $row['Остаток после'] : null,
                ':purpose' => (string) ($row['Назначение платежа'] ?? ''),
                ':employee' => (string) ($row['Сотрудник'] ?? ''),
                ':source_file' => $sourceFile,
                ':imported_at' => $now,
            ]);
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => "платежи из $sourceFile: $imported строк, $skipped пропущено (нет # платежа)",
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

// Контрагенты — покупатели, база для LTV/оттока (раздел 2.2 ТЗ). Организации
// с "Признак поставщика" = "да" — не покупатели, пропускаются. Даты первой/
// последней покупки и LTV здесь не считаются — они появятся, когда будет
// импортирована история продаж и появится связь sales.customer_id.
function x2pos_import_counterparties(PDO $pdo, array $rows, string $sourceFile): array {
    $now = date('c');
    $imported = 0;
    $skippedSuppliers = 0;

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO customers (x2pos_contragent_id, phone, name)
             VALUES (:x2pos_id, :phone, :name)
             ON CONFLICT(x2pos_contragent_id) DO UPDATE SET
                phone = excluded.phone,
                name = excluded.name'
        );

        foreach ($rows as $row) {
            if (($row['Признак поставщика'] ?? null) === 'да') {
                $skippedSuppliers++;
                continue;
            }

            $id = $row['ID'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $name = $row['ФИО'] ?: ($row['Организация'] ?: '');
            $phone = $row['Телефон'] !== null ? (string) $row['Телефон'] : null;

            $upsert->execute([
                ':x2pos_id' => (string) $id,
                ':phone' => $phone,
                ':name' => $name,
            ]);
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => "контрагенты из $sourceFile: $imported покупателей, $skippedSuppliers поставщиков пропущено",
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['imported' => $imported, 'skipped_suppliers' => $skippedSuppliers];
}

// Последние 10 цифр — устойчивое сравнение вне зависимости от префикса
// (8747..., 7747..., +7747..., 747...  — один и тот же номер в разных файлах).
function x2pos_normalize_phone(?string $raw): ?string {
    $digits = preg_replace('/\D/', '', (string) $raw);
    if (strlen($digits) < 10) {
        return null;
    }
    return substr($digits, -10);
}

// "мирас \n+77785583226" -> имя "мирас", телефон "7785583226".
// "Розничный покупатель" (65% чеков) -> анонимная продажа, оба null.
function x2pos_parse_contragent(?string $raw): array {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === 'Розничный покупатель') {
        return [null, null];
    }
    if (preg_match('/(\d[\d\s]{8,})$/', $raw, $m)) {
        $phone = x2pos_normalize_phone($m[1]);
        $name = trim(str_replace($m[0], '', $raw));
        return [$name !== '' ? $name : null, $phone];
    }
    return [$raw, null];
}

// "01.05.2026 05:00:00" -> "2026-05-01 05:00:00"
function x2pos_parse_datetime_dmy(?string $value): ?string {
    $value = trim((string) $value);
    if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{2}:\d{2}:\d{2}))?/', $value, $m)) {
        return null;
    }
    $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return isset($m[4]) ? "$date {$m[4]}" : $date;
}

// X2POS "Экспорт продаж" — уровень чека, без состава товаров (см. комментарий
// в schema.sql у sales_documents). Даёт оборот/скидки и, через привязку по
// телефону контрагента к customers, LTV/отток — то, что раздел 4 ТЗ просит
// в /клиенты, даже без построчного состава.
function x2pos_import_sales_documents(PDO $pdo, array $rows, string $sourceFile): array {
    $now = date('c');
    $imported = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO sales_documents (id, action, branch, employee, doc_date, contragent_raw,
                contragent_phone, subtotal, total_due, total_paid, discount_type, discount_value,
                status, price_type, external_order_id, source_file, imported_at)
             VALUES (:id, :action, :branch, :employee, :doc_date, :contragent_raw,
                :contragent_phone, :subtotal, :total_due, :total_paid, :discount_type, :discount_value,
                :status, :price_type, :external_order_id, :source_file, :imported_at)
             ON CONFLICT(id) DO UPDATE SET
                action = excluded.action,
                doc_date = excluded.doc_date,
                subtotal = excluded.subtotal,
                total_due = excluded.total_due,
                total_paid = excluded.total_paid,
                status = excluded.status'
        );

        foreach ($rows as $row) {
            $id = $row['# Документа'] ?? null;
            if ($id === null || $id === '') {
                $skipped++;
                continue;
            }

            [, $phone] = x2pos_parse_contragent($row['Контрагент'] ?? null);

            $upsert->execute([
                ':id' => (int) $id,
                ':action' => (string) ($row['Действие'] ?? ''),
                ':branch' => (string) ($row['Филиал'] ?? ''),
                ':employee' => (string) ($row['Сотрудник'] ?? ''),
                ':doc_date' => x2pos_parse_datetime_dmy($row['Дата'] ?? null),
                ':contragent_raw' => $row['Контрагент'] ?? null,
                ':contragent_phone' => $phone,
                ':subtotal' => $row['Подытог'] !== null ? (float) $row['Подытог'] : null,
                ':total_due' => $row['Итого к оплате'] !== null ? (float) $row['Итого к оплате'] : null,
                ':total_paid' => $row['Итого оплачено'] !== null ? (float) $row['Итого оплачено'] : null,
                ':discount_type' => $row['Тип скидки'] ?? null,
                ':discount_value' => $row['Размер скидки'] !== null ? (float) $row['Размер скидки'] : null,
                ':status' => (string) ($row['Статус'] ?? ''),
                ':price_type' => (string) ($row['Тип цены'] ?? ''),
                ':external_order_id' => $row['#  Внешнего заказа'] ?? null,
                ':source_file' => $sourceFile,
                ':imported_at' => $now,
            ]);
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => "чеки из $sourceFile: $imported строк, $skipped пропущено (нет # документа)",
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    x2pos_recompute_customer_stats($pdo);

    return ['imported' => $imported, 'skipped' => $skipped];
}

// Пересчёт LTV/дат покупок клиента по чекам, привязанным по телефону
// (раздел 4 ТЗ: "avg_purchase_interval_days — пересчитывается при импорте").
// Не построчный состав, но чек привязан к конкретному человеку — достаточно
// для LTV и оттока, даже без данных о том, какие товары он покупал.
function x2pos_recompute_customer_stats(PDO $pdo): void {
    $byPhone = [];
    $stmt = $pdo->query(
        "SELECT contragent_phone, doc_date, total_paid FROM sales_documents
         WHERE action = 'Продажа' AND contragent_phone IS NOT NULL AND doc_date IS NOT NULL"
    );
    foreach ($stmt as $row) {
        $byPhone[$row['contragent_phone']][] = $row;
    }

    $customers = $pdo->query('SELECT id, phone FROM customers WHERE phone IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        'UPDATE customers SET first_purchase_at = :first, last_purchase_at = :last,
            avg_purchase_interval_days = :avg_interval, ltv = :ltv WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($customers as $customer) {
            $phoneKey = x2pos_normalize_phone($customer['phone']);
            $docs = $byPhone[$phoneKey] ?? null;
            if (!$docs) {
                continue;
            }

            $dates = array_unique(array_map(fn($d) => substr($d['doc_date'], 0, 10), $docs));
            sort($dates);
            $ltv = array_sum(array_map(fn($d) => (float) $d['total_paid'], $docs));

            $avgInterval = null;
            if (count($dates) > 1) {
                $first = new DateTime($dates[0]);
                $last = new DateTime($dates[count($dates) - 1]);
                $avgInterval = $first->diff($last)->days / (count($dates) - 1);
            }

            $update->execute([
                ':first' => $dates[0],
                ':last' => $dates[count($dates) - 1],
                ':avg_interval' => $avgInterval,
                ':ltv' => $ltv,
                ':id' => $customer['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// "3 312" / "3 312.50" (X2POS форматирует числа с пробелом-разделителем тысяч,
// но не всегда — часть строк приходит как обычный int/float) -> float.
function x2pos_parse_number($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $normalized = str_replace(["\xC2\xA0", ' ', ','], ['', '', '.'], (string) $value);
    return $normalized === '' ? null : (float) $normalized;
}

// Размер зашит в конце названия товара в скобках: "МАЙКА ... (M)". Встречаются
// вложенные скобки для детской одежды с двумя системами размеров:
// "СПОРТИВНЫЙ КОСТЮМ ... (14 (XS))" -> размер "14 (XS)" — сохраняем как есть,
// это не баг файла, а двойная система размеров у производителя.
function x2pos_parse_size_from_name(string $name): ?string {
    if (preg_match('/\(((?:[^()]|\([^()]*\))*)\)\s*$/u', $name, $m)) {
        return $m[1];
    }
    return null;
}

// "Продажа #42609703" -> "42609703" (совпадает с id из sales_documents).
function x2pos_extract_doc_id(?string $doc): ?string {
    if ($doc !== null && preg_match('/#(\d+)/', $doc, $m)) {
        return $m[1];
    }
    return null;
}

// Телефон встречается и как "имя \n+7XXXXXXXXXX" (sales_documents), и как
// "+7XXXXXXXXXX имя" (эта выгрузка) — ищем цифры где угодно в строке.
function x2pos_extract_phone(?string $raw): ?string {
    if ($raw !== null && preg_match('/\d[\d\s]{8,}\d/', $raw, $m)) {
        return x2pos_normalize_phone($m[0]);
    }
    return null;
}

// Построчный состав продаж (артикул/размер/кол-во/маржа за строку) — то, чего
// не хватало в "Экспорте продаж" (там только итог чека). Разблокирует ABC/XYZ,
// /сток, /маржа, /связки (раздел 4 и 6 ТЗ). "Свободный товар" (ручная позиция
// без артикула, напр. подарочный сертификат) — пропускается, как и в номенклатуре.
function x2pos_import_sales_lines(PDO $pdo, array $rows, string $sourceFile): array {
    $now = date('c');
    $imported = 0;
    $skipped = 0;

    // Диапазон дат, реально покрытый файлом — считаем по ВСЕМ строкам (включая
    // "Свободный товар"), а не только по тем, что попадут в sales, иначе можно
    // не заметить крайний день, если он представлен только такой строкой.
    $dates = array_filter(array_map(
        fn($row) => x2pos_parse_date_dmy($row['Дата'] ?? null),
        $rows
    ));
    $minDate = !empty($dates) ? min($dates) : null;
    $maxDate = !empty($dates) ? max($dates) : null;

    $customersByPhone = [];
    foreach ($pdo->query('SELECT id, phone FROM customers WHERE phone IS NOT NULL') as $c) {
        $phoneKey = x2pos_normalize_phone($c['phone']);
        if ($phoneKey !== null) {
            $customersByPhone[$phoneKey] = $c['id'];
        }
    }

    $pdo->beginTransaction();
    try {
        // Идемпотентность и защита от задвоения при перекрывающихся периодах:
        // удаляем существующие строки по ДАТАМ, которые покрывает этот файл, а
        // не по имени файла — иначе повторная заливка "весь сентябрь" после
        // "по 15 сентября" под другим именем файла задвоила бы продажи за
        // 1-15 сентября (у sales нет естественного уникального ключа, в отличие
        // от # платежа / # документа, поэтому per-file DELETE был единственной
        // защитой, а она не распознаёт одинаковые даты в разных файлах).
        if ($minDate !== null) {
            $pdo->prepare('DELETE FROM sales WHERE channel = :channel AND sale_date BETWEEN :min AND :max')
                ->execute([':channel' => 'retail', ':min' => $minDate, ':max' => $maxDate]);
        }

        $insert = $pdo->prepare(
            'INSERT INTO sales (article, size, qty, price, sale_date, channel, customer_id, seller_id,
                cost_price, margin, discount, total_amount, x2pos_doc_id, source_file, imported_at)
             VALUES (:article, :size, :qty, :price, :sale_date, :channel, :customer_id, :seller_id,
                :cost_price, :margin, :discount, :total_amount, :x2pos_doc_id, :source_file, :imported_at)'
        );

        foreach ($rows as $row) {
            $articleRaw = $row['Артикул'] ?? null;
            if ($articleRaw === null || $articleRaw === '') {
                $skipped++;
                continue;
            }

            $phone = x2pos_extract_phone($row['Покупатель'] ?? null);
            $customerId = $phone !== null ? ($customersByPhone[$phone] ?? null) : null;

            $insert->execute([
                ':article' => norm_article((string) $articleRaw),
                ':size' => x2pos_parse_size_from_name((string) ($row['Товар'] ?? '')),
                ':qty' => (int) round((float) ($row['Количество'] ?? 0)),
                ':price' => x2pos_parse_number($row['Цена продажа'] ?? null),
                ':sale_date' => x2pos_parse_date_dmy($row['Дата'] ?? null),
                ':channel' => 'retail',
                ':customer_id' => $customerId,
                ':seller_id' => (string) ($row['Сотрудник'] ?? ''),
                ':cost_price' => x2pos_parse_number($row['Цена закуп'] ?? null),
                ':margin' => x2pos_parse_number($row['Маржа'] ?? null),
                ':discount' => x2pos_parse_number($row['Скидки'] ?? null),
                ':total_amount' => x2pos_parse_number($row['Сумма продажа'] ?? null),
                ':x2pos_doc_id' => x2pos_extract_doc_id($row['Документ'] ?? null),
                ':source_file' => $sourceFile,
                ':imported_at' => $now,
            ]);
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => "продажи по товарам из $sourceFile: $imported строк за $minDate..$maxDate, $skipped пропущено (\"Свободный товар\" без артикула)",
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

// Прайслист группирует товары строками-заголовками категорий ("аптечка",
// "Головные уборы" — только колонка "Наименование" заполнена, остальное null,
// "#" не число) вперемешку со строками товаров ("#" — порядковый int). Не
// содержит себестоимость (раздел 2.2 ТЗ: "цены, желательно себестоимость" —
// тут только розница/скидка), поэтому обновляет только цену, не трогая
// cost_price/name/category у уже существующих товаров из номенклатуры.
//
// Некоторые версии прайслиста содержат фото товаров, вклеенные поверх листа
// (не в ячейках — см. xlsx_read_row_images()). Если $xlsxPath передан, они
// извлекаются в $photosDir и путь пишется в products.photo_path. Один файл
// на артикул (у разных размеров одного товара фото повторяется — сохраняем
// один раз, а не 5-10 копий).
function x2pos_import_pricelist(PDO $pdo, array $rows, string $sourceFile, ?string $xlsxPath = null, int $headerRowIndex = 0, ?string $photosDir = null): array {
    $now = date('c');
    $imported = 0;
    $skipped = 0;
    $photosSaved = 0;
    $currentCategory = null;

    // Первый проход: для каждого артикула, у которого хоть одна строка (любого
    // размера) несёт фото, извлечь и сохранить файл один раз.
    $photoPathByArticle = [];
    if ($xlsxPath !== null && $photosDir !== null) {
        $rowToMedia = xlsx_read_row_images($xlsxPath);
        if (!empty($rowToMedia) && !is_dir($photosDir)) {
            mkdir($photosDir, 0775, true);
        }
        foreach ($rows as $i => $row) {
            if (!is_int($row['#'] ?? null)) {
                continue;
            }
            $articleRaw = $row['Артикул'] ?? null;
            if ($articleRaw === null || $articleRaw === '') {
                continue;
            }
            $article = norm_article((string) $articleRaw);
            if (isset($photoPathByArticle[$article])) {
                continue;
            }
            $absoluteRow = $headerRowIndex + 1 + $i;
            if (!isset($rowToMedia[$absoluteRow])) {
                continue;
            }
            $bytes = xlsx_read_media_bytes($xlsxPath, $rowToMedia[$absoluteRow]);
            if ($bytes === null) {
                continue;
            }
            $ext = xlsx_detect_image_extension($bytes);
            $relativePath = "photos/{$article}.{$ext}";
            file_put_contents("{$photosDir}/{$article}.{$ext}", $bytes);
            $photoPathByArticle[$article] = $relativePath;
            $photosSaved++;
        }
    }

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO products (article, name, category, brand, retail_price, discount_price, price_updated_at, photo_path, updated_at)
             VALUES (:article, :name, :category, :brand, :retail_price, :discount_price, :price_updated_at, :photo_path, :updated_at)
             ON CONFLICT(article) DO UPDATE SET
                retail_price = excluded.retail_price,
                discount_price = excluded.discount_price,
                price_updated_at = excluded.price_updated_at,
                photo_path = COALESCE(excluded.photo_path, products.photo_path)'
        );

        foreach ($rows as $row) {
            $isDataRow = is_int($row['#'] ?? null);
            if (!$isDataRow) {
                if (trim((string) ($row['Наименование'] ?? '')) !== '') {
                    $currentCategory = $row['Наименование'];
                }
                continue;
            }

            $articleRaw = $row['Артикул'] ?? null;
            if ($articleRaw === null || $articleRaw === '') {
                $skipped++;
                continue;
            }

            $article = norm_article((string) $articleRaw);
            $upsert->execute([
                ':article' => $article,
                ':name' => (string) ($row['Наименование'] ?? ''),
                ':category' => $currentCategory,
                ':brand' => 'JOMA',
                ':retail_price' => x2pos_parse_number($row['Цена розница'] ?? null),
                ':discount_price' => x2pos_parse_number($row['Цена со скидкой'] ?? null),
                ':price_updated_at' => $now,
                ':photo_path' => $photoPathByArticle[$article] ?? null,
                ':updated_at' => $now,
            ]);
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :synced_at)'
        )->execute([
            ':source' => 'x2pos_file',
            ':status' => 'ok',
            ':details' => "прайслист из $sourceFile: $imported цен обновлено, $skipped пропущено (нет артикула), $photosSaved фото сохранено",
            ':synced_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'photos_saved' => $photosSaved];
}
