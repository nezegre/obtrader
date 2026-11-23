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

// Uygulamadan gelen bağlantı: /start binance_BTC_g_100000_250000  (nokta yerine 'd')
if ($cmd === '/start' && isset($args[0]) && preg_match('/^(binance|okx|coinbase)_([A-Z0-9]{1,15})_([gl])_([0-9d]+)_([0-9d]+)$/', $args[0], $m)) {
    [, $ex, $base, $o, $p, $w] = $m;
    $price = (float)str_replace('d', '.', $p);
    $whale = (float)str_replace('d', '.', $w);
    $out = ["📲 <b>ObTrader</b> uygulamasından gelen alarm:"];
    if ($price > 0) $out[] = make_price($cid, $base, $o === 'g' ? '>' : '<', $price, $ex);
    if ($whale > 0) $out[] = make_whale($cid, $base, $whale);
    if (count($out) === 1) $out[] = 'Alarm bilgisi boş geldi.';
    $out[] = "\n/liste ile tüm alarmlarını görebilirsin.";
    send($cid, implode("\n\n", $out)); exit;
}

// Uygulamada silinen alarm: /start del_binance_BTC_pw  (p = fiyat, w = balina)
if ($cmd === '/start' && isset($args[0]) && preg_match('/^del_(binance|okx|coinbase)_([A-Z0-9]{1,15})_(p?w?)$/', $args[0], $m) && $m[3] !== '') {
    [, $ex, $base, $what] = $m;
    $n = with_db(function (&$db) use ($cid, $ex, $base, $what) {
        $al = $db['chats'][$cid]['alarms'] ?? [];
        $new = array_values(array_filter($al, fn($a) => !($a['base'] === $base && (
            ($a['type'] === 'price' && $a['ex'] === $ex && strpos($what, 'p') !== false) ||
            ($a['type'] === 'whale' && strpos($what, 'w') !== false)))));
        if (isset($db['chats'][$cid])) $db['chats'][$cid]['alarms'] = $new;
        return count($al) - count($new);
    });
    send($cid, $n ? "🗑 $base için $n alarm silindi (uygulamadan)." : "$base için silinecek alarm bulunamadı. /liste ile bak.");
    exit;
}

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
        if (isset($db['chats'][$cid])) $db['chats'][$cid]['alarms'] = $new;
        return count($new) !== count($al);
    });
    send($cid, $ok ? "$id numaralı alarm silindi." : 'Bu numarada alarm yok. /liste ile bak.'); exit;
}

function add_alarm(string $cid, array $alarm): void {
    with_db(function (&$db) use ($cid, $alarm) {
        $c = $db['chats'][$cid] ?? ['alarms' => [], 'nextId' => 1];
        $alarm['id'] = $c['nextId']++;
        $c['alarms'][] = $alarm;
        $db['chats'][$cid] = $c;
    });
}
function alarm_count(string $cid): int { return with_db(fn(&$db) => count($db['chats'][$cid]['alarms'] ?? [])); }

// Fiyat alarmı kurar, kullanıcıya gidecek metni döndürür
function make_price(string $cid, string $base, string $op, float $price, string $ex): string {
    if (alarm_count($cid) >= MAX_ALARM) return 'En fazla ' . MAX_ALARM . ' alarm kurabilirsin. /sil ile yer aç.';
    if (!in_array($ex, EXCHANGES, true)) return 'Borsa: ' . implode(', ', EXCHANGES);
    $quote = def_quote($ex);
    $now = spot($ex, $base, $quote);
    if ($now === null) return "$base/$quote " . strtoupper($ex) . "'ta bulunamadı.";
    if (($op === '>' && $now >= $price) || ($op === '<' && $now <= $price))
        return "$base/$quote şu an " . fmt($now) . '; ' . opx($op) . ' ' . fmt($price) . ' alarmı hemen tetiklenirdi. Seviyeyi kontrol et.';
    add_alarm($cid, ['type' => 'price', 'ex' => $ex, 'base' => $base, 'quote' => $quote, 'op' => $op, 'price' => $price, 'ref' => $now]);
    return "✅ $base/$quote " . opx($op) . ' ' . fmt($price) . ' · ' . strtoupper($ex) . "\nŞu an: " . fmt($now);
}

// Balina alarmı kurar
function make_whale(string $cid, string $base, float $amt): string {
    if (alarm_count($cid) >= MAX_ALARM) return 'En fazla ' . MAX_ALARM . ' alarm kurabilirsin. /sil ile yer aç.';
    if ($amt < 10000) return 'Balina tutarı en az 10.000 $ olmalı.';
    if (spot('binance', $base, 'USDT') === null) return "$base/USDT Binance'te bulunamadı (balina takibi şimdilik sadece Binance).";
    add_alarm($cid, ['type' => 'whale', 'ex' => 'binance', 'base' => $base, 'quote' => 'USDT', 'usd' => $amt]);
    return "🐋 $base/USDT tahtasında " . usd($amt) . ' üstü emir görünce haber vereceğim.';
}

if ($cmd === '/alarm' || $cmd === '/balina') {
    $base = preg_replace('/[^A-Z0-9]/', '', strtoupper($args[0] ?? ''));
    if ($base === '') { send($cid, HELP); exit; }
    if ($cmd === '/balina') {
        send($cid, make_whale($cid, $base, (float)str_replace(['.', ',', '_'], '', $args[1] ?? ''))); exit;
    }
    $op = $args[1] ?? '';
    $price = (float)str_replace(',', '.', $args[2] ?? '');
    if (!in_array($op, ['>', '<'], true) || $price <= 0) { send($cid, 'Biçim: <code>/alarm BTC &gt; 100000</code>'); exit; }
    send($cid, make_price($cid, $base, $op, $price, strtolower($args[3] ?? 'binance'))); exit;
}

send($cid, HELP);
