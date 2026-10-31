<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogClient.php';
require_once __DIR__ . '/photo-helpers.php';
require_once __DIR__ . '/asset-helper.php';

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
<?php include __DIR__ . '/partials/tracking.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($active['name'] ?? 'Товар') ?> — Joma Teamwear</title>
<?php if ($active !== null): ?>
<meta name="description" content="<?= h((string) $active['name']) ?> — <?= h($categoryName) ?> Joma, цена <?= fmtTenge($active['price_opt'] !== null ? (float) $active['price_opt'] : null) ?><?= !empty($active['composition']) ? ', ' . h((string) $active['composition']) : '' ?>. Наличие по размерам, заказ для команды.">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<link rel="icon" href="/logo/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/logo/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/logo/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/logo/favicon-180.png">
<style>
  /* Fixed padding, not vw — vw padding inside a max-width-capped block
     balloons on wide desktops (5vw = 80px/side at 1600px) and was eating
     enough of the 760px cap that the two-column row no longer fit,
     silently wrapping photo/info into one cramped narrow column with a
     huge unused gutter beside it. margin:auto centers the capped block
     instead of leaving it pinned to the left edge. */
  .product-wrap{padding:16px 20px 48px;max-width:760px;margin:0 auto}
  /* align-items:flex-start, not the flex default (stretch) — otherwise the
     photo box stretches to match the info column's height, which grew a
     lot once sizes became one row each instead of a compact grid, and
     the aspect-ratio-sized photo ends up tiny and off-center inside a
     much taller box (found via screenshot: the photo box was 1263px tall
     instead of ~500px). */
  .product-main{display:flex;align-items:flex-start;gap:28px;flex-wrap:wrap;margin-bottom:28px}
  /* Soft lavender tile (--tile), generously rounded — same card language as
     the catalog/category grids, not a plain white box. */
  .product-main-photo{
    flex:1 1 320px;max-width:420px;aspect-ratio:3/4;border-radius:28px;overflow:hidden;
    background:var(--tile);position:relative;
  }
  .product-main-photo img{width:100%;height:100%;object-fit:contain;display:block;padding:28px;box-sizing:border-box}
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
    width:52px;height:52px;border-radius:16px;overflow:hidden;border:2px solid transparent;
    background:var(--tile);cursor:pointer;flex:none;transition:box-shadow .2s;
  }
  .color-swatch img{width:100%;height:100%;object-fit:contain;padding:3px;box-sizing:border-box}
  .color-swatch.is-active{box-shadow:0 0 0 2px var(--panel),0 0 0 4px var(--accent)}

  /* Full width of .product-wrap, not squeezed into the narrow info column
     next to the photo — "расположи под карточкой в 2-3 ряда, вытянутой в
     ширину, можно уменьшить ширину каждого размера" (moved out of
     .product-info below). Smaller min column width -> more columns ->
     fewer rows for the same size count. */
  .size-section{margin-bottom:20px}
  .size-table{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;margin-top:4px}
  /* Each size is its own cell with explicit +/- buttons (not tap-to-add on
     the whole cell — "менять количество тапом не лучшая идея"). Out-of-stock
     cells used to just get opacity:.35 on the whole cell, which dimmed the
     already-muted "нет" text below readability on the dark theme — now only
     the background is muted, text colors stay at full opacity so "нет"
     stays legible ("не видно надписей"). */
  /* Stacked (name+stock above, stepper below), not side-by-side — a
     side-by-side row needs ~150px+ to fit both comfortably; stacking lets
     cells go as narrow as ~90px, which is the point of the smaller grid
     columns above ("можно уменьшить ширину каждого размера"). */
  .size-row{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    border:1px solid var(--line);border-radius:14px;padding:8px 4px;
    background:var(--tile);transition:box-shadow .15s,background .15s;
  }
  .size-row-info{display:flex;flex-direction:column;align-items:center;gap:1px;min-width:0}
  .size-row .size-name{font:700 13px var(--display)}
  .size-row .size-stock{font:10px var(--mono);white-space:nowrap}
  .size-row.ok .size-stock{color:var(--stock-ok)}
  .size-row.low .size-stock{color:var(--stock-low)}
  .size-row.out{background:var(--paper)}
  .size-row.out .size-stock{color:var(--stock-out)}
  .size-row.is-selected{background:#fff;box-shadow:0 0 0 2px var(--accent)}
  .size-row.is-selected .size-stock{color:var(--accent);font-weight:700}
  .size-row.at-limit{box-shadow:0 0 0 2px var(--stock-out);animation:shake .35s}
  @keyframes shake{25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}

  /* Pill-shaped stepper, not a square box — matches the rounded, pill-CTA
     language used everywhere else now (add-to-cart, cart-submit). */
  /* touch-action:manipulation на степпере и его кнопках — без него быстрые
     повторные тапы по маленькой кнопке браузер мобильного Safari/Chrome
     иногда распознаёт как жест двойного тапа (zoom), а не как два разных
     клика. user-select:none — чтобы быстрые тапы не выделяли текст "+"/"−". */
  .qty-stepper{
    display:flex;align-items:center;background:var(--panel);border-radius:999px;overflow:hidden;flex:none;
    box-shadow:inset 0 0 0 1px var(--line-strong);touch-action:manipulation;
  }
  .qty-stepper button{
    width:32px;height:32px;border:none;background:none;color:var(--ink);
    font:16px var(--display);line-height:1;cursor:pointer;display:grid;place-items:center;
    touch-action:manipulation;user-select:none;-webkit-tap-highlight-color:transparent;
  }
  .qty-stepper button:hover:not(:disabled){color:var(--accent)}
  .qty-stepper button:disabled{opacity:.3;cursor:not-allowed}
  .qty-stepper .qty-value{min-width:20px;text-align:center;font:700 12.5px var(--mono);font-variant-numeric:tabular-nums}

  .sales-copy{margin:8px 0 24px;padding:20px;background:var(--panel);border-radius:20px;box-shadow:0 1px 0 var(--line)}
  .sales-copy h2{font-family:var(--serif);font-weight:400;font-size:22px;margin:0 0 10px}
  .sales-copy h3{font:700 12px var(--mono);text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:18px 0 8px}
  .sales-lead{font-size:15px;line-height:1.55;margin:0}
  .sales-copy ul{margin:0;padding-left:18px;font-size:14px;line-height:1.55}
  .sales-copy li+li{margin-top:6px}
  .sales-composition{font-size:13px;color:var(--muted);margin:16px 0 0}
  .add-to-cart{
    margin-top:4px;padding:14px 20px;border-radius:999px;border:none;background:var(--accent);color:#fff;
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
            <img src="<?= h((string) photoProxyUrl($active['photo_path'])) ?>" alt="" id="mainPhoto"
                 onerror="this.closest('.product-main-photo').classList.add('photo-missing');this.remove()">
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
                  <img src="<?= h((string) photoProxyUrl($c['photo_path'])) ?>" alt="" loading="lazy" onerror="this.closest('.color-swatch').style.background='var(--panel)';this.remove()">
                </a>
<?php endforeach; ?>
              </div>
            </div>
<?php endif; ?>
          </div>
        </div>

        <!-- Под карточкой (фото+инфо), на всю ширину — больше места для
             колонок размеров (2-3 строки вместо одной длинной), чем в узкой
             правой колонке ("расположи под карточкой в 2-3 ряда"). -->
        <div class="color-row size-section">
          <span class="label">Размер и количество</span>
          <div class="size-table" id="sizeTable">
<?php foreach ($sizes as $s): $qty = (int) $s['qty'];
            $cls = $qty === 0 ? 'out' : ($qty < 20 ? 'low' : 'ok'); ?>
            <div class="size-row <?= $cls ?>" data-size="<?= h((string) $s['size']) ?>" data-stock="<?= $qty ?>">
              <div class="size-row-info">
                <span class="size-name"><?= h((string) $s['size']) ?></span>
                <span class="size-stock"><?= $qty === 0 ? 'нет' : $qty . ' шт.' ?></span>
              </div>
              <div class="qty-stepper">
                <button type="button" class="qty-dec" aria-label="Уменьшить количество" disabled>−</button>
                <span class="qty-value">0</span>
                <button type="button" class="qty-inc" aria-label="Увеличить количество" <?= $qty === 0 ? 'disabled' : '' ?>>+</button>
              </div>
            </div>
<?php endforeach; ?>
          </div>
          <span class="size-hint">Кнопками +/− наберите количество по нужным размерам</span>
        </div>

<?php
$salesCopy = [
    '101579' => [
        'title' => 'Куртка JOMA Doha',
        'lead' => 'Лёгкая куртка на молнии для разминки и дороги на тренировку. Ткань из микроволокна весит всего 175 г, поэтому не сковывает движения: надел до игры, снял, когда разогрелся.',
        'why' => [
            '<b>95% полиэстер и 5% эластан.</b> Эластан даёт растяжение, куртка не мешает при беге, прыжках и растяжке.',
            '<b>Эластичные манжеты и резинка по талии.</b> Куртка не задирается при движении и плотно садится на запястьях.',
            '<b>Микроволокно 175 г.</b> Лёгкая ткань, удобно носить поверх формы.',
        ],
        'details' => [
            'Открытая молния по всей длине',
            'Два передних кармана на молнии: ключи и телефон не выпадут на разминке',
            'Без внутренней подкладки',
            'Вышитый логотип J',
        ],
        'composition' => '95% полиэстер, 5% эластан. Плотность ткани 175 г.',
    ],
];
$copy = $salesCopy[$modelNumber] ?? null;
?>
<?php if ($copy !== null): ?>
        <section class="sales-copy">
          <h2><?= h($copy['title']) ?></h2>
          <p class="sales-lead"><?= h($copy['lead']) ?></p>
          <h3>Почему этот материал подходит для спорта</h3>
          <ul class="sales-why">
<?php foreach ($copy['why'] as $line): ?>
            <li><?= $line ?></li>
<?php endforeach; ?>
          </ul>
          <h3>Детали</h3>
          <ul class="sales-details">
<?php foreach ($copy['details'] as $line): ?>
            <li><?= h($line) ?></li>
<?php endforeach; ?>
          </ul>
          <p class="sales-composition"><b>Состав:</b> <?= h($copy['composition']) ?></p>
        </section>
<?php endif; ?>

        <button class="add-to-cart" id="addToCartBtn" disabled
                data-article="<?= h($active['article']) ?>"
                data-model="<?= h($modelNumber) ?>"
                data-name="<?= h((string) $active['name']) ?>"
                data-category="<?= h($categoryName) ?>"
                data-color="<?= h((string) $active['color']) ?>"
                data-photo="<?= h((string) photoProxyUrl($active['photo_path'])) ?>"
                data-price="<?= h((string) ($active['price_opt'] ?? 0)) ?>">
          Добавить в заявку
        </button>
      </div>
<?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="<?= asset('app.js') ?>"></script>
<?php if ($active !== null): ?>
<script>
  if (typeof trackEvent === 'function') {
    trackEvent('ViewContent', {
      content_name: <?= json_encode((string) $active['name']) ?>,
      content_category: <?= json_encode($categoryName) ?>,
      value: <?= json_encode($active['price_opt'] !== null ? (float) $active['price_opt'] : null) ?>,
    });
  }
</script>
<script>
  // Per-size qty selector — explicit +/- buttons per size (tap-to-increment
  // on the whole cell was confusing: "менять количество тапом не лучшая
  // идея"). Enables the add-to-cart button once at least one size has
  // qty > 0.
  (() => {
    const qtyBySize = {};
    const addBtn = document.getElementById('addToCartBtn');
    const article = addBtn.dataset.article;

    document.querySelectorAll('#sizeTable .size-row').forEach((row) => {
      const size = row.dataset.size;
      const stock = Number(row.dataset.stock) || 0;
      const decBtn = row.querySelector('.qty-dec');
      const incBtn = row.querySelector('.qty-inc');
      const valueEl = row.querySelector('.qty-value');
      if (stock === 0) return;

      const alreadyInCart = (typeof JomaCart !== 'undefined' && JomaCart.getLineQty)
        ? JomaCart.getLineQty(article, size)
        : 0;
      const available = Math.max(0, stock - alreadyInCart);

      function render() {
        const qty = qtyBySize[size] || 0;
        valueEl.textContent = String(qty);
        decBtn.disabled = qty <= 0;
        incBtn.disabled = qty >= available;
        row.classList.toggle('is-selected', qty > 0);
        addBtn.disabled = !Object.values(qtyBySize).some((q) => q > 0);
      }

      incBtn.addEventListener('click', () => {
        const pending = qtyBySize[size] || 0;
        if (pending >= available) {
          row.classList.add('at-limit');
          setTimeout(() => row.classList.remove('at-limit'), 400);
          return;
        }
        qtyBySize[size] = pending + 1;
        render();
      });
      decBtn.addEventListener('click', () => {
        qtyBySize[size] = Math.max(0, (qtyBySize[size] || 0) - 1);
        render();
      });

      render();
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
      document.querySelectorAll('#sizeTable .size-row').forEach((row) => {
        const size = row.dataset.size;
        qtyBySize[size] = 0;
        row.classList.remove('is-selected');
        const valueEl = row.querySelector('.qty-value');
        const decBtn = row.querySelector('.qty-dec');
        const incBtn = row.querySelector('.qty-inc');
        if (valueEl) valueEl.textContent = '0';
        if (decBtn) decBtn.disabled = true;
        if (incBtn && Number(row.dataset.stock) > 0) incBtn.disabled = false;
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
