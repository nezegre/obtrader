<?php
// Telegram webhook — komutları karşılar
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== HOOK_SECRET) { http_response_code(403); exit; }
$u = json_decode(file_get_contents('php://input') ?: '', true);
http_response_code(200);
$msg = $u['message'] ?? null;
if (!$msg || !isset($msg['text'])) exit;

$cid = (string)$msg['chat']['id'];
$args = preg_split('/\s+/', trim($msg['text']));
$cmd = strtolower(preg_replace('/@.*/', '', array_shift($args)));

const HELP = "<b>ObTrader Alarm</b>\nFiyat alarmı:\n<code>/alarm BTC &gt; 100000</code>\n<code>/alarm ETH &lt; 3000 okx</code>\n<code>/alarm SOL &gt; 250 coinbase</code>\n\nBalina alarmı (Binance tahtasında büyük emir):\n<code>/balina BTC 1000000</code>  → 1 milyon \$ üstü emir\n\n<code>/liste</code> · <code>/sil 3</code> · <code>/yardim</code>\nBorsa yazmazsan Binance kullanılır. Pariteler USDT (Coinbase'de USD). Fiyatlar dakikada bir kontrol edilir.";

if (in_array($cmd, ['/start', '/yardim', '/help'], true)) { send($cid, HELP); exit; }

if ($cmd === '/liste') {
    $c = with_db(fn(&$db) => $db['chats'][$cid] ?? null);
    if (!$c || !$c['alarms']) { send($cid, 'Aktif alarmın yok.'); exit; }
    $lines = array_map(fn($a) => $a['type'] === 'price'
        ? "{$a['id']}. {$a['base']}/{$a['quote']} " . opx($a['op']) . ' ' . fmt((float)$a['price']) . " · {$a['ex']}"
        : "{$a['id']}. 🐋 {$a['base']}/{$a['quote']} ≥ " . usd((float)$a['usd']) . " · binance", $c['alarms']);
    send($cid, implode("\n", $lines)); exit;
}

if ($cmd === '/sil') {
    $id = (int)($args[0] ?? 0);
    $ok = with_db(function (&$db) use ($cid, $id) {
        $al = $db['chats'][$cid]['alarms'] ?? [];
        $new = array_values(array_filter($al, fn($a) => $a['id'] !== $id));
        $db['chats'][$cid]['alarms'] = $new;
        return count($new) !== count($al);
    });
    send($cid, $ok ? "$id numaralı alarm silindi." : 'Bu numarada alarm yok. /liste ile bak.'); exit;
}

if ($cmd === '/alarm' || $cmd === '/balina') {
    $base = preg_replace('/[^A-Z0-9]/', '', strtoupper($args[0] ?? ''));
    if ($base === '') { send($cid, HELP); exit; }
    $count = with_db(fn(&$db) => count($db['chats'][$cid]['alarms'] ?? []));
    if ($count >= MAX_ALARM) { send($cid, 'En fazla ' . MAX_ALARM . ' alarm kurabilirsin. /sil ile yer aç.'); exit; }

    if ($cmd === '/balina') {
        $amt = (float)str_replace(['.', ',', '_'], '', $args[1] ?? '');
        if ($amt < 10000) { send($cid, 'Tutar en az 10.000 $ olmalı. Örnek: <code>/balina BTC 1000000</code>'); exit; }
        if (spot('binance', $base, 'USDT') === null) { send($cid, "$base/USDT Binance'te bulunamadı."); exit; }
        $alarm = ['type' => 'whale', 'ex' => 'binance', 'base' => $base, 'quote' => 'USDT', 'usd' => $amt];
        $reply = "🐋 Tamam. $base/USDT tahtasında " . usd($amt) . ' üstü emir görünce haber vereceğim.';
    } else {
        $op = $args[1] ?? '';
        $price = (float)str_replace(',', '.', $args[2] ?? '');
        $ex = strtolower($args[3] ?? 'binance');
        if (!in_array($op, ['>', '<'], true) || $price <= 0) { send($cid, 'Biçim: <code>/alarm BTC &gt; 100000</code>'); exit; }
        if (!in_array($ex, EXCHANGES, true)) { send($cid, 'Borsa: ' . implode(', ', EXCHANGES)); exit; }
        $quote = def_quote($ex);
        $now = spot($ex, $base, $quote);
        if ($now === null) { send($cid, "$base/$quote " . strtoupper($ex) . "'ta bulunamadı."); exit; }
        if (($op === '>' && $now >= $price) || ($op === '<' && $now <= $price)) {
            send($cid, 'Şu an fiyat ' . fmt($now) . '; alarm hemen tetiklenirdi. Seviyeyi kontrol et.'); exit;
        }
        $alarm = ['type' => 'price', 'ex' => $ex, 'base' => $base, 'quote' => $quote, 'op' => $op, 'price' => $price, 'ref' => $now];
        $reply = "✅ $base/$quote " . opx($op) . ' ' . fmt($price) . ' · ' . strtoupper($ex) . "\nŞu an: " . fmt($now);
    }
    with_db(function (&$db) use ($cid, $alarm) {
        $c = $db['chats'][$cid] ?? ['alarms' => [], 'nextId' => 1];
        $alarm['id'] = $c['nextId']++;
        $c['alarms'][] = $alarm;
        $db['chats'][$cid] = $c;
    });
    send($cid, $reply); exit;
}

send($cid, HELP);
