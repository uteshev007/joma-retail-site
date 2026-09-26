<?php
require_once __DIR__ . '/category_hierarchy.php';

// Отчёты по номенклатуре, остаткам и построчным продажам (X2POS "История
// продаж по товарам"). Команды, для которых продаж всё ещё нет, честно
// говорят об этом, а не показывают нули как будто это анализ.
//
// Единый стиль вывода: эмодзи-заголовок, короткие подписи, суммы в <code>
// для моноширинного выравнивания, нумерованные списки вместо длинных
// абзацев — чтобы отчёт читался за 5 секунд в Telegram, а не построчно.

function fmt_money(float $v): string {
    return number_format($v, 0, '', ' ') . ' тг.';
}

function fmt_ru_date(?string $iso): string {
    if (!$iso) {
        return '—';
    }
    $t = strtotime($iso);
    return $t ? date('d.m.Y', $t) : $iso;
}

// [срез страницы, номер страницы (зажат в границы), всего страниц]. Списки
// длиннее одной страницы (топ-10/15) резались молча — теперь можно листать
// кнопками ◀️/▶️ вместо того, чтобы обрезать данные.
function paginate_slice(array $items, int $page, int $perPage): array {
    $totalPages = max(1, (int) ceil(count($items) / $perPage));
    $page = max(1, min($page, $totalPages));
    return [array_slice($items, ($page - 1) * $perPage, $perPage), $page, $totalPages];
}

function fmt_page_footer(int $page, int $totalPages): string {
    return $totalPages > 1 ? "\n\n<i>Страница $page из $totalPages</i>" : '';
}

// Пагинация по "блокам" текста (напр. один отдел целиком), а не по числу
// элементов — так группировка по категориям никогда не рвётся посередине,
// а Telegram (лимит 4096 символов на сообщение) не обрезает текст молча.
function paginate_text_blocks(array $blocks, int $page, int $charBudget = 3300): array {
    $pages = [];
    $current = '';
    foreach ($blocks as $block) {
        if ($current !== '' && strlen($current) + strlen($block) > $charBudget) {
            $pages[] = $current;
            $current = '';
        }
        $current .= ($current === '' ? '' : "\n") . $block;
    }
    if ($current !== '') {
        $pages[] = $current;
    }
    $totalPages = max(1, count($pages));
    $page = max(1, min($page, $totalPages));
    return [$pages[$page - 1] ?? '', $page, $totalPages];
}

// Размеры в X2POS вперемешку: числовые (обувь "35".."45", одежда "42","44"),
// буквенные (S/M/L/XL...), диапазоны/двойные системы ("31-34", "14 (XS)"),
// служебное "б/р". Алфавитная сортировка ставит "10" перед "2" и не знает,
// что XL идёт после L — сортируем по смыслу: сначала все числовые по
// возрастанию (по ведущему числу — покрывает и диапазоны/двойные системы),
// потом буквенные в стандартном порядке одежды, "б/р" — всегда последним.
function size_sort_key(string $size): array {
    $size = trim($size);
    if ($size === 'б/р' || $size === '') {
        return [9, 0, $size];
    }
    if (preg_match('/^-?\d+(\.\d+)?/', $size, $m)) {
        return [0, (float) $m[0], $size];
    }
    $letterOrder = [
        '3XS' => -4, 'XXXS' => -4,
        '2XS' => -3, 'XXS' => -3,
        'XS' => -2,
        'S' => -1,
        'M' => 0,
        'L' => 1,
        'XL' => 2,
        '2XL' => 3, 'XXL' => 3,
        '3XL' => 4, 'XXXL' => 4,
        '4XL' => 5,
    ];
    $upper = mb_strtoupper($size);
    if (isset($letterOrder[$upper])) {
        return [1, $letterOrder[$upper], $size];
    }
    return [5, 0, $size];
}

function size_compare(array $a, array $b): int {
    return size_sort_key($a['size']) <=> size_sort_key($b['size']);
}

// $rows — [['article'=>.., 'category'=>.., 'qty'=>..], ...], одна строка на
// товар (не заранее сгруппированная по категории!) — нужна article, чтобы
// точечные исключения (X2POS_ARTICLE_OVERRIDES) могли увести конкретный
// товар в другую подкатегорию, а не всю его категорию целиком ("В.Одежда"
// в X2POS мешает майки, куртку и платья под одним именем). Строит дерево
// отдел → подкатегория → категория X2POS и рисует его с суммами на каждом
// уровне. $kidsMap (article => bool) — если передан, категории с товарами
// и для детей, и для взрослых показываются двумя отдельными строками
// ("футболка" / "футболка (дети)"), а не одной цифрой на всех.
const CATEGORY_DEPARTMENT_ORDER = ['Обувь', 'Одежда', 'Аксессуары', 'Инвентарь', 'Без категории'];

function strip_kids_suffix(string $catLabel): string {
    return preg_replace('/ \(дети\)$/u', '', $catLabel);
}

function render_category_tree(array $rows, array $kidsMap = []): array {
    $tree = [];
    foreach ($rows as $row) {
        $article = $row['article'] ?? null;
        $cat = $row['category'] ?: null;
        $dep = category_department($cat, $article);
        $sub = category_subcategory($cat, $article);
        $catLabel = ($cat ?: 'без категории') . (($kidsMap[$article] ?? false) ? ' (дети)' : '');
        $tree[$dep][$sub][$catLabel] = ($tree[$dep][$sub][$catLabel] ?? 0) + $row['qty'];
    }

    $departments = array_keys($tree);
    usort($departments, fn($a, $b) => array_search($a, CATEGORY_DEPARTMENT_ORDER) <=> array_search($b, CATEGORY_DEPARTMENT_ORDER));

    // Отступы обычными пробелами в Telegram визуально не читаются (переносы
    // строк есть, а вложенность на глаз не видна) — вложенность показываем
    // явными маркерами (▪️/•) и отдельной строкой на каждую категорию, а не
    // длинным списком в скобках, который на телефоне не помещается в одну
    // строку и переносится без понятной структуры.
    $lines = [];
    foreach ($departments as $dep) {
        $depQty = 0;
        foreach ($tree[$dep] as $catQtys) {
            $depQty += array_sum($catQtys);
        }
        $lines[] = "";
        $lines[] = sprintf("🔹 <b>%s</b> — <code>%d шт.</code>", $dep, $depQty);

        $subcats = $tree[$dep];
        uasort($subcats, fn($a, $b) => array_sum($b) <=> array_sum($a));

        foreach ($subcats as $sub => $catQtys) {
            $subQty = array_sum($catQtys);
            arsort($catQtys);
            $onlyCat = strip_kids_suffix(array_key_first($catQtys));
            // Если под подкатегорией всего одна категория X2POS, стоящая тут
            // по умолчанию (не через исключение по артикулу) — не дублируем
            // название, там и так всё видно по подкатегории.
            if (count($catQtys) === 1 && $sub === category_subcategory($onlyCat === 'без категории' ? null : $onlyCat)) {
                $lines[] = sprintf("▪️ %s — <code>%d шт.</code>", $sub, $subQty);
            } else {
                $lines[] = sprintf("▪️ %s — <code>%d шт.</code>", $sub, $subQty);
                foreach ($catQtys as $name => $qty) {
                    $lines[] = sprintf("     • %s — <code>%d шт.</code>", $name, $qty);
                }
            }
        }
    }

    return $lines;
}

function report_summary(PDO $pdo): string {
    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $totalStock = (int) $pdo->query('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock')->fetchColumn();
    $salesCount = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $customerCount = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $paymentsCount = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();

    $byArticle = $pdo->query(
        'SELECT p.article, p.category, COALESCE(SUM(s.qty_on_hand), 0) AS qty
         FROM products p
         LEFT JOIN stock s ON s.article = p.article
         GROUP BY p.article'
    )->fetchAll(PDO::FETCH_ASSOC);

    $zeroStock = (int) $pdo->query(
        'SELECT COUNT(DISTINCT p.article) FROM products p
         LEFT JOIN stock s ON s.article = p.article
         GROUP BY p.article
         HAVING COALESCE(SUM(s.qty_on_hand), 0) = 0'
    )->fetchColumn();

    $lines = [];
    $lines[] = "📊 <b>Сводный дайджест</b>";
    $lines[] = "";
    $lines[] = "📦 Товаров в номенклатуре: <code>$productCount</code>";
    $lines[] = "📥 Суммарный остаток: <code>$totalStock шт.</code>";
    $lines[] = "🚫 С нулевым остатком: <code>$zeroStock</code>";
    $lines[] = "👥 Покупателей в базе: <code>$customerCount</code>";
    $lines[] = "💳 Платежей в базе: <code>$paymentsCount</code>";
    $lines[] = "";
    $lines[] = "<b>Остаток по отделам</b>";
    foreach (render_category_tree($byArticle, compute_kids_map($pdo)) as $line) {
        $lines[] = $line;
    }

    if ($salesCount === 0) {
        $lines[] = "";
        $lines[] = "⚠️ Продажи не импортированы — ABC/XYZ, /сток, /маржа пока недоступны. Загрузите выгрузку X2POS «Продажи по товарам».";
    }

    return implode("\n", $lines);
}

function report_size_matrix(PDO $pdo, ?string $article): string {
    if ($article !== null) {
        $stmt = $pdo->prepare('SELECT size, qty_on_hand FROM stock WHERE article = :a');
        $stmt->execute([':a' => norm_article($article)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, 'size_compare');

        if (empty($rows)) {
            return "🚫 Артикул <b>" . norm_article($article) . "</b> не найден в остатках.";
        }

        $product = $pdo->prepare('SELECT name FROM products WHERE article = :a');
        $product->execute([':a' => norm_article($article)]);
        $name = $product->fetchColumn() ?: '';

        $lines = ["👟 <b>{$article}</b>"];
        if ($name !== '') {
            $lines[] = $name;
        }
        $lines[] = "";
        foreach ($rows as $r) {
            $lines[] = sprintf("%s — <code>%d шт.</code>", $r['size'], $r['qty_on_hand']);
        }
        return implode("\n", $lines);
    }

    // Без артикула — вызывающий код должен использовать report_size_matrix_list().
    return report_size_matrix_list($pdo, 1)['text'];
}

// Сводка остатков по размерам во всей номенклатуре, в порядке размера (не по
// количеству — иначе список выглядит перемешанным), постранично.
function report_size_matrix_list(PDO $pdo, int $page = 1): array {
    $rows = $pdo->query(
        'SELECT size, SUM(qty_on_hand) AS qty FROM stock GROUP BY size'
    )->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, 'size_compare');

    [$slice, $page, $totalPages] = paginate_slice($rows, $page, 15);

    $lines = ["📏 <b>Остаток по размерам</b> (все товары)", ""];
    foreach ($slice as $r) {
        $lines[] = sprintf("%s — <code>%d шт.</code>", $r['size'], $r['qty']);
    }
    $lines[] = fmt_page_footer($page, $totalPages);

    return ['text' => implode("\n", $lines), 'page' => $page, 'total_pages' => $totalPages];
}

// ABC (доля в накопленной выручке: A — первые 80%, B — следующие 15%, C —
// остальное 5%) + XYZ (коэффициент вариации недельных продаж: X <=10% —
// стабильный спрос, Y <=25%, Z — нерегулярный) по разделу 6 ТЗ.
function compute_abc_xyz(PDO $pdo, int $days): array {
    $since = date('Y-m-d', strtotime("-$days days"));

    $revenueRows = $pdo->prepare(
        "SELECT s.article, p.name, p.category, SUM(s.total_amount) AS revenue, SUM(s.qty) AS qty
         FROM sales s LEFT JOIN products p ON p.article = s.article
         WHERE s.sale_date >= :since
         GROUP BY s.article
         ORDER BY revenue DESC"
    );
    $revenueRows->execute([':since' => $since]);
    $articles = $revenueRows->fetchAll(PDO::FETCH_ASSOC);

    $totalRevenue = array_sum(array_column($articles, 'revenue'));
    $cumulative = 0;
    foreach ($articles as &$a) {
        $cumulative += $a['revenue'];
        $pct = $totalRevenue > 0 ? $cumulative / $totalRevenue : 1;
        $a['abc'] = $pct <= 0.80 ? 'A' : ($pct <= 0.95 ? 'B' : 'C');
    }
    unset($a);

    // Недельные суммы qty по артикулу, с нулями за недели без продаж —
    // иначе товар с 1 всплеском продаж выглядел бы стабильным (мало точек).
    $weekly = $pdo->prepare(
        "SELECT article, strftime('%Y-%W', sale_date) AS week, SUM(qty) AS qty
         FROM sales WHERE sale_date >= :since
         GROUP BY article, week"
    );
    $weekly->execute([':since' => $since]);
    $byArticleWeek = [];
    $allWeeks = [];
    foreach ($weekly as $row) {
        $byArticleWeek[$row['article']][$row['week']] = (float) $row['qty'];
        $allWeeks[$row['week']] = true;
    }
    $allWeeks = array_keys($allWeeks);

    // Классические пороги XYZ (X <=10%, Y <=25% коэфф. вариации) рассчитаны на
    // крупный ритейл с десятками продаж артикула в неделю. При объёме этого
    // магазина (1-2 продажи/неделю на артикул) недельная дисперсия огромна
    // почти всегда — фиксированные пороги дали 100% "Z" даже на топовых
    // товарах (проверено на реальных данных). Делим по ОТНОСИТЕЛЬНОЙ
    // изменчивости: нижняя треть по CV — самый стабильный спрос (X), верхняя
    // треть — самый нестабильный (Z). Это всегда информативно, независимо от
    // масштаба продаж, и не требует калибровки "универсальных" порогов.
    $cvByArticle = [];
    foreach ($byArticleWeek as $article => $weeks) {
        $values = [];
        foreach ($allWeeks as $w) {
            $values[] = $weeks[$w] ?? 0.0;
        }
        $mean = array_sum($values) / count($values);
        if ($mean <= 0) {
            $cvByArticle[$article] = INF;
            continue;
        }
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
        $cvByArticle[$article] = sqrt($variance) / $mean;
    }

    $sortedCv = $cvByArticle;
    asort($sortedCv);
    $ranked = array_keys($sortedCv);
    $total = count($ranked);

    $xyzByArticle = [];
    foreach ($ranked as $rank => $article) {
        $percentile = $total > 1 ? $rank / ($total - 1) : 0;
        $xyzByArticle[$article] = $percentile <= 0.33 ? 'X' : ($percentile <= 0.66 ? 'Y' : 'Z');
    }

    foreach ($articles as &$a) {
        $a['xyz'] = $xyzByArticle[$a['article']] ?? 'Z';
    }
    unset($a);

    // Разовая оптовая партия (напр. 12 мячей одним чеком постороннему опто-
    // вику после 3 месяцев нулевых продаж) поднимает выручку артикула в
    // ABC-рейтинге ровно так же, как стабильный спрос — по сумме выручки это
    // неотличимо, хотя по факту это разовая сделка, а не хит. Помечаем
    // артикул как "разовую партию", если вся (или почти вся) его выручка за
    // период идёт из ОДНОГО чека: либо чек вообще единственный, либо один чек
    // даёт >70% количества даже при наличии других чеков.
    $docStats = $pdo->prepare(
        "SELECT article, x2pos_doc_id, SUM(qty) AS doc_qty
         FROM sales WHERE sale_date >= :since AND x2pos_doc_id IS NOT NULL
         GROUP BY article, x2pos_doc_id"
    );
    $docStats->execute([':since' => $since]);
    $docsByArticle = [];
    foreach ($docStats as $row) {
        $docsByArticle[$row['article']][] = (float) $row['doc_qty'];
    }

    foreach ($articles as &$a) {
        $docs = $docsByArticle[$a['article']] ?? [];
        $totalQty = array_sum($docs);
        $maxDocQty = !empty($docs) ? max($docs) : 0;
        $a['distinct_docs'] = count($docs);
        $a['is_wholesale_spike'] = count($docs) < 2 || ($totalQty > 0 && $maxDocQty / $totalQty > 0.7);
    }
    unset($a);

    return $articles;
}

// Расшифровка кодов вида [AX]/[BZ] — первая буква откуда ABC, вторая откуда
// XYZ, показывается под каждым списком, где встречаются эти коды.
function abc_xyz_legend(): string {
    return "<i>Код [XY]: 1-я буква — доля в выручке (A — топ-80%, B — следующие 15%, "
        . "C — остальные 5%); 2-я — стабильность спроса среди ваших товаров "
        . "(X — самый стабильный, Y — средний, Z — самый нестабильный).</i>";
}

// Короткая версия для списков, где показывается только буква XYZ (ABC уже
// известна из контекста — например, "Аутсайдеры" — это всегда C-класс).
function xyz_legend(): string {
    return "<i>Буква — стабильность спроса среди ваших товаров "
        . "(X — самый стабильный, Y — средний, Z — самый нестабильный).</i>";
}

// Возвращает ['text'=>.., 'page'=>.., 'total_pages'=>..] — вызывающий код
// (webhook) достраивает кнопки ◀️/▶️ по page/total_pages.
function report_abc(PDO $pdo, int $days = 30, int $page = 1): array {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return ['text' => report_needs_sales_data('/abc'), 'page' => 1, 'total_pages' => 1];
    }

    $articles = compute_abc_xyz($pdo, $days);
    $counts = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($articles as $a) {
        $counts[$a['abc']]++;
    }

    [$slice, $page, $totalPages] = paginate_slice($articles, $page, 10);

    $lines = ["🏆 <b>ABC/XYZ за $days дней</b>", ""];
    $lines[] = sprintf("A: <code>%d</code> товаров (80%% выручки)", $counts['A']);
    $lines[] = sprintf("B: <code>%d</code> товаров (15%%)", $counts['B']);
    $lines[] = sprintf("C: <code>%d</code> товаров (5%%)", $counts['C']);
    $lines[] = "";
    $lines[] = "<b>Топ по выручке</b>";
    $offset = ($page - 1) * 10;
    foreach ($slice as $i => $a) {
        $name = $a['name'] ?: $a['article'];
        $spike = $a['is_wholesale_spike'] ? ' 📦разовая партия' : '';
        $lines[] = sprintf("%d. %s — <code>%s</code> [%s%s]%s", $offset + $i + 1, $name, fmt_money($a['revenue']), $a['abc'], $a['xyz'], $spike);
    }
    $lines[] = "";
    $lines[] = abc_xyz_legend();
    $lines[] = "<i>📦разовая партия — почти вся выручка из одного чека, не стабильный спрос.</i>";
    $lines[] = fmt_page_footer($page, $totalPages);

    return ['text' => implode("\n", $lines), 'page' => $page, 'total_pages' => $totalPages];
}

function report_outliers(PDO $pdo, int $days = 30, int $page = 1): array {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return ['text' => report_needs_sales_data('/аутсайдеры'), 'page' => 1, 'total_pages' => 1];
    }

    $articles = compute_abc_xyz($pdo, $days);
    $cSlow = array_values(array_filter($articles, fn($a) => $a['abc'] === 'C'));
    usort($cSlow, fn($a, $b) => $a['revenue'] <=> $b['revenue']);

    [$slice, $page, $totalPages] = paginate_slice($cSlow, $page, 15);

    $lines = ["📉 <b>Аутсайдеры за $days дней</b>", "<i>C-класс — 5% выручки, кандидаты на вывод/распродажу</i>", ""];
    $offset = ($page - 1) * 15;
    foreach ($slice as $i => $a) {
        $name = $a['name'] ?: $a['article'];
        $lines[] = sprintf("%d. %s — <code>%s</code>, %d шт. [%s]", $offset + $i + 1, $name, fmt_money($a['revenue']), $a['qty'], $a['xyz']);
    }
    if (empty($cSlow)) {
        $lines[] = "нет данных за этот период";
    }
    $lines[] = "";
    $lines[] = xyz_legend();
    $lines[] = fmt_page_footer($page, $totalPages);

    return ['text' => implode("\n", $lines), 'page' => $page, 'total_pages' => $totalPages];
}

// раздел 6 ТЗ: скорость = продано за N недель / N; дней_до_нуля = остаток / (скорость/7)
function compute_stock_forecast(PDO $pdo, ?string $articleArg, int $weeks): array {
    $since = date('Y-m-d', strtotime("-{$weeks} weeks"));

    $sql = "SELECT s.article, p.name, SUM(s.qty) AS sold, COALESCE(SUM(st.qty_on_hand), 0) AS stock
            FROM sales s
            LEFT JOIN products p ON p.article = s.article
            LEFT JOIN stock st ON st.article = s.article
            WHERE s.sale_date >= :since";
    $params = [':since' => $since];
    if ($articleArg !== null) {
        $sql .= " AND s.article = :article";
        $params[':article'] = norm_article($articleArg);
    }
    $sql .= " GROUP BY s.article";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $forecast = [];
    foreach ($rows as $r) {
        $velocityPerWeek = $r['sold'] / $weeks;
        if ($velocityPerWeek <= 0) {
            continue;
        }
        $daysToZero = $r['stock'] / ($velocityPerWeek / 7);
        $forecast[] = [
            'article' => $r['article'],
            'name' => $r['name'] ?: $r['article'],
            'stock' => $r['stock'],
            'days_to_zero' => $daysToZero,
        ];
    }
    usort($forecast, fn($a, $b) => $a['days_to_zero'] <=> $b['days_to_zero']);
    return $forecast;
}

// Список для закупки, по КОНКРЕТНОМУ РАЗМЕРУ, а не по артикулу в целом:
// "товар есть в наличии" на уровне артикула ничего не говорит о том, что
// именно ходовой размер S уже кончился, пока лежат нераспроданные XL —
// именно эта проблема (раздел 6 ТЗ, "перекос закупки") здесь и ищется.
// "Стабильный розничный спрос" проверяется на уровне АРТИКУЛА (не размера):
// не разовая оптовая партия — тот же принцип, что и is_wholesale_spike в
// compute_abc_xyz, пересчитан на своём окне в неделях. Артикул, который в
// целом продаётся разовыми оптовыми партиями, не подходит целиком — даже
// если один его размер формально "кончился".
function compute_procurement_needs(PDO $pdo, int $weeks = 8): array {
    $since = date('Y-m-d', strtotime("-{$weeks} weeks"));

    // Стабильность спроса — по артикулу целиком (см. комментарий выше).
    $docStats = $pdo->prepare(
        "SELECT article, x2pos_doc_id, SUM(qty) AS doc_qty
         FROM sales WHERE sale_date >= :since AND channel = 'retail' AND x2pos_doc_id IS NOT NULL
         GROUP BY article, x2pos_doc_id"
    );
    $docStats->execute([':since' => $since]);
    $docsByArticle = [];
    foreach ($docStats as $r) {
        $docsByArticle[$r['article']][] = (float) $r['doc_qty'];
    }
    $steadyArticles = [];
    foreach ($docsByArticle as $article => $docs) {
        $totalDocQty = array_sum($docs);
        $maxDocQty = max($docs);
        $steadyArticles[$article] = count($docs) >= 2 && ($totalDocQty <= 0 || $maxDocQty / $totalDocQty <= 0.7);
    }

    // Продажи и остаток — по (артикул, размер). "б/р" у товаров без размера
    // в stock хранится строкой, а в sales размер для них NULL (нет скобок в
    // названии) — приравниваем через COALESCE, иначе они не сойдутся.
    $sold = $pdo->prepare(
        "SELECT s.article, COALESCE(s.size, 'б/р') AS size, p.name, p.category, SUM(s.qty) AS sold
         FROM sales s LEFT JOIN products p ON p.article = s.article
         WHERE s.sale_date >= :since AND s.channel = 'retail'
         GROUP BY s.article, size"
    );
    $sold->execute([':since' => $since]);
    $sizeSales = $sold->fetchAll(PDO::FETCH_ASSOC);

    $sizeStock = [];
    foreach ($pdo->query('SELECT article, size, qty_on_hand FROM stock') as $r) {
        $sizeStock[$r['article']][$r['size']] = (int) $r['qty_on_hand'];
    }

    $sizeNeeds = [];
    foreach ($sizeSales as $s) {
        if (!($steadyArticles[$s['article']] ?? false)) {
            continue;
        }
        if ($s['sold'] < 2) {
            continue; // 1 продажа этого размера за 8 недель — не спрос, случайность
        }

        $velocityPerWeek = $s['sold'] / $weeks;
        $stock = $sizeStock[$s['article']][$s['size']] ?? 0;
        $daysToZero = $stock > 0 ? $stock / ($velocityPerWeek / 7) : 0.0;
        if ($stock > 0 && $daysToZero >= 15) {
            continue; // этого размера хватает больше чем на 2 недели — не срочно
        }

        $suggestedQty = max(1, (int) ceil($velocityPerWeek * 4) - $stock);

        $sizeNeeds[$s['article']][] = [
            'article' => $s['article'],
            'name' => $s['name'] ?: $s['article'],
            'category' => $s['category'],
            'size' => $s['size'],
            'stock' => $stock,
            'sold' => (int) $s['sold'],
            'days_to_zero' => $daysToZero,
            'suggested_qty' => $suggestedQty,
        ];
    }

    // Если у артикула ВСЕ размеры с реальным спросом сейчас на нуле — это не
    // "перекос размеров", это весь товар кончился; показываем одной строкой
    // по артикулу, а не N одинаковых строк "размер X — 0, размер Y — 0".
    // Иначе (есть хоть один размер в наличии) — показываем именно те размеры,
    // которых не хватает, это и есть ответ на "какого размера нет".
    $needs = [];
    foreach ($sizeNeeds as $article => $sizes) {
        $allOut = !array_filter($sizes, fn($sz) => $sz['stock'] > 0);
        if ($allOut && count($sizes) > 1) {
            $totalSold = array_sum(array_column($sizes, 'sold'));
            $totalSuggested = array_sum(array_column($sizes, 'suggested_qty'));
            $sizeList = implode(', ', array_column($sizes, 'size'));
            $needs[] = [
                'article' => $article,
                'name' => $sizes[0]['name'],
                'category' => $sizes[0]['category'],
                'size' => $sizeList,
                'stock' => 0,
                'sold' => $totalSold,
                'days_to_zero' => 0.0,
                'suggested_qty' => $totalSuggested,
                'bucket' => 'out',
            ];
        } else {
            foreach ($sizes as $sz) {
                $sz['bucket'] = $sz['stock'] <= 0 ? 'out' : 'soon';
                $needs[] = $sz;
            }
        }
    }

    usort($needs, fn($a, $b) => $a['days_to_zero'] <=> $b['days_to_zero']);
    return $needs;
}

// Один артикул текстом — /сток АРТИКУЛ, без пагинации (там одна позиция).
function report_stock_forecast(PDO $pdo, string $articleArg, int $weeks = 8): string {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return report_needs_sales_data('/сток');
    }

    $forecast = compute_stock_forecast($pdo, $articleArg, $weeks);
    if (empty($forecast)) {
        return "Нет продаж за последние $weeks недель по артикулу <b>" . norm_article($articleArg) . "</b> — прогноз не построить.";
    }
    $f = $forecast[0];
    return sprintf(
        "⏳ <b>%s</b> (%s)\n\nОстаток: <code>%d шт.</code>\nПрогноз до обнуления: <code>~%.0f дней</code>",
        $f['name'], $f['article'], $f['stock'], $f['days_to_zero']
    );
}

// Список по всем товарам — кнопка /сток без артикула, с пагинацией.
// Список для закупки, сгруппированный по отделам — только товары со
// стабильным розничным спросом (см. compute_procurement_needs), у которых
// сейчас нет остатка или он на исходе. Разовые оптовые продажи намеренно не
// попадают сюда — довозить их не нужно, это не постоянный спрос.
function report_stock_forecast_list(PDO $pdo, int $weeks = 8, int $page = 1): array {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return ['text' => report_needs_sales_data('/сток'), 'page' => 1, 'total_pages' => 1];
    }

    $needs = compute_procurement_needs($pdo, $weeks);
    $kidsMap = compute_kids_map($pdo);

    $header = "📋 <b>Список на закупку</b>\n<i>стабильный розничный спрос за последние $weeks недель, разовые оптовые продажи исключены</i>";

    $out = array_values(array_filter($needs, fn($n) => $n['bucket'] === 'out'));
    $soon = array_values(array_filter($needs, fn($n) => $n['bucket'] === 'soon'));

    // Список бьётся на "блоки" (один отдел целиком — не рвём группировку по
    // категориям), а не по числу товаров: Telegram режет сообщение молча на
    // 4096 символах, и с ~30 товарами это легко превышается.
    $blocks = [$header];
    foreach ([
        ['🚫 Уже нет в наличии — теряете продажи прямо сейчас', $out, true],
        ['⏳ Скоро закончится (&lt;15 дней) — планируйте довоз', $soon, false],
    ] as [$title, $items, $isOut]) {
        if (empty($items)) {
            $blocks[] = "<b>$title</b>\nнет";
            continue;
        }

        $tree = [];
        foreach ($items as $item) {
            $dep = category_department($item['category'], $item['article']);
            $sub = category_subcategory($item['category'], $item['article']);
            $tree[$dep][$sub][] = $item;
        }
        $departments = array_keys($tree);
        usort($departments, fn($a, $b) => array_search($a, CATEGORY_DEPARTMENT_ORDER) <=> array_search($b, CATEGORY_DEPARTMENT_ORDER));

        $firstInBucket = true;
        foreach ($departments as $dep) {
            $depLines = [];
            if ($firstInBucket) {
                $depLines[] = "<b>$title</b>";
                $firstInBucket = false;
            }
            $depLines[] = sprintf("🔹 <b>%s</b>", $dep);
            $subcats = $tree[$dep];
            foreach ($subcats as $sub => $subItems) {
                $depLines[] = "▪️ $sub";
                usort($subItems, fn($a, $b) => $a['days_to_zero'] <=> $b['days_to_zero']);
                foreach ($subItems as $item) {
                    $kidsTag = ($kidsMap[$item['article']] ?? false) ? ' (дети)' : '';
                    if ($item['size'] === 'б/р') {
                        $sizeLabel = '';
                    } elseif (str_contains($item['size'], ', ')) {
                        $sizeLabel = ", все размеры ({$item['size']})";
                    } else {
                        $sizeLabel = ", размер {$item['size']}";
                    }
                    if ($isOut) {
                        $depLines[] = sprintf(
                            "     • %s%s (%s)%s — продано %d шт./%d нед., довезти ~%d шт.",
                            $item['name'], $kidsTag, $item['article'], $sizeLabel, $item['sold'], $weeks, $item['suggested_qty']
                        );
                    } else {
                        $depLines[] = sprintf(
                            "     • %s%s (%s)%s — <code>%d шт.</code>, ~%.0f дн. до нуля, довезти ~%d шт.",
                            $item['name'], $kidsTag, $item['article'], $sizeLabel, $item['stock'], $item['days_to_zero'], $item['suggested_qty']
                        );
                    }
                }
            }
            $blocks[] = implode("\n", $depLines);
        }
    }
    $blocks[] = "<i>Рекомендуемый закуп — грубая оценка на 4 недели спроса вперёд, без учёта сезонности и сроков поставки.</i>";

    [$text, $page, $totalPages] = paginate_text_blocks($blocks, $page);
    return ['text' => $text . fmt_page_footer($page, $totalPages), 'page' => $page, 'total_pages' => $totalPages];
}

// Раздел 6 ТЗ: маржа за строку берётся из X2POS как есть (см. schema.sql),
// а не пересчитывается из products.cost_price, который может быть устаревшим.
function report_margin(PDO $pdo, int $days = 30): string {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return report_needs_sales_data('/маржа');
    }

    $since = date('Y-m-d', strtotime("-$days days"));

    // По артикулу, не по категории — точечные исключения (X2POS_ARTICLE_OVERRIDES)
    // должны увести конкретный товар в другую подкатегорию, не всю его категорию.
    $byArticle = $pdo->prepare(
        "SELECT s.article, p.category, SUM(s.margin) AS margin, SUM(s.total_amount) AS revenue
         FROM sales s LEFT JOIN products p ON p.article = s.article
         WHERE s.sale_date >= :since
         GROUP BY s.article"
    );
    $byArticle->execute([':since' => $since]);
    $articles = $byArticle->fetchAll(PDO::FETCH_ASSOC);

    $lines = ["💰 <b>Маржа за $days дней по отделам</b>"];
    if (empty($articles)) {
        $lines[] = "нет данных за этот период";
        return implode("\n", $lines);
    }

    // tree[dep][sub][categoryLabel] = ['margin'=>.., 'revenue'=>..]
    $kidsMap = compute_kids_map($pdo);
    $tree = [];
    foreach ($articles as $a) {
        $dep = category_department($a['category'], $a['article']);
        $sub = category_subcategory($a['category'], $a['article']);
        $catLabel = ($a['category'] ?: 'без категории') . (($kidsMap[$a['article']] ?? false) ? ' (дети)' : '');
        $tree[$dep][$sub][$catLabel]['margin'] = ($tree[$dep][$sub][$catLabel]['margin'] ?? 0) + $a['margin'];
        $tree[$dep][$sub][$catLabel]['revenue'] = ($tree[$dep][$sub][$catLabel]['revenue'] ?? 0) + $a['revenue'];
    }
    $departments = array_keys($tree);
    usort($departments, fn($a, $b) => array_search($a, CATEGORY_DEPARTMENT_ORDER) <=> array_search($b, CATEGORY_DEPARTMENT_ORDER));

    foreach ($departments as $dep) {
        $depMargin = 0;
        $depRevenue = 0;
        foreach ($tree[$dep] as $cats) {
            foreach ($cats as $c) {
                $depMargin += $c['margin'];
                $depRevenue += $c['revenue'];
            }
        }
        $depPct = $depRevenue > 0 ? $depMargin / $depRevenue * 100 : 0;
        $lines[] = "";
        $lines[] = sprintf("🔹 <b>%s</b> — <code>%s</code> (%.0f%%)", $dep, fmt_money($depMargin), $depPct);

        $subcats = $tree[$dep];
        uasort($subcats, fn($a, $b) => array_sum(array_column($b, 'margin')) <=> array_sum(array_column($a, 'margin')));

        foreach ($subcats as $sub => $cats) {
            $subMargin = array_sum(array_column($cats, 'margin'));
            $subRevenue = array_sum(array_column($cats, 'revenue'));
            $subPct = $subRevenue > 0 ? $subMargin / $subRevenue * 100 : 0;
            uasort($cats, fn($a, $b) => $b['margin'] <=> $a['margin']);
            $onlyCat = strip_kids_suffix(array_key_first($cats));
            if (count($cats) === 1 && $sub === category_subcategory($onlyCat === 'без категории' ? null : $onlyCat)) {
                $lines[] = sprintf("▪️ %s — <code>%s</code> (%.0f%%)", $sub, fmt_money($subMargin), $subPct);
            } else {
                $lines[] = sprintf("▪️ %s — <code>%s</code> (%.0f%%)", $sub, fmt_money($subMargin), $subPct);
                foreach ($cats as $name => $c) {
                    $lines[] = sprintf("     • %s — <code>%s</code>", $name, fmt_money($c['margin']));
                }
            }
        }
    }

    return implode("\n", $lines);
}

// market basket: пары товаров, чаще всего встречающиеся в одном чеке
// (раздел 6 ТЗ). Требует x2pos_doc_id — есть только у "История продаж по товарам".
function report_basket(PDO $pdo, int $page = 1): array {
    if ((int) $pdo->query("SELECT COUNT(*) FROM sales WHERE x2pos_doc_id IS NOT NULL")->fetchColumn() === 0) {
        return ['text' => report_needs_sales_data('/связки'), 'page' => 1, 'total_pages' => 1];
    }

    $rows = $pdo->query(
        "SELECT x2pos_doc_id, article FROM sales WHERE x2pos_doc_id IS NOT NULL AND qty > 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byDoc = [];
    foreach ($rows as $r) {
        $byDoc[$r['x2pos_doc_id']][$r['article']] = true;
    }

    $pairCounts = [];
    foreach ($byDoc as $articles) {
        $articles = array_keys($articles);
        sort($articles);
        $n = count($articles);
        if ($n < 2) {
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $key = $articles[$i] . ' + ' . $articles[$j];
                $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
            }
        }
    }
    arsort($pairCounts);
    $pairCounts = array_filter($pairCounts, fn($count) => $count >= 2);

    [$slice, $page, $totalPages] = paginate_slice(array_keys($pairCounts), $page, 15);

    $lines = ["🔗 <b>Часто покупают вместе</b>", ""];
    $offset = ($page - 1) * 15;
    foreach ($slice as $i => $pair) {
        $lines[] = sprintf("%d. %s — <code>%d раз</code>", $offset + $i + 1, $pair, $pairCounts[$pair]);
    }
    if (empty($pairCounts)) {
        $lines[] = "пока нет пар, встретившихся более одного раза";
    }
    $lines[] = fmt_page_footer($page, $totalPages);

    return ['text' => implode("\n", $lines), 'page' => $page, 'total_pages' => $totalPages];
}

function report_needs_sales_data(string $commandName): string {
    return "⚠️ Команда <b>$commandName</b> требует историю продаж, которой пока нет в базе.\n"
        . "Загрузите выгрузку X2POS «История продаж по товарам» — после этого команда будет работать.";
}

// Строится на sales_documents (чеки, привязанные к клиенту по телефону) —
// без построчного состава товаров, но для LTV/оттока этого достаточно.
// 65% чеков — анонимные "Розничный покупатель", их здесь нет и не будет.
// Топ по LTV — постраничный (может быть сотни клиентов); отток — всегда
// целиком под ним, он естественно небольшой (только те, кто просрочил свой
// личный интервал).
function report_customers(PDO $pdo, int $page = 1): array {
    $docsCount = (int) $pdo->query('SELECT COUNT(*) FROM sales_documents')->fetchColumn();
    if ($docsCount === 0) {
        return ['text' => report_needs_sales_data('/клиенты'), 'page' => 1, 'total_pages' => 1];
    }

    $top = $pdo->query(
        "SELECT name, phone, ltv, last_purchase_at FROM customers
         WHERE ltv IS NOT NULL AND ltv > 0
         ORDER BY ltv DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    [$slice, $page, $totalPages] = paginate_slice($top, $page, 10);

    // Отток: интервал с последней покупки заметно больше личного среднего
    // интервала клиента между покупками (раздел 4 ТЗ — не общий порог для всех).
    $churn = $pdo->query(
        "SELECT name, phone, last_purchase_at, avg_purchase_interval_days,
                CAST((julianday('now') - julianday(last_purchase_at)) AS INTEGER) AS days_since
         FROM customers
         WHERE avg_purchase_interval_days IS NOT NULL
           AND julianday('now') - julianday(last_purchase_at) > avg_purchase_interval_days * 1.5
         ORDER BY days_since DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $lines = ["👑 <b>Топ клиентов по LTV</b>", ""];
    $offset = ($page - 1) * 10;
    foreach ($slice as $i => $c) {
        $name = $c['name'] ?: $c['phone'];
        $lines[] = sprintf("%d. %s — <code>%s</code> (%s)", $offset + $i + 1, $name, fmt_money($c['ltv']), fmt_ru_date($c['last_purchase_at']));
    }

    $lines[] = "";
    $lines[] = "📉 <b>Похожи на отток</b>";
    $lines[] = "<i>не покупали дольше, чем обычно именно у них</i>";
    $lines[] = "";
    if (empty($churn)) {
        $lines[] = "нет данных или пока рано считать (мало покупок на клиента)";
    }
    $i = 0;
    foreach ($churn as $c) {
        $name = $c['name'] ?: $c['phone'];
        $lines[] = sprintf("%d. %s — <code>%d дн.</code> без покупок (обычно раз в %.0f дн.)", ++$i, $name, $c['days_since'], $c['avg_purchase_interval_days']);
    }
    $lines[] = fmt_page_footer($page, $totalPages);

    return ['text' => implode("\n", $lines), 'page' => $page, 'total_pages' => $totalPages];
}

// Считает всё то же, что раньше делал report_growth_opportunities целиком —
// вынесено отдельно, чтобы /дашборд мог показать те же находки компактно
// (счётчики + топ-1 по каждому пункту), не пересчитывая всё заново своей
// отдельной логикой, которая рано или поздно разошлась бы с /точки_роста.
function compute_growth_opportunities(PDO $pdo, int $days): array {
    $articles = compute_abc_xyz($pdo, $days);
    // Разовая оптовая партия не делает товар хитом (см. is_wholesale_spike в
    // compute_abc_xyz) — без этого фильтра товар, который не продавался
    // 3 месяца, а потом ушёл разом 12 штук одним чеком, попадал бы в "хиты
    // без остатка" наравне с реально стабильно продающимся товаром.
    $topArticles = array_filter($articles, fn($a) => in_array($a['abc'], ['A', 'B'], true) && !$a['is_wholesale_spike']);
    $wholesaleSpikes = array_filter($articles, fn($a) => in_array($a['abc'], ['A', 'B'], true) && $a['is_wholesale_spike']);
    $topByArticle = [];
    foreach ($topArticles as $a) {
        $topByArticle[$a['article']] = $a;
    }

    $stockByArticle = [];
    foreach ($pdo->query('SELECT article, SUM(qty_on_hand) AS qty FROM stock GROUP BY article') as $r) {
        $stockByArticle[$r['article']] = (int) $r['qty'];
    }

    // 1. Хиты, которых прямо сейчас нет в наличии — доказанный спрос, товара нет.
    $stockouts = [];
    foreach ($topByArticle as $article => $a) {
        if (($stockByArticle[$article] ?? 0) <= 0) {
            $stockouts[] = $a;
        }
    }
    usort($stockouts, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    // 2. Хиты, которые скоро закончатся (но пока есть).
    $forecast = compute_stock_forecast($pdo, null, 8);
    $soonOut = [];
    foreach ($forecast as $f) {
        if (isset($topByArticle[$f['article']]) && $f['stock'] > 0 && $f['days_to_zero'] < 15) {
            $soonOut[] = $f;
        }
    }
    usort($soonOut, fn($a, $b) => $a['days_to_zero'] <=> $b['days_to_zero']);

    // 3. Дефицит конкретного размера у хита (остальные размеры есть, этого нет).
    $since = date('Y-m-d', strtotime("-$days days"));
    $sizeSales = $pdo->prepare(
        "SELECT article, size, SUM(qty) AS sold FROM sales
         WHERE sale_date >= :since AND size IS NOT NULL
         GROUP BY article, size"
    );
    $sizeSales->execute([':since' => $since]);
    $sizeStock = [];
    foreach ($pdo->query('SELECT article, size, qty_on_hand FROM stock') as $r) {
        $sizeStock[$r['article']][$r['size']] = (int) $r['qty_on_hand'];
    }
    $sizeDeficits = [];
    foreach ($sizeSales as $s) {
        $article = $s['article'];
        if (!isset($topByArticle[$article]) || ($stockByArticle[$article] ?? 0) <= 0) {
            continue; // уже в п.1 (весь артикул без остатка) — не дублируем
        }
        $currentQty = $sizeStock[$article][$s['size']] ?? 0;
        if ($currentQty <= 0 && $s['sold'] > 0) {
            $sizeDeficits[] = ['article' => $article, 'name' => $topByArticle[$article]['name'] ?: $article, 'size' => $s['size'], 'sold' => $s['sold']];
        }
    }
    usort($sizeDeficits, fn($a, $b) => $b['sold'] <=> $a['sold']);

    // 4. Дорогие клиенты в оттоке — уже посчитано в report_customers, переиспользуем логику.
    $churn = $pdo->query(
        "SELECT name, phone, ltv, last_purchase_at, avg_purchase_interval_days,
                CAST((julianday('now') - julianday(last_purchase_at)) AS INTEGER) AS days_since
         FROM customers
         WHERE avg_purchase_interval_days IS NOT NULL
           AND julianday('now') - julianday(last_purchase_at) > avg_purchase_interval_days * 1.5
           AND ltv > 0
         ORDER BY ltv DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    // 5. Мёртвый капитал: C-класс (5% выручки) с деньгами, замороженными на полке.
    $costByArticle = [];
    foreach ($pdo->query('SELECT article, cost_price FROM products WHERE cost_price IS NOT NULL') as $r) {
        $costByArticle[$r['article']] = (float) $r['cost_price'];
    }
    $frozen = [];
    foreach ($articles as $a) {
        if ($a['abc'] !== 'C') {
            continue;
        }
        $qty = $stockByArticle[$a['article']] ?? 0;
        $cost = $costByArticle[$a['article']] ?? 0;
        if ($qty > 0 && $cost > 0) {
            $frozen[] = ['name' => $a['name'] ?: $a['article'], 'article' => $a['article'], 'qty' => $qty, 'value' => $qty * $cost];
        }
    }
    usort($frozen, fn($a, $b) => $b['value'] <=> $a['value']);

    // 6. Категории с маржой заметно ниже среднего по каталогу — возможно, скидки съедают прибыль.
    $catMargin = $pdo->prepare(
        "SELECT p.category, SUM(s.margin) AS margin, SUM(s.total_amount) AS revenue
         FROM sales s LEFT JOIN products p ON p.article = s.article
         WHERE s.sale_date >= :since
         GROUP BY p.category"
    );
    $catMargin->execute([':since' => $since]);
    $catRows = $catMargin->fetchAll(PDO::FETCH_ASSOC);
    $totalMargin = array_sum(array_column($catRows, 'margin'));
    $totalRevenue = array_sum(array_column($catRows, 'revenue'));
    $avgPct = $totalRevenue > 0 ? $totalMargin / $totalRevenue * 100 : 0;

    $weak = [];
    foreach ($catRows as $c) {
        if ($c['revenue'] < 50000) {
            continue; // слишком мало продаж, чтобы % значил что-то
        }
        $pct = $c['revenue'] > 0 ? $c['margin'] / $c['revenue'] * 100 : 0;
        if ($pct < $avgPct - 15) {
            $weak[] = ['category' => $c['category'] ?: 'без категории', 'pct' => $pct, 'revenue' => $c['revenue']];
        }
    }
    usort($weak, fn($a, $b) => $a['pct'] <=> $b['pct']);

    return [
        'stockouts' => $stockouts,
        'soon_out' => $soonOut,
        'size_deficits' => $sizeDeficits,
        'churn' => $churn,
        'frozen' => $frozen,
        'weak_margin' => $weak,
        'avg_margin_pct' => $avgPct,
        'wholesale_spikes' => array_values($wholesaleSpikes),
    ];
}

// Дашборд — отдельная кнопка в меню (не замена /start): самое важное на
// одном экране, без деталей — цифры + счётчики находок из
// compute_growth_opportunities, дальше кнопка "Подробнее" ведёт в
// /точки_роста за полным разбором.
function report_dashboard(PDO $pdo, int $days = 7): string {
    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $totalStock = (int) $pdo->query('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock')->fetchColumn();
    $customerCount = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $zeroStock = (int) $pdo->query(
        'SELECT COUNT(DISTINCT p.article) FROM products p
         LEFT JOIN stock s ON s.article = p.article
         GROUP BY p.article
         HAVING COALESCE(SUM(s.qty_on_hand), 0) = 0'
    )->fetchColumn();

    $salesCount = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();

    $lines = ["📊 <b>Дашборд</b>"];

    if ($salesCount === 0) {
        $lines[] = "";
        $lines[] = "📦 Товаров: <code>$productCount</code>, остаток: <code>$totalStock шт.</code>";
        $lines[] = "👥 Покупателей: <code>$customerCount</code>";
        $lines[] = "";
        $lines[] = "⚠️ Продажи не импортированы — выручка и находки по потерям недоступны. Загрузите выгрузку X2POS «Продажи по товарам».";
        return implode("\n", $lines);
    }

    $since = date('Y-m-d', strtotime("-$days days"));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS revenue FROM sales WHERE sale_date >= :since");
    $stmt->execute([':since' => $since]);
    $revenue = (float) $stmt->fetchColumn();

    $lines[] = "";
    $lines[] = "💰 Выручка за $days дней: <code>" . fmt_money($revenue) . "</code>";
    $lines[] = "📦 Остаток: <code>$totalStock шт.</code> ($zeroStock товаров с нулевым остатком)";
    $lines[] = "👥 Покупателей: <code>$customerCount</code>";

    // Находки по потерям считаем на 30 днях (как в /точки_роста), а не на
    // $days от выручки — при коротком окне почти нечему попасть в A/B-класс
    // хитов при небольшом объёме продаж, и блок "Требует внимания" был бы
    // почти всегда пустым без реальной причины.
    $g = compute_growth_opportunities($pdo, 30);

    $lines[] = "";
    $lines[] = "🚨 <b>Требует внимания</b>";
    $alertCount = 0;
    if (!empty($g['stockouts'])) {
        $top = $g['stockouts'][0];
        $lines[] = sprintf("🚫 %d хитов без остатка — топ: %s", count($g['stockouts']), $top['name'] ?: $top['article']);
        $alertCount++;
    }
    if (!empty($g['soon_out'])) {
        $lines[] = sprintf("⏳ %d скоро закончатся (&lt;15 дн.)", count($g['soon_out']));
        $alertCount++;
    }
    if (!empty($g['size_deficits'])) {
        $lines[] = sprintf("📏 %d дефицитов размера у хитов", count($g['size_deficits']));
        $alertCount++;
    }
    if (!empty($g['churn'])) {
        $lines[] = sprintf("👤 %d дорогих клиентов в оттоке", count($g['churn']));
        $alertCount++;
    }
    if (!empty($g['frozen'])) {
        $sumFrozen = array_sum(array_column($g['frozen'], 'value'));
        $lines[] = sprintf("🧊 ~%s заморожено в аутсайдерах", fmt_money($sumFrozen));
        $alertCount++;
    }
    if (!empty($g['weak_margin'])) {
        $lines[] = sprintf("📉 %d категорий с маржой ниже среднего", count($g['weak_margin']));
        $alertCount++;
    }
    if ($alertCount === 0) {
        $lines[] = "нет — по текущим данным явных проблем не видно";
    }

    $lines[] = "";
    $lines[] = "<i>Подробности и рекомендации — кнопка «Где теряю продажи» ниже.</i>";

    return implode("\n", $lines);
}

// Сводный диагностический отчёт "где теряю продажи" — полная версия с
// разбором и рекомендациями по каждому пункту. Демо-сигналы от продавцов
// (demand_signals) и цены конкурентов (competitor_prices) сюда не входят —
// эти таблицы пока пустые (боты для них не подключены), добавить туда же,
// когда появятся данные.
function report_growth_opportunities(PDO $pdo, int $days = 30): string {
    if ((int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0) {
        return report_needs_sales_data('/точки_роста');
    }

    $g = compute_growth_opportunities($pdo, $days);
    ['stockouts' => $stockouts, 'soon_out' => $soonOut, 'size_deficits' => $sizeDeficits,
     'churn' => $churn, 'frozen' => $frozen, 'weak_margin' => $weak,
     'avg_margin_pct' => $avgPct, 'wholesale_spikes' => $wholesaleSpikes] = $g;

    $lines = ["💡 <b>Где вы теряете продажи</b>", "<i>за $days дней, по хитам (A/B-класс, 95% выручки)</i>"];

    $lines[] = "";
    $lines[] = "🚫 <b>1. Хиты без остатка прямо сейчас</b>";
    if (empty($stockouts)) {
        $lines[] = "нет — все ваши топ-продавцы в наличии";
    } else {
        foreach (array_slice($stockouts, 0, 5) as $a) {
            $name = $a['name'] ?: $a['article'];
            $lines[] = sprintf("• %s (%s) — продано %d шт. на <code>%s</code>, сейчас 0 шт.", $name, $a['article'], $a['qty'], fmt_money($a['revenue']));
        }
    }

    $lines[] = "";
    $lines[] = "⏳ <b>2. Хиты, которые скоро закончатся</b> (&lt;15 дней)";
    if (empty($soonOut)) {
        $lines[] = "нет — по текущей скорости запаса хватит";
    } else {
        foreach (array_slice($soonOut, 0, 5) as $f) {
            $lines[] = sprintf("• %s (%s) — <code>%d шт.</code>, ~%.0f дн. до нуля", $f['name'], $f['article'], $f['stock'], $f['days_to_zero']);
        }
    }

    $lines[] = "";
    $lines[] = "📏 <b>3. Дефицит размера у хита</b> (остальные размеры есть)";
    if (empty($sizeDeficits)) {
        $lines[] = "нет — размерный ряд хитов не перекошен";
    } else {
        foreach (array_slice($sizeDeficits, 0, 5) as $d) {
            $lines[] = sprintf("• %s (%s), размер %s — продано %d шт. за период, сейчас 0", $d['name'], $d['article'], $d['size'], $d['sold']);
        }
    }

    $lines[] = "";
    $lines[] = "👤 <b>4. Дорогие клиенты в оттоке</b>";
    if (empty($churn)) {
        $lines[] = "нет — клиенты с высоким LTV покупают в своём обычном ритме";
    } else {
        foreach ($churn as $c) {
            $name = $c['name'] ?: $c['phone'];
            $lines[] = sprintf("• %s — LTV <code>%s</code>, %d дн. без покупок (обычно раз в %.0f)", $name, fmt_money($c['ltv']), $c['days_since'], $c['avg_purchase_interval_days']);
        }
    }

    $lines[] = "";
    $lines[] = "🧊 <b>5. Мёртвый капитал</b> (аутсайдеры, лежат на полке)";
    if (empty($frozen)) {
        $lines[] = "нет — в аутсайдерах не заморожено значимых денег";
    } else {
        foreach (array_slice($frozen, 0, 5) as $f) {
            $lines[] = sprintf("• %s (%s) — %d шт. заморожено на <code>%s</code> (по себестоимости)", $f['name'], $f['article'], $f['qty'], fmt_money($f['value']));
        }
    }

    $lines[] = "";
    $lines[] = sprintf("📉 <b>6. Маржа заметно ниже среднего</b> (средняя по каталогу %.0f%%)", $avgPct);
    if (empty($weak)) {
        $lines[] = "нет — маржа по категориям ровная";
    } else {
        foreach (array_slice($weak, 0, 5) as $w) {
            $lines[] = sprintf("• %s — %.0f%% при выручке <code>%s</code>", $w['category'], $w['pct'], fmt_money($w['revenue']));
        }
    }

    if (!empty($wholesaleSpikes)) {
        $names = implode(', ', array_map(fn($a) => $a['name'] ?: $a['article'], array_slice($wholesaleSpikes, 0, 5)));
        $lines[] = "";
        $lines[] = sprintf("ℹ️ <i>%d товар(ов) исключены из хитов как разовая оптовая партия одним чеком, не постоянный спрос: %s.</i>", count($wholesaleSpikes), $names);
    }

    $lines[] = "";
    $lines[] = "⚠️ <i>Сигналы от продавцов и цены конкурентов сюда не входят — эти данные ещё не подключены.</i>";

    // Рекомендации — по факту того, что реально нашлось, не общие советы.
    $lines[] = "";
    $lines[] = "✅ <b>Что делать прямо сейчас</b>";
    $recNum = 0;
    if (!empty($stockouts)) {
        $top = $stockouts[0];
        $lines[] = sprintf("%d. Довезти <b>%s</b> (%s) — это ваш хит, но он раскуплен подчистую. Каждый день без него — упущенная выручка.", ++$recNum, $top['name'] ?: $top['article'], $top['article']);
    }
    if (!empty($soonOut)) {
        $top = $soonOut[0];
        $lines[] = sprintf("%d. Заказать пополнение <b>%s</b> (%s) заранее — на исходе меньше %d дней, а поставка обычно не мгновенная.", ++$recNum, $top['name'], $top['article'], (int) $top['days_to_zero']);
    }
    if (!empty($sizeDeficits)) {
        $top = $sizeDeficits[0];
        $lines[] = sprintf("%d. Довезти размер %s по <b>%s</b> (%s) — товар в наличии, но именно ходовой размер кончился.", ++$recNum, $top['size'], $top['name'], $top['article']);
    }
    if (!empty($churn)) {
        $top = $churn[0];
        $name = $top['name'] ?: $top['phone'];
        $lines[] = sprintf("%d. Связаться с <b>%s</b> лично (LTV %s) — предложить скидку/новинку, пока не ушёл к конкуренту.", ++$recNum, $name, fmt_money($top['ltv']));
    }
    if (!empty($frozen)) {
        $sumFrozen = array_sum(array_column($frozen, 'value'));
        $lines[] = sprintf("%d. Уценить топ-%d аутсайдеров (в них заморожено ~%s) — освободит деньги на довоз хитов из пунктов 1-3.", ++$recNum, min(5, count($frozen)), fmt_money($sumFrozen));
    }
    if (!empty($weak)) {
        $top = $weak[0];
        $lines[] = sprintf("%d. Проверить скидки/закупочную цену в категории «%s» — маржа там на %.0f п.п. ниже среднего.", ++$recNum, $top['category'], $avgPct - $top['pct']);
    }
    if ($recNum === 0) {
        $lines[] = "Явных проблем не нашлось — держите текущий темп.";
    }

    return implode("\n", $lines);
}
