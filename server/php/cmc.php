<?php
// ObTrader — CMC Korku & Açgözlülük + Altcoin Sezonu ara katmanı (PHP 7.4+, cURL)
// Web kökünde: public_html/api/cmc.php →  <?php require '/home/cryptov1/obt-alarm/cmc.php';
// Anahtar isteğe bağlı: config.php'ye  const CMC_KEY = '...';  ekle. Yoksa CMC'nin anahtarsız (keyless) yolu kullanılır.
declare(strict_types=1);

const CMC_TTL = 600;                          // sn — CMC bu verileri 15 dk'da bir günceller
define('CMC_CACHE', __DIR__ . '/cmc-cache.json');
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https://(www\.)?obtrader\.app$#', $origin)) header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

function cmc_get(string $path): ?array {
    $key = defined('CMC_KEY') ? CMC_KEY : '';
    $url = 'https://pro-api.coinmarketcap.com' . ($key ? '' : '/public-api') . $path;
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($key) $h[] = 'X-CMC_PRO_API_KEY: ' . $key;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => $h,
        CURLOPT_USERAGENT => 'ObTrader/1.0']);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $code !== 200) { @file_put_contents(__DIR__ . '/cmc.log', date('c') . " $path HTTP $code " . substr((string)$r, 0, 200) . "\n", FILE_APPEND); return null; }
    $j = json_decode($r, true);
    return is_array($j['data'] ?? null) ? $j['data'] : null;
}

$cache = is_file(CMC_CACHE) ? json_decode((string)file_get_contents(CMC_CACHE), true) : null;
if (!is_array($cache) || time() - ($cache['fetched'] ?? 0) >= CMC_TTL) {
    $fp = fopen(CMC_CACHE . '.lock', 'c');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {          // aynı anda tek istek CMC'ye gitsin
        $new = is_array($cache) ? $cache : [];
        $fg = cmc_get('/v3/fear-and-greed/latest');
        if ($fg && is_numeric($fg['value'] ?? null))
            $new['fg'] = ['v' => (int)$fg['value'], 't' => strtotime((string)($fg['update_time'] ?? '')) ?: time()];
        $as = cmc_get('/v1/altcoin-season-index/latest');
        if ($as && is_numeric($as['altcoin_index'] ?? null))
            $new['as'] = ['v' => (int)round((float)$as['altcoin_index']), 't' => strtotime((string)($as['snapshot_time'] ?? '')) ?: time()];
        $new['fetched'] = time();                          // hata olsa da 10 dk bekle, krediyi koru
        @file_put_contents(CMC_CACHE, json_encode($new));
        $cache = $new;
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

echo json_encode(['fg' => $cache['fg'] ?? null, 'as' => $cache['as'] ?? null]);
