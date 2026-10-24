<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogClient.php';
require_once __DIR__ . '/photo-helpers.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);
$categories = $client->getCategorySummaries();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Каталог — Joma Teamwear</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="style.css">
<link rel="icon" href="/logo/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/logo/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/logo/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/logo/favicon-180.png">
<style>
  /* Index-page specifics: category count badge on each ring, no detail panel
     (the catalog index doesn't need one — each tile just opens the category). */
  .cat-count{font:11px var(--mono);color:var(--muted);margin-top:2px}
  /* Same corrupt-image fallback as category.php — see its comment for why
     this is needed (the supplier's own image endpoint, not our bug). */
  .ring-thumb.photo-missing::after{
    content:'Нет фото';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font:10px var(--mono);color:var(--muted);text-align:center;
  }
</style>
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <div class="page" data-page="catalog">
    <div class="cat-showcase">
      <div class="page-head">
        <h1>Каталог</h1>
      </div>
      <div class="cat-main-row">
        <div class="rings-col">
          <div class="rings-grid">
<?php foreach ($categories as $cat): ?>
            <a class="ring-card" href="category.php?name=<?= urlencode($cat['name']) ?>">
              <div class="ring-wrap">
                <div class="ring-thumb"><img src="<?= h((string) photoProxyUrl($cat['photo'])) ?>" alt="" loading="lazy" onerror="this.closest('.ring-thumb').classList.add('photo-missing');this.remove()"></div>
              </div>
              <p class="ring-name"><?= h($cat['name']) ?></p>
              <p class="cat-count"><?= $cat['count'] ?> товаров</p>
            </a>
<?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="app.js"></script>
</body>
</html>
