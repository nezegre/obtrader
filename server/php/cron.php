<?php
// Dakikada bir çalışır: fiyat ve balina alarmlarını kontrol eder
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib.php';

// Tek seferlik: webhook'u Telegram'a bildir
$flag = __DIR__ . '/.webhook_ok';
if (!is_file($flag) && BOT_TOKEN !== 'BURAYA_TOKEN') {
    $r = tg('setWebhook', ['url' => HOOK_URL, 'secret_token' => HOOK_SECRET, 'allowed_updates' => ['message']]);
    if (!empty($r['ok'])) touch($flag);
}

$db = with_db(fn(&$d) => $d);
$need = ['binance' => false, 'okx' => false, 'coinbase' => [], 'depth' => []];
foreach ($db['chats'] as $c) foreach ($c['alarms'] as $a) {
    if ($a['ex'] === 'coinbase') $need['coinbase']["{$a['base']}-{$a['quote']}"] = 1;
    else $need[$a['ex']] = true;
    if ($a['type'] === 'whale') $need['depth'][$a['base'] . $a['quote']] = 1;
}

// Fiyatları toplu çek
$px = [];
if ($need['binance']) foreach (http_get('https://api.binance.com/api/v3/ticker/price') ?? [] as $t)
    $px['binance:' . $t['symbol']] = (float)$t['price'];
if ($need['okx']) foreach ((http_get('https://www.okx.com/api/v5/market/tickers?instType=SPOT')['data'] ?? []) as $t)
    $px['okx:' . str_replace('-', '', $t['instId'])] = (float)$t['last'];
foreach (array_keys($need['coinbase']) as $p) {
    $j = http_get("https://api.exchange.coinbase.com/products/$p/ticker");
    if (isset($j['price'])) $px['coinbase:' . str_replace('-', '', $p)] = (float)$j['price'];
}

// Balina: Binance tahtasından ilk 100 kademe
$books = [];
foreach (array_keys($need['depth']) as $s) {
    $j = http_get("https://api.binance.com/api/v3/depth?symbol=$s&limit=100");
    if (isset($j['bids'])) $books[$s] = $j;
}

$out = [];  // [chatId, metin]
$whale = []; // chatId => parite => satırlar
with_db(function (&$db) use ($px, $books, &$out, &$whale) {
    $now = time();
    foreach ($db['chats'] as $cid => &$c) {
        $keep = [];
        foreach ($c['alarms'] as $a) {
            if ($a['type'] === 'price') {
                $k = $a['ex'] . ':' . $a['base'] . $a['quote'];
                $p = $px[$k] ?? null;
                if ($p !== null && (($a['op'] === '>' && $p >= $a['price']) || ($a['op'] === '<' && $p <= $a['price']))) {
                    $out[] = [$cid, "🔔 <b>{$a['base']}/{$a['quote']}</b> " . ($a['op'] === '>' ? 'yukarı kırdı' : 'aşağı kırdı') .
                        ': <b>' . fmt($p) . "</b>\nAlarm: " . opx($a['op']) . ' ' . fmt((float)$a['price']) . ' · ' . strtoupper($a['ex'])];
                    continue;                    // tetiklenen alarm silinir
                }
            } elseif ($a['type'] === 'whale' && isset($books[$a['base'] . $a['quote']])) {
                $bk = $books[$a['base'] . $a['quote']];
                foreach (['ALIŞ' => $bk['bids'], 'SATIŞ' => $bk['asks']] as $side => $rows) foreach ($rows as [$p, $q]) {
                    $val = (float)$p * (float)$q;
                    if ($val < $a['usd']) continue;
                    $d = "$cid:{$a['base']}{$a['quote']}:$side:$p";
                    if ($now - ($db['seen'][$d] ?? 0) < WHALE_COOLDOWN) continue;
                    $db['seen'][$d] = $now;
                    $whale[$cid]["{$a['base']}/{$a['quote']}"][] = ($side === 'ALIŞ' ? '🟢 ' : '🔴 ') . "$side " . fmt((float)$p) .
                        ' · ' . fmt((float)$q) . " {$a['base']} ≈ <b>" . usd($val) . '</b>';
                }
            }
            $keep[] = $a;
        }
        $c['alarms'] = $keep;
    }
    unset($c);
    foreach ($db['seen'] as $k => $t) if ($now - $t > WHALE_COOLDOWN) unset($db['seen'][$k]);
});

foreach ($whale as $cid => $pairs) foreach ($pairs as $pair => $lines)
    $out[] = [$cid, "🐋 <b>$pair</b> büyük emir · BINANCE\n" . implode("\n", array_slice($lines, 0, 10))];
foreach ($out as [$cid, $text]) send($cid, $text);
