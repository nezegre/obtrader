<?php
// ObTrader alarm — ortak fonksiyonlar (PHP 7.4+, cURL)
declare(strict_types=1);
require __DIR__ . '/config.php';            // BOT_TOKEN, HOOK_SECRET, HOOK_URL

const MAX_ALARM = 25;
const WHALE_COOLDOWN = 600;                 // sn — aynı balina emri tekrar bildirilmez
const EXCHANGES = ['binance', 'okx', 'coinbase'];
define('DATA_FILE', __DIR__ . '/data.json');

function http_get(string $url, int $timeout = 8): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'ObTraderAlarm/1.0', CURLOPT_FOLLOWLOCATION => true]);
    $r = curl_exec($ch);
    curl_close($ch);
    if ($r === false) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

function tg(string $method, array $params): ?array {
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($params)]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

function send($chatId, string $text): void {
    $r = tg('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
    if (empty($r['ok'])) @file_put_contents(__DIR__ . '/hook.log', date('c') . ' send hata: ' . json_encode($r) . "\n", FILE_APPEND);
}

// Veri dosyasını kilitleyerek oku-değiştir-yaz
function with_db(callable $fn) {
    $fp = fopen(DATA_FILE, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $db = $raw ? json_decode($raw, true) : null;
    if (!is_array($db)) $db = ['chats' => [], 'last' => [], 'seen' => []];
    $out = $fn($db);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $out;
}

function fmt(float $n): string {
    if ($n >= 1) return number_format($n, $n >= 100 ? 0 : 2, '.', ',');
    return rtrim(rtrim(sprintf('%.6f', $n), '0'), '.');
}
function opx(string $op): string { return $op === '>' ? '&gt;' : '&lt;'; }
function usd(float $n): string { return '$' . number_format($n, 0, '.', ','); }
function def_quote(string $ex): string { return $ex === 'coinbase' ? 'USD' : 'USDT'; }

// Tek paritenin anlık fiyatı
function spot(string $ex, string $b, string $q): ?float {
    if ($ex === 'binance') { $j = http_get("https://api.binance.com/api/v3/ticker/price?symbol=$b$q"); return isset($j['price']) ? (float)$j['price'] : null; }
    if ($ex === 'okx')     { $j = http_get("https://www.okx.com/api/v5/market/ticker?instId=$b-$q"); return isset($j['data'][0]['last']) ? (float)$j['data'][0]['last'] : null; }
    if ($ex === 'coinbase'){ $j = http_get("https://api.exchange.coinbase.com/products/$b-$q/ticker"); return isset($j['price']) ? (float)$j['price'] : null; }
    return null;
}
