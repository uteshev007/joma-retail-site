<?php
declare(strict_types=1);

namespace V2Catalog;

/**
 * Читает каталог из CRM (GET /api/catalog) с файловым кэшем — обновляется
 * "лениво" при первом запросе после истечения TTL, без отдельного cron
 * (см. v2-catalog/README.md и инструкцию CRM-стороны: раз в 15-30 мин
 * обновления достаточно).
 *
 * Старый вручную забитый каталог (prices.js/images.js/catalog-*.html)
 * заменяется этим классом как единственным источником данных о товарах.
 */
final class CatalogClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array{items: array<int, array<string, mixed>>, fetchedAt: string, stale: bool} */
    public function getItems(): array
    {
        $cachePath = $this->config['cache_path'];
        $ttl = (int) $this->config['cache_ttl_seconds'];

        $cached = $this->readCache($cachePath);
        if ($cached !== null && (time() - $cached['fetchedAt']) < $ttl) {
            return ['items' => $cached['items'], 'fetchedAt' => date('c', $cached['fetchedAt']), 'stale' => false];
        }

        $fresh = $this->fetchFromApi();
        if ($fresh !== null) {
            $this->writeCache($cachePath, $fresh);
            return ['items' => $fresh, 'fetchedAt' => date('c'), 'stale' => false];
        }

        // API недоступен — отдаём старый кэш, даже просроченный, лучше
        // устаревшие данные, чем пустой каталог на сайте.
        if ($cached !== null) {
            return ['items' => $cached['items'], 'fetchedAt' => date('c', $cached['fetchedAt']), 'stale' => true];
        }

        return ['items' => [], 'fetchedAt' => date('c'), 'stale' => true];
    }

    /** Группирует товары по category -> список, с фото-превью и счётчиком. */
    public function getCategorySummaries(): array
    {
        $data = $this->getItems();
        $byCategory = [];
        foreach ($data['items'] as $item) {
            $cat = $item['category'] ?? 'Без категории';
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['name' => $cat, 'count' => 0, 'photo' => null];
            }
            $byCategory[$cat]['count']++;
            if ($byCategory[$cat]['photo'] === null && !empty($item['photo_path'])) {
                $byCategory[$cat]['photo'] = $item['photo_path'];
            }
        }
        $summaries = array_values($byCategory);
        usort($summaries, fn($a, $b) => $b['count'] <=> $a['count']);
        return $summaries;
    }

    /** @return array<int, array<string, mixed>> */
    public function getItemsByCategory(string $category): array
    {
        $data = $this->getItems();
        return array_values(array_filter(
            $data['items'],
            fn($item) => ($item['category'] ?? null) === $category
        ));
    }

    /** Все цвета одной модели (по model_number) — для карточки товара. */
    public function getItemsByModel(string $modelNumber): array
    {
        $data = $this->getItems();
        return array_values(array_filter(
            $data['items'],
            fn($item) => ($item['model_number'] ?? $item['article'] ?? null) === $modelNumber
        ));
    }

    private function fetchFromApi(): ?array
    {
        $ch = curl_init($this->config['crm_api_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $this->config['crm_api_key']],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FAILONERROR => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $code !== 200) {
            return null;
        }
        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !isset($parsed['items']) || !is_array($parsed['items'])) {
            return null;
        }
        return $parsed['items'];
    }

    private function readCache(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || !isset($parsed['items'], $parsed['fetchedAt'])) {
            return null;
        }
        return ['items' => $parsed['items'], 'fetchedAt' => (int) $parsed['fetchedAt']];
    }

    private function writeCache(string $path, array $items): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $payload = json_encode(['items' => $items, 'fetchedAt' => time()], JSON_UNESCAPED_UNICODE);
        // Атомарная запись — пишем во временный файл и переименовываем,
        // чтобы параллельный запрос никогда не увидел наполовину
        // записанный JSON.
        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $payload);
        rename($tmpPath, $path);
    }
}
