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

$cacheDir = __DIR__ . '/../../storage/photo-cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/' . md5($url) . '.jpg';

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
    if ($data !== false && $code === 200 && $data !== '') {
        file_put_contents($cacheFile, $data);
    } elseif (!is_file($cacheFile)) {
        http_response_code(502);
        exit;
    }
    // при ошибке, но с уже существующим старым кэшем — отдаём устаревшую версию, не пустоту

    if (mt_rand(1, 50) === 1) {
        $limitBytes = 600 * 1024 * 1024;
        $files = glob($cacheDir . '/*.jpg') ?: [];
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

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
readfile($cacheFile);
