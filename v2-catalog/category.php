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

/**
 * CRM присылает group как "мужская"/"Мужская"/"женская"/null вперемешку
 * (регистр не нормализован на их стороне) — приводим к одному виду и
 * раскладываем по секциям, вместо плоской свалки мужского/женского вместе.
 */
function normalizeGroup(?string $group): string
{
    $g = mb_strtolower(trim((string) $group));
    if ($g === 'мужская') {
        return 'мужская';
    }
    if ($g === 'женская') {
        return 'женская';
    }
    return 'унисекс';
}

$genderOrder = ['мужская' => 'Мужская', 'женская' => 'Женская', 'унисекс' => 'Унисекс'];

/**
 * Группировка в два уровня: подкатегория (когда она реально есть в этой
 * категории — например "Футбол/Футзал" / "Баскетбол" внутри "Игровая
 * форма", или 6 видов внутри "Аксессуары"), а внутри неё — по полу.
 * Для категорий без подкатегорий (большинство — Поло, Футболки и т.д.)
 * верхний уровень просто один, без собственного заголовка.
 */
$bySub = [];
foreach ($models as $m) {
    $sub = $m['base']['subcategory'] ?: null;
    $bySub[$sub ?? '__none__']['title'] = $sub;
    $bySub[$sub ?? '__none__']['genders'][normalizeGroup($m['base']['group'] ?? null)][] = $m;
}
function subModelCount(array $group): int
{
    $n = 0;
    foreach ($group['genders'] as $models) {
        $n += count($models);
    }
    return $n;
}

// Подкатегории — в порядке убывания количества моделей, "без подкатегории"
// (не встречается вместе с реальными подкатегориями на практике, но на
// всякий случай) — последней.
uksort($bySub, function ($a, $b) use ($bySub) {
    if ($a === '__none__') return 1;
    if ($b === '__none__') return -1;
    return subModelCount($bySub[$b]) <=> subModelCount($bySub[$a]);
});

// Деление по полу добавляет свой заголовок на каждую подгруппу — если
// почти всё приходится на одну группу (например, 2 женских + 13 унисекс),
// секция из 2 карточек не помогает ориентироваться, а только добавляет
// "воздуха" и создаёт ощущение, что каталог внезапно разросся. Показываем
// пол отдельными секциями только когда минимум 2 группы набирают приличное
// количество моделей каждая — иначе один плоский список.
const MIN_MODELS_PER_GENDER_BUCKET = 3;
foreach ($bySub as $subKey => &$subGroup) {
    $meaningfulBuckets = count(array_filter(
        $subGroup['genders'],
        fn($g) => count($g) >= MIN_MODELS_PER_GENDER_BUCKET
    ));
    if ($meaningfulBuckets < 2) {
        $flat = [];
        foreach ($subGroup['genders'] as $genderModels) {
            $flat = array_merge($flat, $genderModels);
        }
        $subGroup['genders'] = ['__flat__' => $flat];
    }
}
unset($subGroup);
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
  /* Supplier's own image endpoint occasionally returns a corrupt JPEG for a
     given product code (confirmed directly — valid-looking headers, broken
     pixel data) — the img's onerror strips the broken <img>, leaving this
     quiet placeholder instead of the browser's default broken-image icon. */
  .product-photo.photo-missing::after{
    content:'Нет фото';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font:11px var(--mono);color:var(--muted);text-align:center;
  }
  .stock-dot{position:absolute;top:8px;right:8px;width:9px;height:9px;border-radius:50%;border:1.5px solid var(--ink)}
  .stock-dot.ok{background:var(--stock-ok)}
  .stock-dot.low{background:var(--stock-low)}
  .stock-dot.out{background:var(--stock-out)}
  .product-name{font-size:13px;font-weight:600;line-height:1.3;margin:0 0 2px}
  .product-meta{font:11px var(--mono);color:var(--muted);margin:0 0 4px}
  .product-price{font-size:14px;font-weight:700;margin:0}
  .empty-state{padding:6vh 5vw;color:var(--muted);text-align:center}
  /* Two heading levels: a subcategory (e.g. "Футбол/Футзал" within "Игровая
     форма") is a real content division, styled like a heading; gender is a
     lighter sub-grouping nested under it (or under the page title directly,
     for the many categories with no subcategory at all). */
  .subcat-label{
    margin:3vh 5vw 0;font:700 clamp(17px,2.2vh,22px) var(--display);
  }
  .gender-label{
    display:flex;align-items:center;gap:10px;margin:1.6vh 5vw .8vh;
    font:600 11px var(--mono);text-transform:uppercase;letter-spacing:.06em;color:var(--muted);
  }
  .gender-label::after{content:'';flex:1;height:1px;background:var(--line)}
  .gender-label .count{color:var(--muted);font-weight:400}
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
        <p><?= count($models) ?> <?= pluralRu(count($models), 'модель', 'модели', 'моделей') ?> · цена указана оптовая, за единицу.</p>
      </div>
<?php if (empty($models)): ?>
      <div class="empty-state">В этой категории пока нет товаров в выгрузке.</div>
<?php else: ?>
<?php foreach ($bySub as $subKey => $subGroup):
        $hasSubcat = $subKey !== '__none__'; ?>
<?php if ($hasSubcat): ?>
      <h2 class="subcat-label"><?= h($subGroup['title']) ?></h2>
<?php endif; ?>
<?php $isFlat = isset($subGroup['genders']['__flat__']); ?>
<?php foreach (($isFlat ? ['__flat__' => null] : $genderOrder) as $genderKey => $genderValue):
        $genderModels = $subGroup['genders'][$genderKey] ?? [];
        if (empty($genderModels)) continue; ?>
<?php if (!$isFlat): ?>
      <div class="gender-label"><?= h($genderValue) ?> <span class="count"><?= count($genderModels) ?></span></div>
<?php endif; ?>
      <div class="products-grid">
<?php foreach ($genderModels as $m): $item = $m['base']; $stock = totalStock($item);
        $stockClass = $stock === 0 ? 'out' : ($stock < 20 ? 'low' : 'ok');
        $genderSuffix = $isFlat ? ['мужская' => ' · муж.', 'женская' => ' · жен.', 'унисекс' => ''][normalizeGroup($item['group'] ?? null)] : ''; ?>
        <div class="product-card">
          <div class="product-photo">
            <img src="<?= h((string) $item['photo_path']) ?>" alt="" loading="lazy" onerror="this.closest('.product-photo').classList.add('photo-missing');this.remove()">
            <span class="stock-dot <?= $stockClass ?>" title="<?= $stock ?> шт. на складе"></span>
          </div>
          <p class="product-name"><?= h((string) $item['name']) ?></p>
          <p class="product-meta"><?= $m['colorCount'] ?> <?= pluralRu($m['colorCount'], 'цвет', 'цвета', 'цветов') ?><?= $genderSuffix ?></p>
          <p class="product-price"><?= fmtTenge($item['price_opt'] !== null ? (float) $item['price_opt'] : null) ?></p>
        </div>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
    </div>
  </div>
</main>

<script src="app.js"></script>
</body>
</html>
