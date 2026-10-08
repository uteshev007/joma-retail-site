<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogClient.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);

$categoryName = $_GET['name'] ?? '';
$items = $categoryName !== '' ? $client->getItemsByCategory($categoryName) : [];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmtTenge(?float $n): string
{
    if ($n === null) {
        return '—';
    }
    return number_format($n, 0, '', ' ') . ' ₸';
}

/** Сумма остатков по всем размерам — для точки наличия на карточке. */
function totalStock(array $item): int
{
    $total = 0;
    foreach ($item['sizes'] ?? [] as $size) {
        $total += (int) ($size['qty'] ?? 0);
    }
    return $total;
}

// Группируем по model_number — одна карточка на модель, а не на каждый цвет
// отдельно (иначе "Футболки" превращаются в 729 почти одинаковых плиток).
$byModel = [];
foreach ($items as $item) {
    $key = $item['model_number'] ?: $item['article'];
    if (!isset($byModel[$key])) {
        $byModel[$key] = ['base' => $item, 'colorCount' => 0];
    }
    $byModel[$key]['colorCount']++;
}
$models = array_values($byModel);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($categoryName ?: 'Категория') ?> — Joma Teamwear</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
<style>
  /* Products page: real catalogs run from dozens to hundreds of items
     (52–729 in this data), so unlike the category index's single-screen
     ring layout, this is always a plain scrolling grid — on desktop too. */
  .products-grid{
    flex:1;overflow-y:auto;display:grid;
    grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px 10px;
    padding:1vh 5vw 4vh;
  }
  @media(min-width:900px){
    .products-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:20px}
  }
  .product-card{display:flex;flex-direction:column}
  .product-photo{position:relative;aspect-ratio:3/4;border-radius:10px;overflow:hidden;background:var(--panel);margin-bottom:8px}
  .product-photo img{width:100%;height:100%;object-fit:cover}
  .stock-dot{position:absolute;top:8px;right:8px;width:9px;height:9px;border-radius:50%;border:1.5px solid var(--ink)}
  .stock-dot.ok{background:var(--stock-ok)}
  .stock-dot.low{background:var(--stock-low)}
  .stock-dot.out{background:var(--stock-out)}
  .product-name{font-size:13px;font-weight:600;line-height:1.3;margin:0 0 2px}
  .product-meta{font:11px var(--mono);color:var(--muted);margin:0 0 4px}
  .product-price{font-size:14px;font-weight:700;margin:0}
  .empty-state{padding:6vh 5vw;color:var(--muted);text-align:center}
</style>
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <div class="page" data-page="products">
    <div class="cat-showcase">
      <div class="page-head">
        <div class="page-head-top">
          <a class="back-page" href="index.php" aria-label="Назад в каталог"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
          <h1><?= h($categoryName ?: 'Категория') ?></h1>
        </div>
        <p><?= count($models) ?> моделей · цена указана оптовая, за единицу.</p>
      </div>
<?php if (empty($models)): ?>
      <div class="empty-state">В этой категории пока нет товаров в выгрузке.</div>
<?php else: ?>
      <div class="products-grid">
<?php foreach ($models as $m): $item = $m['base']; $stock = totalStock($item);
        $stockClass = $stock === 0 ? 'out' : ($stock < 20 ? 'low' : 'ok'); ?>
        <div class="product-card">
          <div class="product-photo">
            <img src="<?= h((string) $item['photo_path']) ?>" alt="" loading="lazy">
            <span class="stock-dot <?= $stockClass ?>" title="<?= $stock ?> шт. на складе"></span>
          </div>
          <p class="product-name"><?= h((string) $item['name']) ?></p>
          <p class="product-meta"><?= $m['colorCount'] ?> <?= $m['colorCount'] === 1 ? 'цвет' : 'цвета' ?><?= $item['group'] ? ' · ' . h((string) $item['group']) : '' ?></p>
          <p class="product-price"><?= fmtTenge($item['price_opt'] !== null ? (float) $item['price_opt'] : null) ?></p>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </div>
</main>

<script src="app.js"></script>
</body>
</html>
