<?php
// Клиент Kaspi Магазин API v2 (guide.kaspi.kz/partner/ru/shop/api). В отличие
// от X2POS, здесь ЕСТЬ чтение истории продаж (/orders) — это отдельный,
// не пересекающийся с X2POS канал продаж (маркетплейс, не розница в
// магазине), поэтому пишем в те же sales с channel='kaspi', а не заменяем
// X2POS-импорт.
//
// Реальные ограничения, не описанные (или описанные не полностью) в
// документации — проверено на живом токене:
// - filter[orders][creationDate][$ge]/[$le] ОБЯЗАТЕЛЬНЫ, максимум 14 дней
//   между ними за один запрос — более длинные периоды тянем несколькими
//   последовательными 14-дневными окнами
// - customer.cellPhone замаскирован Kaspi ("+0(000)-000-00-00" для всех
//   заказов) — привязать покупателя с Kaspi к тому же customer, что и в
//   X2POS (там линковка по телефону), невозможно; kaspi-продажи идут без
//   customer_id
// - orderentries[].offer.code — это "АРТИКУЛ-РАЗМЕР" в точности в том же
//   формате, что naш article в products (проверено: "BFR111S2602-44" →
//   артикул "BFR111S2602" уже есть в базе от X2POS). Значит доп. запрос
//   /orderentries/{id}/product не нужен вообще — состав чека собирается из
//   одного вызова /orders/{id}/entries

function kaspi_api_get(string $urlOrPath): array {
    $url = str_starts_with($urlOrPath, 'http') ? $urlOrPath : KASPI_API_BASE . $urlOrPath;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . KASPI_API_TOKEN, 'Content-Type: application/vnd.api+json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200) {
        throw new RuntimeException("Kaspi GET $url failed: HTTP $httpCode, ответ: " . substr((string) $response, 0, 300));
    }
    $data = json_decode((string) $response, true);
    return is_array($data) ? $data : [];
}

// offer.code = "АРТИКУЛ-РАЗМЕР" — режем по ПОСЛЕДНЕМУ дефису. Если после
// разреза левая часть не находится в products, используем код как есть
// (без размера) — на случай единого товара без размерных вариантов, у
// которого в code дефиса вообще нет либо он не размерный.
function kaspi_split_offer_code(string $code, array $knownArticles): array {
    $lastDash = strrpos($code, '-');
    if ($lastDash === false) {
        return [$code, 'б/р'];
    }
    $candidateArticle = substr($code, 0, $lastDash);
    $candidateSize = substr($code, $lastDash + 1);
    if (isset($knownArticles[$candidateArticle])) {
        return [$candidateArticle, $candidateSize];
    }
    return [$code, 'б/р'];
}

// Заказы за один 14-дневный (максимум) интервал, включая состав (entries).
// Возвращает плоский список строк, готовых для вставки в sales.
function kaspi_fetch_orders_window(int $sinceMs, int $untilMs, array $knownArticles): array {
    $rows = [];
    $pageSize = 100;
    $pageNumber = 0;

    do {
        $result = kaspi_api_get('/orders?' . http_build_query([
            'page[number]' => $pageNumber,
            'page[size]' => $pageSize,
            'filter[orders][creationDate][$ge]' => $sinceMs,
            'filter[orders][creationDate][$le]' => $untilMs,
        ]));
        $orders = $result['data'] ?? [];

        foreach ($orders as $order) {
            $attr = $order['attributes'];
            if ($attr['status'] !== 'COMPLETED') {
                continue; // не завершённые/возвращённые — не считаем продажей (см. заголовок файла)
            }

            $entriesUrl = $order['relationships']['entries']['links']['related'] ?? null;
            if ($entriesUrl === null) {
                continue;
            }
            $entriesResult = kaspi_api_get($entriesUrl);
            $saleDate = date('Y-m-d H:i:s', intdiv((int) $attr['creationDate'], 1000));

            foreach ($entriesResult['data'] ?? [] as $entry) {
                $entryAttr = $entry['attributes'];
                $offerCode = $entryAttr['offer']['code'] ?? '';
                if ($offerCode === '') {
                    continue;
                }
                [$article, $size] = kaspi_split_offer_code($offerCode, $knownArticles);
                $qty = (float) $entryAttr['quantity'];
                $totalAmount = (float) $entryAttr['totalPrice'];

                $rows[] = [
                    'article' => $article,
                    'size' => $size,
                    'qty' => $qty,
                    'price' => $qty > 0 ? $totalAmount / $qty : 0,
                    'total_amount' => $totalAmount,
                    'sale_date' => $saleDate,
                    'kaspi_order_id' => $attr['code'],
                    'kaspi_city' => $attr['deliveryAddress']['town'] ?? null,
                ];
            }
        }

        $pageCount = $result['meta']['pageCount'] ?? 1;
        $pageNumber++;
    } while ($pageNumber < $pageCount);

    return $rows;
}

// Полный синк: от последнего успешного до сейчас, кусками по 14 дней (макс.
// окно API). Первый запуск без истории синка тянет $defaultDaysBack дней
// назад (по умолчанию 90 — глубже возможен, но обойдётся в кол-во окон/14).
function kaspi_run_full_sync(PDO $pdo, int $defaultDaysBack = 90): string {
    // Первый синк на 90 дней назад — это ~200+ заказов, каждый требует
    // отдельного запроса за составом (entries) → минуты, не секунды.
    // Дефолтный max_execution_time на ps.kz не подтверждён (раздел 10 ТЗ) —
    // снимаем ограничение явно, чтобы веб-хук не оборвался на полпути.
    set_time_limit(0);

    $since = x2pos_last_sync($pdo, 'kaspi_api_orders');
    $sinceTs = $since !== null ? strtotime($since) : strtotime("-$defaultDaysBack days");
    $nowTs = time();

    $knownArticles = array_flip($pdo->query('SELECT article FROM products')->fetchAll(PDO::FETCH_COLUMN));

    $allRows = [];
    $windowStart = $sinceTs;
    try {
        while ($windowStart < $nowTs) {
            $windowEnd = min($windowStart + 14 * 86400, $nowTs);
            $rows = kaspi_fetch_orders_window($windowStart * 1000, $windowEnd * 1000, $knownArticles);
            $allRows = array_merge($allRows, $rows);
            $windowStart = $windowEnd;
        }
    } catch (Throwable $e) {
        x2pos_log_sync($pdo, 'kaspi_api_orders', 'error', $e->getMessage());
        return "❌ Не удалось подключиться к Kaspi API: " . $e->getMessage();
    }

    if (empty($allRows)) {
        x2pos_log_sync($pdo, 'kaspi_api_orders', 'ok', '0 строк');
        return "✅ Синк с Kaspi завершён — новых завершённых заказов за период нет.";
    }

    // Тот же принцип дедупа, что и в x2pos_import_sales_lines(): удаляем по
    // фактическому диапазону дат в полученных данных и вставляем заново —
    // безопасно при повторных синках с перекрывающимся периодом.
    $dates = array_column($allRows, 'sale_date');
    $minDate = min($dates);
    $maxDate = max($dates);

    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM sales WHERE channel = 'kaspi' AND sale_date BETWEEN :min AND :max");
    $del->execute([':min' => $minDate, ':max' => $maxDate]);

    $insert = $pdo->prepare(
        "INSERT INTO sales (article, size, qty, price, total_amount, sale_date, channel, kaspi_order_id, kaspi_city, imported_at)
         VALUES (:article, :size, :qty, :price, :total_amount, :sale_date, 'kaspi', :kaspi_order_id, :kaspi_city, :now)"
    );
    $now = date('Y-m-d H:i:s');
    foreach ($allRows as $r) {
        $insert->execute([
            ':article' => $r['article'],
            ':size' => $r['size'],
            ':qty' => $r['qty'],
            ':price' => $r['price'],
            ':total_amount' => $r['total_amount'],
            ':sale_date' => $r['sale_date'],
            ':kaspi_order_id' => $r['kaspi_order_id'],
            ':kaspi_city' => $r['kaspi_city'],
            ':now' => $now,
        ]);
    }
    $pdo->commit();

    x2pos_log_sync($pdo, 'kaspi_api_orders', 'ok', count($allRows) . ' строк продаж');

    $orderCount = count(array_unique(array_column($allRows, 'kaspi_order_id')));
    return "✅ <b>Синк с Kaspi завершён</b>\n"
        . "• Заказов: <code>$orderCount</code>\n"
        . "• Строк продаж: <code>" . count($allRows) . "</code>\n\n"
        . "<i>Учтены только заказы в статусе COMPLETED. Клиент Kaspi не привязывается к клиенту X2POS — Kaspi маскирует номер телефона покупателя.</i>";
}
