<?php
declare(strict_types=1);

/**
 * Карта сайта генерируется автоматически из каталога CRM, а не ведётся
 * руками — в каталоге ~2800 товаров (~700 моделей), вручную не угнаться.
 * Та же кэшированная выгрузка, что и у самих страниц (CatalogClient),
 * так что лишних запросов к CRM это не добавляет.
 */

require_once __DIR__ . '/CatalogClient.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);
$data = $client->getItems();

$byCategory = [];
$byModel = [];
foreach ($data['items'] as $item) {
    $cat = $item['category'] ?? null;
    if ($cat === null) {
        continue;
    }
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = true;
    }
    $model = (string) ($item['model_number'] ?: $item['article']);
    $updatedAt = (string) ($item['updated_at'] ?? '');
    if (!isset($byModel[$model]) || $updatedAt > $byModel[$model]['updatedAt']) {
        $byModel[$model] = ['category' => $cat, 'updatedAt' => $updatedAt];
    }
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://joma-retail.kz/</loc><priority>1.0</priority></url>
  <url><loc>https://joma-retail.kz/catalog.php</loc><priority>0.9</priority></url>
<?php foreach (array_keys($byCategory) as $cat): ?>
  <url><loc>https://joma-retail.kz/category.php?name=<?= urlencode($cat) ?></loc><priority>0.7</priority></url>
<?php endforeach; ?>
<?php foreach ($byModel as $model => $info):
    $lastmod = $info['updatedAt'] !== '' ? substr(str_replace(' ', 'T', $info['updatedAt']), 0, 19) . '+05:00' : null; ?>
  <url><loc>https://joma-retail.kz/product.php?model=<?= urlencode((string) $model) ?>&amp;category=<?= urlencode($info['category']) ?></loc><priority>0.5</priority><?= $lastmod !== null ? '<lastmod>' . $lastmod . '</lastmod>' : '' ?></url>
<?php endforeach; ?>
</urlset>
