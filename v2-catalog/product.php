<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogClient.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);

$modelNumber = $_GET['model'] ?? '';
$categoryName = $_GET['category'] ?? '';
$subParam = $_GET['sub'] ?? null;
$genderParam = $_GET['gender'] ?? null;
$colorParam = $_GET['color'] ?? null;

$colors = $modelNumber !== '' ? $client->getItemsByModel($modelNumber) : [];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function pluralRu(int $n, string $one, string $few, string $many): string
{
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return $one;
    }
    if (in_array($mod10, [2, 3, 4], true) && !in_array($mod100, [12, 13, 14], true)) {
        return $few;
    }
    return $many;
}

function fmtTenge(?float $n): string
{
    if ($n === null) {
        return '—';
    }
    return number_format($n, 0, '', ' ') . ' ₸';
}

// Размеры в выгрузке идут вперемешку (детские "12 (2XS)" и взрослые "XL" в
// произвольном порядке) — сортируем по реальной размерной сетке, а не по
// алфавиту. Неизвестные значения (обувные "42.5", "ONE SIZE" и т.п.) просто
// уходят в конец в исходном порядке.
const SIZE_ORDER = [
    '4 (6XS)', '6 (5XS)', '8 (4XS)', '10 (3XS)', '12 (2XS)', '14 (XS)',
    'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL',
];
function sizeSortKey(string $size): int
{
    $i = array_search($size, SIZE_ORDER, true);
    return $i === false ? 999 : $i;
}

$active = null;
foreach ($colors as $c) {
    if ($colorParam !== null && $c['article'] === $colorParam) {
        $active = $c;
        break;
    }
}
if ($active === null) {
    $active = $colors[0] ?? null;
}
if ($active !== null) {
    $sizes = $active['sizes'] ?? [];
    usort($sizes, fn($a, $b) => sizeSortKey($a['size']) <=> sizeSortKey($b['size']));
}

// Назад — туда, откуда реально пришли: экран пола, подкатегории или просто
// категория, в зависимости от того, что было передано по цепочке ссылок.
$backHref = 'category.php?name=' . urlencode($categoryName);
if ($subParam !== null) {
    $backHref .= '&sub=' . urlencode($subParam);
}
if ($genderParam !== null) {
    $backHref .= '&gender=' . urlencode($genderParam);
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($active['name'] ?? 'Товар') ?> — Joma Teamwear</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
<style>
  /* Fixed padding, not vw — vw padding inside a max-width-capped block
     balloons on wide desktops (5vw = 80px/side at 1600px) and was eating
     enough of the 760px cap that the two-column row no longer fit,
     silently wrapping photo/info into one cramped narrow column with a
     huge unused gutter beside it. margin:auto centers the capped block
     instead of leaving it pinned to the left edge. */
  .product-wrap{padding:16px 20px 48px;max-width:760px;margin:0 auto}
  .product-main{display:flex;gap:28px;flex-wrap:wrap;margin-bottom:28px}
  /* Supplier photos are shot on a near-white backdrop baked into the
     pixels — a dark card background behind them read as a stray white
     rectangle floating in a black hole. Light card + soft shadow instead,
     so it looks like an intentional product card, not a mistake. */
  .product-main-photo{
    flex:1 1 320px;max-width:420px;aspect-ratio:3/4;border-radius:16px;overflow:hidden;
    background:var(--paper);position:relative;box-shadow:0 12px 32px rgba(0,0,0,.35);
  }
  .product-main-photo img{width:100%;height:100%;object-fit:contain;display:block;padding:20px;box-sizing:border-box}
  .product-main-photo.photo-missing::after{
    content:'Нет фото';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font:12px var(--mono);color:var(--muted);
  }
  .product-info{flex:1 1 260px;display:flex;flex-direction:column;gap:14px}
  .product-info h1{font-family:var(--serif);font-weight:400;font-size:clamp(22px,3vh,28px);margin:0;text-wrap:balance}
  .product-info .cat-chain{font:11px var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
  .product-info .price-block{display:flex;align-items:baseline;gap:10px}
  .product-info .price-opt{font:700 24px var(--display)}
  .product-info .price-retail{font:13px var(--mono);color:var(--muted);text-decoration:line-through}
  .product-info .composition{font-size:12.5px;color:var(--muted);line-height:1.5}

  .color-row{display:flex;flex-direction:column;gap:8px}
  .color-row .label{font:600 11px var(--mono);text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .color-swatches{display:flex;flex-wrap:wrap;gap:8px}
  .color-swatch{
    width:52px;height:52px;border-radius:10px;overflow:hidden;border:2px solid var(--line);
    background:var(--paper);cursor:pointer;flex:none;transition:border-color .2s;
  }
  .color-swatch img{width:100%;height:100%;object-fit:contain;padding:3px;box-sizing:border-box}
  .color-swatch.is-active{border-color:var(--accent)}

  .size-table{display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:8px;margin-top:4px}
  /* Each size is its own stepper, not just a stock readout — tap to add
     a unit, the count shown replaces the stock number while selected so
     it's clear what you're about to submit, not just what's in stock. */
  .size-cell{
    border:1px solid var(--line);border-radius:8px;padding:8px 6px;text-align:center;
    background:none;font-family:inherit;cursor:pointer;position:relative;transition:border-color .15s,background .15s;
  }
  .size-cell .size-name{font:600 12.5px var(--mono)}
  .size-cell .size-stock{font:10px var(--mono);color:var(--muted);margin-top:2px}
  .size-cell.out{opacity:.35;cursor:not-allowed}
  .size-cell.ok .size-stock{color:var(--stock-ok)}
  .size-cell.low .size-stock{color:var(--stock-low)}
  .size-cell.is-selected{border-color:var(--accent);background:rgba(43,70,255,.12)}
  .size-cell.is-selected .size-stock{color:var(--accent);font-weight:700}
  .size-cell .qty-badge{
    position:absolute;top:-7px;right:-7px;min-width:18px;height:18px;border-radius:999px;background:var(--accent);
    color:var(--paper);font:700 10px var(--mono);display:none;align-items:center;justify-content:center;padding:0 4px;
  }
  .size-cell.is-selected .qty-badge{display:flex}

  .add-to-cart{
    margin-top:6px;padding:14px 20px;border-radius:10px;border:none;background:var(--accent);color:var(--paper);
    font:700 13px var(--display);text-transform:uppercase;letter-spacing:.04em;cursor:pointer;
  }
  .add-to-cart:hover{opacity:.9}
  .add-to-cart:disabled{opacity:.4;cursor:not-allowed}
  .size-hint{font:11px var(--mono);color:var(--muted)}
  .empty-state{padding:6vh 5vw;color:var(--muted);text-align:center}
</style>
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <div class="page" data-page="product">
    <div class="cat-showcase" style="overflow-y:auto">
      <div class="page-head" style="padding-bottom:0">
        <div class="page-head-top">
          <a class="back-page" href="<?= h($backHref) ?>" aria-label="Назад"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
        </div>
      </div>
<?php if ($active === null): ?>
      <div class="empty-state">Товар не найден.</div>
<?php else: ?>
      <div class="product-wrap">
        <div class="product-main">
          <div class="product-main-photo" id="mainPhotoWrap">
            <img src="<?= h((string) $active['photo_path']) ?>" alt="" id="mainPhoto" onerror="this.closest('.product-main-photo').classList.add('photo-missing');this.remove()">
          </div>
          <div class="product-info">
            <p class="cat-chain"><?= h($categoryName) ?><?= $subParam !== null ? ' · ' . h($subParam) : '' ?></p>
            <h1><?= h((string) $active['name']) ?></h1>
            <div class="price-block">
              <span class="price-opt"><?= fmtTenge($active['price_opt'] !== null ? (float) $active['price_opt'] : null) ?></span>
<?php if (!empty($active['price_retail'])): ?>
              <span class="price-retail"><?= fmtTenge((float) $active['price_retail']) ?></span>
<?php endif; ?>
            </div>
<?php if (!empty($active['composition'])): ?>
            <p class="composition"><?= h((string) $active['composition']) ?></p>
<?php endif; ?>

<?php if (count($colors) > 1): ?>
            <div class="color-row">
              <span class="label"><?= count($colors) ?> <?= pluralRu(count($colors), 'цвет', 'цвета', 'цветов') ?></span>
              <div class="color-swatches">
<?php foreach ($colors as $c): ?>
                <a class="color-swatch<?= $c['article'] === $active['article'] ? ' is-active' : '' ?>"
                   href="product.php?model=<?= urlencode($modelNumber) ?>&category=<?= urlencode($categoryName) ?><?= $subParam !== null ? '&sub=' . urlencode($subParam) : '' ?><?= $genderParam !== null ? '&gender=' . urlencode($genderParam) : '' ?>&color=<?= urlencode($c['article']) ?>"
                   title="<?= h((string) $c['color']) ?>">
                  <img src="<?= h((string) $c['photo_path']) ?>" alt="" loading="lazy" onerror="this.closest('.color-swatch').style.background='var(--panel)';this.remove()">
                </a>
<?php endforeach; ?>
              </div>
            </div>
<?php endif; ?>

            <div class="color-row">
              <span class="label">Размер и количество</span>
              <div class="size-table" id="sizeTable">
<?php foreach ($sizes as $s): $qty = (int) $s['qty'];
                $cls = $qty === 0 ? 'out' : ($qty < 20 ? 'low' : 'ok'); ?>
                <button type="button" class="size-cell <?= $cls ?>" <?= $qty === 0 ? 'disabled' : '' ?>
                        data-size="<?= h((string) $s['size']) ?>" data-stock="<?= $qty ?>">
                  <div class="size-name"><?= h((string) $s['size']) ?></div>
                  <div class="size-stock"><?= $qty === 0 ? 'нет' : $qty ?></div>
                  <span class="qty-badge">0</span>
                </button>
<?php endforeach; ?>
              </div>
              <span class="size-hint">Нажимайте на размер, чтобы добавить штуку — можно несколько размеров сразу</span>
            </div>

            <button class="add-to-cart" id="addToCartBtn" disabled
                    data-article="<?= h($active['article']) ?>"
                    data-model="<?= h($modelNumber) ?>"
                    data-name="<?= h((string) $active['name']) ?>"
                    data-category="<?= h($categoryName) ?>"
                    data-color="<?= h((string) $active['color']) ?>"
                    data-photo="<?= h((string) $active['photo_path']) ?>"
                    data-price="<?= h((string) ($active['price_opt'] ?? 0)) ?>">
              Добавить в заявку
            </button>
          </div>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</main>

<script src="app.js"></script>
<?php if ($active !== null): ?>
<script>
  // Per-size qty selector — tap a size to add one unit, tap again for
  // another. Enables the add-to-cart button once at least one size has
  // qty > 0 (an empty submission wouldn't mean anything).
  (() => {
    const qtyBySize = {};
    const addBtn = document.getElementById('addToCartBtn');
    document.querySelectorAll('#sizeTable .size-cell').forEach((cell) => {
      if (cell.disabled) return;
      cell.addEventListener('click', () => {
        const size = cell.dataset.size;
        qtyBySize[size] = (qtyBySize[size] || 0) + 1;
        cell.classList.add('is-selected');
        cell.querySelector('.qty-badge').textContent = String(qtyBySize[size]);
        addBtn.disabled = false;
      });
      // Right-click / long-press alternative isn't worth the complexity
      // here — a misclick is cheap to fix by just not adding that line.
    });

    addBtn.addEventListener('click', () => {
      const sizes = Object.keys(qtyBySize).filter((s) => qtyBySize[s] > 0);
      if (sizes.length === 0) return;
      for (const size of sizes) {
        JomaCart.addLine({
          article: addBtn.dataset.article,
          modelNumber: addBtn.dataset.model,
          name: addBtn.dataset.name,
          category: addBtn.dataset.category,
          color: addBtn.dataset.color,
          photo: addBtn.dataset.photo,
          unitPrice: Number(addBtn.dataset.price) || 0,
          size,
          qty: qtyBySize[size],
        });
      }
      // Reset the picker so a second click doesn't silently double-add,
      // and confirm by opening the cart panel.
      for (const size of sizes) {
        qtyBySize[size] = 0;
      }
      document.querySelectorAll('#sizeTable .size-cell.is-selected').forEach((cell) => {
        cell.classList.remove('is-selected');
        cell.querySelector('.qty-badge').textContent = '0';
      });
      addBtn.disabled = true;
      document.getElementById('cartOverlay')?.classList.add('is-open');
      document.getElementById('cartPanel')?.classList.add('is-open');
    });
  })();
</script>
<?php endif; ?>
</body>
</html>
