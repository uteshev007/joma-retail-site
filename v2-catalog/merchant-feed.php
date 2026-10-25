<?php
declare(strict_types=1);

/**
 * Товарный фид для Google Merchant Center (требование для полноценной
 * работы Performance Max с реальными товарами вместо текстовых/
 * графических объявлений вслепую — в кабинете Google Ads прямым текстом:
 * "Merchant Center не настроено"). Формат — Google Shopping RSS 2.0
 * (https://support.google.com/merchants/answer/7052112), генерируется
 * из того же кэшированного каталога CRM, что и сами страницы сайта —
 * без лишних запросов к CRM, актуальность та же, что у каталога
 * (обновляется раз в cache_ttl_seconds).
 *
 * Один элемент фида — одна модель+цвет (article), не одна модель+размер:
 * у апparel-фидов Google предпочитает разбивку по размеру тоже, но при
 * сотнях моделей с 5-13 размерами это даёт десятки тысяч строк — для
 * первого подключения разбивка по цвету (не по размеру) достаточна и
 * сильно проще; наличие считается "в наличии", если есть сток хотя бы
 * по одному размеру.
 */

require_once __DIR__ . '/CatalogClient.php';
require_once __DIR__ . '/photo-helpers.php';

$config = require __DIR__ . '/config.php';
$client = new V2Catalog\CatalogClient($config);
$data = $client->getItems();

function xmlEscape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
<channel>
  <title>Joma Teamwear — каталог</title>
  <link>https://joma-retail.kz/</link>
  <description>Экипировка для спортивных команд Joma — каталог для Google Merchant Center</description>
<?php foreach ($data['items'] as $item):
    $article = (string) ($item['article'] ?? '');
    if ($article === '') {
        continue;
    }
    $model = (string) ($item['model_number'] ?: $article);
    $category = (string) ($item['category'] ?? '');
    $name = (string) ($item['name'] ?? '');
    $price = $item['price_opt'] ?? null;
    if ($name === '' || $price === null) {
        continue;
    }
    $totalStock = 0;
    foreach ($item['sizes'] ?? [] as $s) {
        $totalStock += (int) ($s['qty'] ?? 0);
    }
    $availability = $totalStock > 0 ? 'in_stock' : 'out_of_stock';
    $link = 'https://joma-retail.kz/product.php?model=' . urlencode($model)
        . '&category=' . urlencode($category)
        . '&color=' . urlencode($article);
    $imageLink = photoProxyUrl($item['photo_path'] ?? null);
    if ($imageLink === null) {
        continue;
    }
    if (!str_starts_with($imageLink, 'http')) {
        $imageLink = 'https://joma-retail.kz/' . ltrim($imageLink, '/');
    }
    $description = (string) ($item['description'] ?? $name);
?>
  <item>
    <g:id><?= xmlEscape($article) ?></g:id>
    <g:item_group_id><?= xmlEscape($model) ?></g:item_group_id>
    <title><?= xmlEscape($name) ?></title>
    <description><?= xmlEscape(mb_substr($description, 0, 5000)) ?></description>
    <link><?= xmlEscape($link) ?></link>
    <g:image_link><?= xmlEscape($imageLink) ?></g:image_link>
    <g:availability><?= $availability ?></g:availability>
    <g:price><?= number_format((float) $price, 2, '.', '') ?> KZT</g:price>
    <g:brand>Joma</g:brand>
    <g:condition>new</g:condition>
<?php if (!empty($item['color'])): ?>
    <g:color><?= xmlEscape((string) $item['color']) ?></g:color>
<?php endif; ?>
<?php if ($category !== ''): ?>
    <g:product_type><?= xmlEscape($category) ?></g:product_type>
<?php endif; ?>
  </item>
<?php endforeach; ?>
</channel>
</rss>
