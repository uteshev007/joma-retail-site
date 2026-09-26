<?php
// Клиент X2POS API v1.6 (см. JOMA_sales_bots_spec.md — там же оговорка, что
// продажи через API не читаются, только создаются; здесь синкаются только
// товары/категории/остатки/клиенты/платежи/приёмки).

function x2pos_api_auth(): string {
    $ch = curl_init(X2POS_API_BASE . '/auth');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['user' => X2POS_API_LOGIN, 'password' => X2POS_API_PASSWORD],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string) $response, true);
    if ($httpCode !== 200 || !isset($data['token'])) {
        throw new RuntimeException("X2POS auth failed: HTTP $httpCode, ответ: " . substr((string) $response, 0, 300));
    }
    return $data['token'];
}

// $params — ассоц. массив query-параметров (page, branch_id, changed_after и т.п.)
function x2pos_api_get(string $endpoint, string $token, array $params = []): array {
    $url = X2POS_API_BASE . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['API-KEY: ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("X2POS GET $endpoint failed: HTTP $httpCode, ответ: " . substr((string) $response, 0, 300));
    }
    $data = json_decode((string) $response, true);
    return is_array($data) ? $data : [];
}

function x2pos_last_sync(PDO $pdo, string $source): ?string {
    $stmt = $pdo->prepare("SELECT synced_at FROM sync_log WHERE source = :source AND status = 'ok' ORDER BY synced_at DESC LIMIT 1");
    $stmt->execute([':source' => $source]);
    $result = $stmt->fetchColumn();
    return $result ?: null;
}

function x2pos_log_sync(PDO $pdo, string $source, string $status, string $details): void {
    $stmt = $pdo->prepare("INSERT INTO sync_log (source, status, details, synced_at) VALUES (:source, :status, :details, :now)");
    $stmt->execute([':source' => $source, ':status' => $status, ':details' => $details, ':now' => date('Y-m-d H:i:s')]);
}

// Категории → карту [x2pos_category_id => name], используется при синке
// товаров (products.category должен остаться совместим с category_hierarchy.php,
// где ключи — русские названия категорий, так же как приходили из файла
// "Номенклатура"; здесь то же самое имя, просто из API вместо Excel).
function x2pos_sync_categories(PDO $pdo, string $token): array {
    $categories = x2pos_api_get('/categories', $token, ['branch_id' => 'all']);
    $map = [];
    foreach ($categories as $c) {
        if (($c['is_deleted'] ?? '0') === '1') {
            continue;
        }
        $map[$c['id']] = $c['name'];
    }
    return $map;
}

// Товары+разновидности → products/stock. Артикул берём из vendor_code (той
// же роли, что играл "Код товара"/"Артикул" в файловом импорте) — сначала
// у разновидности, если пусто — у товара целиком; так сохраняется
// совместимость с уже накопленной историей продаж, которая ключуется по
// этим же строковым артикулам, а не по числовым product_id/variation_id
// из API (те новые и ни на что в старых данных не сослались бы).
function x2pos_sync_products(PDO $pdo, string $token, array $categoryMap): array {
    $since = x2pos_last_sync($pdo, 'x2pos_api_products');
    $params = ['branch_id' => 'all'];
    if ($since !== null) {
        $params['changed_after'] = $since;
    }

    $upsertProduct = $pdo->prepare(
        "INSERT INTO products (article, name, category, x2pos_product_id, x2pos_category_id, updated_at)
         VALUES (:article, :name, :category, :product_id, :category_id, :now)
         ON CONFLICT(article) DO UPDATE SET
            name = excluded.name,
            category = excluded.category,
            x2pos_product_id = excluded.x2pos_product_id,
            x2pos_category_id = excluded.x2pos_category_id,
            updated_at = excluded.updated_at"
    );
    $upsertStock = $pdo->prepare(
        "INSERT INTO stock (article, size, x2pos_variation_id, updated_at)
         VALUES (:article, :size, :variation_id, :now)
         ON CONFLICT(article, size) DO UPDATE SET x2pos_variation_id = excluded.x2pos_variation_id, updated_at = excluded.updated_at"
    );

    $now = date('Y-m-d H:i:s');
    $variationToArticleSize = []; // variation_id => [article, size], нужно для синка остатков следом
    $page = 1;
    $productCount = 0;

    do {
        $params['page'] = $page;
        $products = x2pos_api_get('/products', $token, $params);
        foreach ($products as $p) {
            $productArticle = trim((string) ($p['product_vendor_code'] ?? ''));
            $categoryName = $categoryMap[$p['category_id']] ?? null;

            foreach ($p['variations'] ?? [] as $v) {
                $article = trim((string) ($v['vendor_code'] ?? '')) ?: $productArticle;
                if ($article === '') {
                    continue; // без артикула сопоставить с историей продаж невозможно
                }
                $size = $v['name'] !== null && $v['name'] !== '' ? (string) $v['name'] : 'б/р';

                $upsertProduct->execute([
                    ':article' => $article,
                    ':name' => $p['product_name'],
                    ':category' => $categoryName,
                    ':product_id' => $p['product_id'],
                    ':category_id' => $p['category_id'],
                    ':now' => $now,
                ]);
                $upsertStock->execute([
                    ':article' => $article,
                    ':size' => $size,
                    ':variation_id' => $v['id'],
                    ':now' => $now,
                ]);
                $variationToArticleSize[$v['id']] = [$article, $size];
                $productCount++;
            }
        }
        $page++;
    } while (count($products) > 0);

    x2pos_log_sync($pdo, 'x2pos_api_products', 'ok', "$productCount разновидностей товаров");
    return $variationToArticleSize;
}

// Остатки — по всем филиалам сразу (branch_id не передаём => текущая версия
// API возвращает по каждому филиалу отдельным вызовом судя по документации;
// без явного списка филиалов используем branch_id=all там, где поддерживается,
// иначе агрегируем по каждому известному филиалу).
function x2pos_sync_stock(PDO $pdo, string $token, array $variationToArticleSize, array $branchIds): int {
    $qtyByVariation = [];
    foreach ($branchIds as $branchId) {
        $stock = x2pos_api_get('/stock', $token, ['branch_id' => $branchId]);
        foreach ($stock as $variationId => $row) {
            $qtyByVariation[$variationId] = ($qtyByVariation[$variationId] ?? 0) + (float) $row['quantity'];
        }
    }

    $update = $pdo->prepare("UPDATE stock SET qty_on_hand = :qty, updated_at = :now WHERE x2pos_variation_id = :variation_id");
    $now = date('Y-m-d H:i:s');
    $count = 0;
    foreach ($qtyByVariation as $variationId => $qty) {
        if (!isset($variationToArticleSize[$variationId])) {
            continue; // остаток на разновидность, которую synced_products почему-то не увидел (напр. другая страница пагинации ещё не долетела) — пропускаем, догонит на следующем синке
        }
        $update->execute([':qty' => (int) $qty, ':now' => $now, ':variation_id' => $variationId]);
        $count++;
    }

    x2pos_log_sync($pdo, 'x2pos_api_stock', 'ok', "$count разновидностей остатков");
    return $count;
}

// Клиенты (is_supplier=1 пропускаем — это поставщики, не покупатели, тот же
// принцип, что и в файловом импорте контрагентов).
function x2pos_sync_customers(PDO $pdo, string $token): int {
    $since = x2pos_last_sync($pdo, 'x2pos_api_customers');
    $params = [];
    if ($since !== null) {
        $params['changed_after'] = $since;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO customers (x2pos_contragent_id, phone, name)
         VALUES (:contragent_id, :phone, :name)
         ON CONFLICT(x2pos_contragent_id) DO UPDATE SET phone = excluded.phone, name = excluded.name"
    );

    $page = 1;
    $count = 0;
    do {
        $params['page'] = $page;
        $customers = x2pos_api_get('/customers', $token, $params);
        foreach ($customers as $c) {
            if (($c['is_supplier'] ?? '0') === '1') {
                continue;
            }
            $upsert->execute([
                ':contragent_id' => $c['id'],
                ':phone' => norm_phone_x2pos_api((string) ($c['tel'] ?? '')),
                ':name' => $c['customer_name'] ?: $c['company_name'],
            ]);
            $count++;
        }
        $page++;
    } while (count($customers) > 0);

    x2pos_log_sync($pdo, 'x2pos_api_customers', 'ok', "$count клиентов");
    return $count;
}

function norm_phone_x2pos_api(string $tel): ?string {
    $digits = preg_replace('/\D/', '', $tel);
    return $digits !== '' ? $digits : null;
}

// Платежи — как есть, "id" из API совпадает по роли с "# платежа" из файла
// (natural key, стабилен между синками).
function x2pos_sync_payments(PDO $pdo, string $token): int {
    $since = x2pos_last_sync($pdo, 'x2pos_api_payments');
    $params = ['page' => 'all'];
    if ($since !== null) {
        $params['changed_after'] = $since;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO payments (id, payment_date, payment_method, counterparty, income, expense, purpose)
         VALUES (:id, :date, :method, :counterparty, :income, :expense, :purpose)
         ON CONFLICT(id) DO UPDATE SET
            payment_date = excluded.payment_date, payment_method = excluded.payment_method,
            counterparty = excluded.counterparty, income = excluded.income,
            expense = excluded.expense, purpose = excluded.purpose"
    );

    $result = x2pos_api_get('/payments', $token, $params);
    $payments = $result['payments'] ?? [];
    $count = 0;
    foreach ($payments as $p) {
        if (($p['is_deleted'] ?? '0') === '1') {
            continue;
        }
        $amount = (float) $p['amount'];
        $upsert->execute([
            ':id' => $p['id'],
            ':date' => $p['date_of_payment'],
            ':method' => $p['operation'],
            ':counterparty' => $p['customer_id'] ?: $p['supplier_id'],
            ':income' => $amount > 0 ? $amount : 0,
            ':expense' => $amount < 0 ? abs($amount) : 0,
            ':purpose' => $p['notes'],
        ]);
        $count++;
    }

    x2pos_log_sync($pdo, 'x2pos_api_payments', 'ok', "$count платежей");
    return $count;
}

// Приёмки (acceptance) → обновляем products.cost_price по последней
// завершённой приёмке на разновидность (buy_price) — этого не было ни в
// одной из файловых выгрузок X2POS, реальное усиление данных, не просто
// перенос существующего источника в API.
function x2pos_sync_procurement_costs(PDO $pdo, string $token, array $variationToArticleSize): int {
    $since = x2pos_last_sync($pdo, 'x2pos_api_procurements');
    $params = ['action' => 'acceptance', 'status' => 'completed', 'per_page' => 1000];
    if ($since !== null) {
        $params['changed_after'] = $since;
    }

    $update = $pdo->prepare("UPDATE products SET cost_price = :cost WHERE article = :article");
    $latestByArticle = []; // article => [date, cost] — берём самую свежую приёмку

    $page = 1;
    do {
        $params['page'] = $page;
        $result = x2pos_api_get('/procurements', $token, $params);
        $procurements = $result['procurements'] ?? [];
        foreach ($procurements as $doc) {
            foreach ($doc['procurement_items'] ?? [] as $item) {
                if (($item['is_deleted'] ?? '0') === '1') {
                    continue;
                }
                $variationId = $item['variation_id'];
                if (!isset($variationToArticleSize[$variationId])) {
                    continue;
                }
                [$article] = $variationToArticleSize[$variationId];
                $date = $doc['procurement_date'];
                if (!isset($latestByArticle[$article]) || $date > $latestByArticle[$article]['date']) {
                    $latestByArticle[$article] = ['date' => $date, 'cost' => (float) $item['buy_price']];
                }
            }
        }
        $page++;
    } while (count($procurements) > 0);

    foreach ($latestByArticle as $article => $info) {
        $update->execute([':cost' => $info['cost'], ':article' => $article]);
    }

    x2pos_log_sync($pdo, 'x2pos_api_procurements', 'ok', count($latestByArticle) . ' себестоимостей обновлено');
    return count($latestByArticle);
}

// Полный цикл синка — вызывается из кнопки бота или cron-скрипта.
// Продажи (чеки) сюда не входят — API их не отдаёт, см. заголовок файла.
function x2pos_run_full_sync(PDO $pdo): string {
    $lines = [];
    try {
        $token = x2pos_api_auth();
    } catch (Throwable $e) {
        x2pos_log_sync($pdo, 'x2pos_api_auth', 'error', $e->getMessage());
        return "❌ Не удалось подключиться к X2POS API: " . $e->getMessage();
    }

    try {
        $branches = x2pos_api_get('/company_settings', $token);
        $branchIds = array_keys($branches[0]['branches'] ?? []);

        $categoryMap = x2pos_sync_categories($pdo, $token);
        $variationMap = x2pos_sync_products($pdo, $token, $categoryMap);
        $stockCount = x2pos_sync_stock($pdo, $token, $variationMap, $branchIds);
        $customerCount = x2pos_sync_customers($pdo, $token);
        $paymentCount = x2pos_sync_payments($pdo, $token);
        $costCount = x2pos_sync_procurement_costs($pdo, $token, $variationMap);

        $lines[] = "✅ <b>Синк с X2POS API завершён</b>";
        $lines[] = "• Разновидностей товаров: <code>" . count($variationMap) . "</code>";
        $lines[] = "• Остатков обновлено: <code>$stockCount</code>";
        $lines[] = "• Клиентов: <code>$customerCount</code>";
        $lines[] = "• Платежей: <code>$paymentCount</code>";
        $lines[] = "• Себестоимостей обновлено (из приёмок): <code>$costCount</code>";
        $lines[] = "";
        $lines[] = "<i>Продажи API не отдаёт — их по-прежнему нужно загружать файлом «История продаж по товарам».</i>";
    } catch (Throwable $e) {
        x2pos_log_sync($pdo, 'x2pos_api_sync', 'error', $e->getMessage());
        $lines[] = "⚠️ Синк прошёл частично, ошибка: " . $e->getMessage();
    }

    return implode("\n", $lines);
}
