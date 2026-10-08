<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogClient.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);

$categoryName = $_GET['name'] ?? '';
$subParam = $_GET['sub'] ?? null;
$genderParam = $_GET['gender'] ?? null;
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

/**
 * CRM присылает group как "мужская"/"Мужская"/"женская"/null вперемешку
 * (регистр не нормализован на их стороне) — приводим к одному виду.
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

function subModelCount(array $group): int
{
    $n = 0;
    foreach ($group['genders'] as $models) {
        $n += count($models);
    }
    return $n;
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
    $subKey = $sub ?? '__none__';
    if (!isset($bySub[$subKey])) {
        $bySub[$subKey] = ['title' => $sub, 'photo' => null, 'genders' => []];
    }
    if ($bySub[$subKey]['photo'] === null && !empty($m['base']['photo_path'])) {
        $bySub[$subKey]['photo'] = $m['base']['photo_path'];
    }
    $bySub[$subKey]['genders'][normalizeGroup($m['base']['group'] ?? null)][] = $m;
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
// "воздуха". Показываем пол отдельными секциями только когда минимум 2
// группы набирают приличное количество моделей каждая — иначе плоский
// список (пол тогда виден прямо в подписи карточки).
const MIN_MODELS_PER_GENDER_BUCKET = 2;
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

// Настоящих подкатегорий 2 и больше (не считая "без подкатегории", которой
// в такой ситуации и не бывает) — показываем отдельный шаг выбора вместо
// того, чтобы валить все подкатегории на одну длинную страницу. Категории
// без подкатегорий (большинство) сразу показывают товары, как раньше.
$realSubKeys = array_filter(array_keys($bySub), fn($k) => $k !== '__none__');
$needsSubPicker = count($realSubKeys) >= 2 && $subParam === null;

// Когда подкатегория выбрана (или её нет вовсе), работаем только с одной
// веткой $bySub — либо явно запрошенной, либо единственной ('__none__').
$activeSubKey = $subParam !== null ? $subParam : '__none__';
$activeSub = $bySub[$activeSubKey] ?? null;

// Внутри подкатегории (или всей категории, если подкатегорий нет) — тот же
// принцип: если деление по полу реально оправдано (посчитано выше), это
// отдельный шаг выбора, а не секции на одной странице с товарами.
$isFlat = $activeSub !== null && isset($activeSub['genders']['__flat__']);
$needsGenderPicker = $activeSub !== null && !$isFlat && $genderParam === null;

function genderBucketPhoto(array $models): ?string
{
    foreach ($models as $m) {
        if (!empty($m['base']['photo_path'])) {
            return $m['base']['photo_path'];
        }
    }
    return null;
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($subParam ?: $categoryName ?: 'Категория') ?> — Joma Teamwear</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
<style>
  /* Products page: real catalogs run from dozens to hundreds of items
     (52–729 in this data), so unlike the ring layouts (category index,
     subcategory picker), this is always a plain scrolling grid — on
     desktop too. */
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
  .product-photo.photo-missing::after,
  .ring-thumb.photo-missing::after{
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
  .gender-label{
    display:flex;align-items:center;gap:10px;margin:1.6vh 5vw .8vh;
    font:600 11px var(--mono);text-transform:uppercase;letter-spacing:.06em;color:var(--muted);
  }
  .gender-label::after{content:'';flex:1;height:1px;background:var(--line)}
  .gender-label .count{color:var(--muted);font-weight:400}
  .sub-count{font:11px var(--mono);color:var(--muted);margin-top:2px}
</style>
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
<?php if ($needsSubPicker): ?>
  <!-- Шаг выбора подкатегории — тот же паттерн колец, что и в каталоге,
       вместо того чтобы сваливать все подкатегории на одну страницу. -->
  <div class="page" data-page="catalog">
    <div class="cat-showcase">
      <div class="page-head">
        <div class="page-head-top">
          <a class="back-page" href="index.php" aria-label="Назад в каталог"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
          <h1><?= h($categoryName) ?></h1>
        </div>
        <p><?= count($realSubKeys) ?> <?= pluralRu(count($realSubKeys), 'подкатегория', 'подкатегории', 'подкатегорий') ?> · <?= count($models) ?> <?= pluralRu(count($models), 'модель', 'модели', 'моделей') ?> всего.</p>
      </div>
      <div class="cat-main-row">
        <div class="rings-col">
          <div class="rings-grid">
<?php foreach ($realSubKeys as $subKey): $sub = $bySub[$subKey]; ?>
            <a class="ring-card" href="category.php?name=<?= urlencode($categoryName) ?>&sub=<?= urlencode($subKey) ?>">
              <div class="ring-wrap">
                <div class="ring-thumb"><img src="<?= h((string) $sub['photo']) ?>" alt="" loading="lazy" onerror="this.closest('.ring-thumb').classList.add('photo-missing');this.remove()"></div>
              </div>
              <p class="ring-name"><?= h($subKey) ?></p>
              <p class="sub-count"><?= subModelCount($sub) ?> <?= pluralRu(subModelCount($sub), 'модель', 'модели', 'моделей') ?></p>
            </a>
<?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($activeSub === null): ?>
  <div class="page" data-page="products">
    <div class="cat-showcase">
      <div class="page-head">
        <div class="page-head-top">
          <a class="back-page" href="index.php" aria-label="Назад в каталог"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
          <h1><?= h($categoryName ?: 'Категория') ?></h1>
        </div>
      </div>
      <div class="empty-state">В этой категории пока нет товаров в выгрузке.</div>
    </div>
  </div>

<?php else: ?>
<?php
// Куда ведёт "назад" с этого шага — к выбору подкатегории, если он
// существует для этой категории, иначе прямо в каталог.
$backToSubOrCatalog = !empty($realSubKeys)
    ? 'category.php?name=' . urlencode($categoryName)
    : 'index.php';
$backToSubOrCatalogLabel = !empty($realSubKeys) ? 'Назад к подкатегориям' : 'Назад в каталог';
?>
<?php if ($needsGenderPicker): ?>
  <!-- Шаг выбора пола — тот же паттерн колец, когда деление реально
       оправдано (посчитано выше, в MIN_MODELS_PER_GENDER_BUCKET). -->
  <div class="page" data-page="catalog">
    <div class="cat-showcase">
      <div class="page-head">
        <div class="page-head-top">
          <a class="back-page" href="<?= h($backToSubOrCatalog) ?>" aria-label="<?= h($backToSubOrCatalogLabel) ?>"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
          <h1><?= h($subParam ?: $categoryName) ?></h1>
        </div>
        <p><?= subModelCount($activeSub) ?> <?= pluralRu(subModelCount($activeSub), 'модель', 'модели', 'моделей') ?> всего.</p>
      </div>
      <div class="cat-main-row">
        <div class="rings-col">
          <div class="rings-grid">
<?php foreach ($genderOrder as $genderKey => $genderTitle):
          $genderModels = $activeSub['genders'][$genderKey] ?? [];
          if (empty($genderModels)) continue; ?>
            <a class="ring-card" href="category.php?name=<?= urlencode($categoryName) ?><?= $subParam !== null ? '&sub=' . urlencode($subParam) : '' ?>&gender=<?= urlencode($genderKey) ?>">
              <div class="ring-wrap">
                <div class="ring-thumb"><img src="<?= h((string) genderBucketPhoto($genderModels)) ?>" alt="" loading="lazy" onerror="this.closest('.ring-thumb').classList.add('photo-missing');this.remove()"></div>
              </div>
              <p class="ring-name"><?= h($genderTitle) ?></p>
              <p class="sub-count"><?= count($genderModels) ?> <?= pluralRu(count($genderModels), 'модель', 'модели', 'моделей') ?></p>
            </a>
<?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- Товары: либо один пол (выбранный на шаге выше), либо вся подкатегория
       плоским списком, когда деление по полу не было оправдано. -->
<?php
  $genderModels = $isFlat ? ($activeSub['genders']['__flat__'] ?? []) : ($activeSub['genders'][$genderParam] ?? []);
  $pageTitle = !$isFlat && $genderParam !== null ? ($genderOrder[$genderParam] ?? $genderParam) : ($subParam ?: $categoryName);
  $backHref = !$isFlat && $genderParam !== null
      ? 'category.php?name=' . urlencode($categoryName) . ($subParam !== null ? '&sub=' . urlencode($subParam) : '')
      : $backToSubOrCatalog;
  $backLabel = !$isFlat && $genderParam !== null ? 'Назад к выбору пола' : $backToSubOrCatalogLabel;
?>
  <div class="page" data-page="products">
    <div class="cat-showcase">
      <div class="page-head">
        <div class="page-head-top">
          <a class="back-page" href="<?= h($backHref) ?>" aria-label="<?= h($backLabel) ?>"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
          <h1><?= h($pageTitle ?: 'Категория') ?></h1>
        </div>
        <p><?= count($genderModels) ?> <?= pluralRu(count($genderModels), 'модель', 'модели', 'моделей') ?> · цена указана оптовая, за единицу.</p>
      </div>
      <div class="products-grid">
<?php foreach ($genderModels as $m): $item = $m['base']; $stock = totalStock($item);
        $stockClass = $stock === 0 ? 'out' : ($stock < 20 ? 'low' : 'ok');
        $genderSuffix = $isFlat ? ['мужская' => ' · муж.', 'женская' => ' · жен.', 'унисекс' => ''][normalizeGroup($item['group'] ?? null)] : ''; ?>
        <a class="product-card" href="product.php?model=<?= urlencode((string) ($item['model_number'] ?: $item['article'])) ?>&category=<?= urlencode($categoryName) ?><?= $subParam !== null ? '&sub=' . urlencode($subParam) : '' ?><?= (!$isFlat && $genderParam !== null) ? '&gender=' . urlencode($genderParam) : '' ?>">
          <div class="product-photo">
            <img src="<?= h((string) $item['photo_path']) ?>" alt="" loading="lazy" onerror="this.closest('.product-photo').classList.add('photo-missing');this.remove()">
            <span class="stock-dot <?= $stockClass ?>" title="<?= $stock ?> шт. на складе"></span>
          </div>
          <p class="product-name"><?= h((string) $item['name']) ?></p>
          <p class="product-meta"><?= $m['colorCount'] ?> <?= pluralRu($m['colorCount'], 'цвет', 'цвета', 'цветов') ?><?= $genderSuffix ?></p>
          <p class="product-price"><?= fmtTenge($item['price_opt'] !== null ? (float) $item['price_opt'] : null) ?></p>
        </a>
<?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>
</main>

<script src="app.js"></script>
</body>
</html>
