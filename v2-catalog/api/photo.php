<?php
declare(strict_types=1);

/**
 * Прокси-кэш фото поставщика (joma-sport.net) — см. photo-helpers.php.
 * Публичный (без логина), доступ ограничен проверкой домена-источника.
 * Логика и лимиты зеркалят CRM (/catalog/photo в public/index.php).
 */

$url = (string) ($_GET['u'] ?? '');
$host = parse_url($url, PHP_URL_HOST);
if (!in_array($host, ['www.joma-sport.net', 'joma-sport.net'], true)) {
    http_response_code(400);
    exit;
}

/**
 * Поставщик для части цветов отдаёт не 404, а валидную заглушку
 * "нет фото" (белый квадрат с крестом по диагонали) с кодом 200 — ничего
 * не ошибается, карточка просто показывала эту заглушку как настоящее
 * фото ("товары у которых не загружаются фотографии не должны быть
 * главными"). Заглушка — всегда один и тот же файл (проверено на 5 из
 * 22 цветов одной модели: 29542 байта, один MD5), отличить от реального
 * фото по размеру/формату. Ловим это здесь и отдаём 502, как при
 * обычной ошибке — остальной пайплайн (onerror/imgFallbackChain,
 * подмена на фото другого цвета) уже это умеет.
 */
const NO_PHOTO_PLACEHOLDER_MD5 = '6a76cd4e94d8586128f558878c8ef821';

/**
 * Доработка: PageSpeed показывал LCP 8.6с на мобильном — товарные фото
 * шли как есть с сайта поставщика (JPEG 600×600, 20-40Кб), хотя на
 * сетке карточка — 160-190px, а значит и на retina больше ~380px не
 * нужно. Теперь сжимаем до WebP (обычно на 25-50% легче JPEG того же
 * качества) и ужимаем длинную сторону до 640px (retina-запас под самое
 * крупное использование — фото на странице товара, 420px) при записи
 * в кэш — на отдачу из кэша (почти все запросы) это не влияет совсем,
 * дороже только самый первый заход на конкретное фото.
 */
function toWebpResized(string $jpegData, int $maxSide = 640, int $quality = 82): ?string
{
    $src = @imagecreatefromstring($jpegData);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $maxSide / max($w, $h));
    if ($scale < 1.0) {
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $src = $resized;
    }
    ob_start();
    imagewebp($src, null, $quality);
    $out = ob_get_clean();
    return $out !== '' ? $out : null;
}

$cacheDir = __DIR__ . '/../../storage/photo-cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/' . md5($url) . '.webp';

if (!is_file($cacheFile) || filemtime($cacheFile) < time() - 7 * 86400) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Joma Teamwear photo proxy)',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data !== false && $code === 200 && $data !== '' && md5($data) === NO_PHOTO_PLACEHOLDER_MD5) {
        http_response_code(502);
        exit;
    }
    if ($data !== false && $code === 200 && $data !== '') {
        $webp = toWebpResized($data);
        if ($webp !== null) {
            file_put_contents($cacheFile, $webp);
        } elseif (!is_file($cacheFile)) {
            // Не смогли декодировать (не JPEG/битые байты) — отдаём как
            // пришло, лучше оригинал, чем ничего.
            file_put_contents($cacheFile, $data);
        }
    } elseif (!is_file($cacheFile)) {
        http_response_code(502);
        exit;
    }
    // при ошибке, но с уже существующим старым кэшем — отдаём устаревшую версию, не пустоту

    if (mt_rand(1, 50) === 1) {
        $limitBytes = 600 * 1024 * 1024;
        $files = glob($cacheDir . '/*.webp') ?: [];
        $size = 0;
        $byMtime = [];
        foreach ($files as $f) {
            $sz = filesize($f) ?: 0;
            $size += $sz;
            $byMtime[] = ['path' => $f, 'mtime' => filemtime($f) ?: 0, 'size' => $sz];
        }
        if ($size > $limitBytes) {
            usort($byMtime, fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
            foreach ($byMtime as $f) {
                if ($size <= $limitBytes) {
                    break;
                }
                unlink($f['path']);
                $size -= $f['size'];
            }
        }
    }
}

header('Content-Type: image/webp');
header('Cache-Control: public, max-age=604800');
readfile($cacheFile);
