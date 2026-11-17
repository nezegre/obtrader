// ObTrader alarm sunucusu — Telegram botu + 7/24 fiyat ve balina takibi
// Bağımlılık yok: Node 22+ (yerleşik fetch ve WebSocket).
// Çalıştırma: BOT_TOKEN=xxx node alarm.js
'use strict';
const fs = require('fs');
const path = require('path');

const TOKEN = process.env.BOT_TOKEN;
if (!TOKEN) { console.error('BOT_TOKEN ortam değişkeni yok'); process.exit(1); }
const DATA = process.env.DATA_FILE || path.join(__dirname, 'data.json');
const TG = `https://api.telegram.org/bot${TOKEN}`;
const MAX_ALARM = 25;              // kişi başı en fazla alarm
const WHALE_COOLDOWN = 10 * 60e3;  // aynı balina emri için tekrar bildirme süresi
const EXCHANGES = ['binance', 'okx', 'coinbase'];

// ---------- kalıcı veri ----------
let db = { chats: {} };            // chats[chatId] = { alarms: [ {id,type,ex,base,quote,op,price,usd} ] }
try { db = JSON.parse(fs.readFileSync(DATA, 'utf8')); } catch {}
let saveTimer = null;
function save() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    const tmp = DATA + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(db));
    fs.renameSync(tmp, DATA);
  }, 500);
}
const chat = id => (db.chats[id] ||= { alarms: [], nextId: 1 });

// ---------- yardımcılar ----------
const opx = o => o === '>' ? '&gt;' : '&lt;';
const fmt = n => n >= 1 ? n.toLocaleString('en-US', { maximumFractionDigits: 2 }) : n.toPrecision(4);
const usd = n => '$' + Math.round(n).toLocaleString('en-US');
const defQuote = ex => ex === 'coinbase' ? 'USD' : 'USDT';
const key = (ex, b, q) => `${ex}:${b}-${q}`;

async function send(chatId, text) {
  try {
    await fetch(`${TG}/sendMessage`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: chatId, text, parse_mode: 'HTML', disable_web_page_preview: true }),
    });
  } catch (e) { console.error('send', e.message); }
}

// Anlık fiyat (alarm eklerken parite kontrolü için)
async function spot(ex, b, q) {
  try {
    if (ex === 'binance') {
      const j = await (await fetch(`https://api.binance.com/api/v3/ticker/price?symbol=${b}${q}`)).json();
      return j.price ? +j.price : null;
    }
    if (ex === 'okx') {
      const j = await (await fetch(`https://www.okx.com/api/v5/market/ticker?instId=${b}-${q}`)).json();
      return j.data?.[0]?.last ? +j.data[0].last : null;
    }
    if (ex === 'coinbase') {
      const j = await (await fetch(`https://api.exchange.coinbase.com/products/${b}-${q}/ticker`)).json();
      return j.price ? +j.price : null;
    }
  } catch {}
  return null;
}

// ---------- canlı fiyat akışları ----------
const last = {};            // key -> son fiyat
const sockets = {};         // ex -> { ws, subs:Set }
const whaleSeen = new Map(); // dedupe

function wanted() {
  const w = { binance: new Set(), okx: new Set(), coinbase: new Set(), depth: new Set() };
  for (const c of Object.values(db.chats)) for (const a of c.alarms) {
    w[a.ex].add(`${a.base}-${a.quote}`);
    if (a.type === 'whale') w.depth.add(`${a.base}${a.quote}`.toLowerCase());
  }
  return w;
}

function onPrice(ex, b, q, p) {
  const k = key(ex, b, q);
  const prev = last[k];
  last[k] = p;
  if (prev == null) return;
  for (const [cid, c] of Object.entries(db.chats)) {
    const hit = c.alarms.filter(a => a.type === 'price' && a.ex === ex && a.base === b && a.quote === q &&
      ((a.op === '>' && prev < a.price && p >= a.price) || (a.op === '<' && prev > a.price && p <= a.price)));
    for (const a of hit) {
      send(cid, `🔔 <b>${b}/${q}</b> ${a.op === '>' ? 'yukarı kırdı' : 'aşağı kırdı'}: <b>${fmt(p)}</b>\nAlarm: ${opx(a.op)} ${fmt(a.price)} · ${ex.toUpperCase()}`);
      c.alarms = c.alarms.filter(x => x !== a);   // TabTrader gibi: tetiklenen alarm silinir
      save();
    }
  }
}

function onBook(sym, bids, asks) {          // Binance depth20 — sym: btcusdt
  for (const [cid, c] of Object.entries(db.chats)) {
    for (const a of c.alarms) {
      if (a.type !== 'whale' || `${a.base}${a.quote}`.toLowerCase() !== sym) continue;
      for (const [side, rows] of [['ALIŞ', bids], ['SATIŞ', asks]]) {
        for (const [px, qty] of rows) {
          const val = +px * +qty;
          if (val < a.usd) continue;
          const d = `${cid}:${sym}:${side}:${px}`;
          if (Date.now() - (whaleSeen.get(d) || 0) < WHALE_COOLDOWN) continue;
          whaleSeen.set(d, Date.now());
          send(cid, `🐋 <b>${a.base}/${a.quote}</b> büyük ${side} emri\n${fmt(+px)} fiyatında ${fmt(+qty)} ${a.base} ≈ <b>${usd(val)}</b> · BINANCE`);
        }
      }
    }
  }
}

function open(ex, url, onOpen, onMsg) {
  const s = sockets[ex];
  if (s?.ws) { try { s.ws.close(); } catch {} }
  const ws = new WebSocket(url);
  sockets[ex] = { ws };
  ws.onopen = () => onOpen(ws);
  ws.onmessage = ev => { try { onMsg(JSON.parse(ev.data), ws); } catch {} };
  ws.onclose = () => { if (sockets[ex]?.ws === ws) { delete sockets[ex]; setTimeout(() => resubscribe(true), 5000); } };
  ws.onerror = () => {};
}

let lastPlan = '';
function resubscribe(force) {
  const w = wanted();
  const plan = JSON.stringify([...w.binance, '|', ...w.okx, '|', ...w.coinbase, '|', ...w.depth]);
  if (plan === lastPlan && !force) return;
  lastPlan = plan;

  // Binance: ticker + derinlik tek bağlantıda
  const bStreams = [...w.binance].map(p => p.replace('-', '').toLowerCase() + '@miniTicker')
    .concat([...w.depth].map(s => s + '@depth20@1000ms'));
  if (bStreams.length) open('binance', 'wss://stream.binance.com:9443/stream?streams=' + bStreams.join('/'), () => {}, m => {
    const st = m.stream || '', d = m.data;
    if (st.endsWith('@miniTicker')) {
      const pair = [...w.binance].find(p => p.replace('-', '') === d.s);
      if (pair) { const [b, q] = pair.split('-'); onPrice('binance', b, q, +d.c); }
    } else if (st.includes('@depth20')) onBook(st.split('@')[0], d.bids, d.asks);
  });
  else if (sockets.binance?.ws) { sockets.binance.ws.close(); delete sockets.binance; }

  if (w.okx.size) open('okx', 'wss://ws.okx.com:8443/ws/v5/public',
    ws => ws.send(JSON.stringify({ op: 'subscribe', args: [...w.okx].map(instId => ({ channel: 'tickers', instId })) })),
    m => { for (const t of m.data || []) { const [b, q] = t.instId.split('-'); onPrice('okx', b, q, +t.last); } });
  else if (sockets.okx?.ws) { sockets.okx.ws.close(); delete sockets.okx; }

  if (w.coinbase.size) open('coinbase', 'wss://ws-feed.exchange.coinbase.com',
    ws => ws.send(JSON.stringify({ type: 'subscribe', product_ids: [...w.coinbase], channels: ['ticker'] })),
    m => { if (m.type === 'ticker') { const [b, q] = m.product_id.split('-'); onPrice('coinbase', b, q, +m.price); } });
  else if (sockets.coinbase?.ws) { sockets.coinbase.ws.close(); delete sockets.coinbase; }
}
// OKX bağlantıyı canlı tutmak için 25 sn'de bir ping ister
setInterval(() => { try { sockets.okx?.ws?.readyState === 1 && sockets.okx.ws.send('ping'); } catch {} }, 25e3);

// ---------- komutlar ----------
const HELP = `<b>ObTrader Alarm</b>
Fiyat alarmı:
<code>/alarm BTC &gt; 100000</code>
<code>/alarm ETH &lt; 3000 okx</code>
<code>/alarm SOL &gt; 250 coinbase</code>

Balina alarmı (Binance tahtasında büyük emir):
<code>/balina BTC 1000000</code>  → 1 milyon $ üstü emir

<code>/liste</code> · <code>/sil 3</code> · <code>/yardim</code>
Borsa yazmazsan Binance kullanılır. Pariteler USDT (Coinbase'de USD).`;

async function handle(msg) {
  const cid = String(msg.chat.id);
  const t = (msg.text || '').trim();
  const [cmd, ...args] = t.split(/\s+/);
  const c = chat(cid);
  const c0 = cmd.toLowerCase().replace(/@.*/, '');

  if (c0 === '/start' || c0 === '/yardim' || c0 === '/help') return send(cid, HELP);

  if (c0 === '/liste') {
    if (!c.alarms.length) return send(cid, 'Aktif alarmın yok.');
    return send(cid, c.alarms.map(a => a.type === 'price'
      ? `${a.id}. ${a.base}/${a.quote} ${opx(a.op)} ${fmt(a.price)} · ${a.ex}`
      : `${a.id}. 🐋 ${a.base}/${a.quote} ≥ ${usd(a.usd)} · binance`).join('\n'));
  }

  if (c0 === '/sil') {
    const id = +args[0];
    const n = c.alarms.length;
    c.alarms = c.alarms.filter(a => a.id !== id);
    if (c.alarms.length === n) return send(cid, 'Bu numarada alarm yok. /liste ile bak.');
    save(); resubscribe(); return send(cid, `${id} numaralı alarm silindi.`);
  }

  if (c0 === '/alarm' || c0 === '/balina') {
    if (c.alarms.length >= MAX_ALARM) return send(cid, `En fazla ${MAX_ALARM} alarm kurabilirsin. /sil ile yer aç.`);
    const base = (args[0] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (!base) return send(cid, HELP);

    if (c0 === '/balina') {
      const u = +String(args[1] || '').replace(/[.,_]/g, '');
      if (!(u >= 10000)) return send(cid, 'Tutar en az 10.000 $ olmalı. Örnek: <code>/balina BTC 1000000</code>');
      const p = await spot('binance', base, 'USDT');
      if (p == null) return send(cid, `${base}/USDT Binance'te bulunamadı.`);
      c.alarms.push({ id: c.nextId++, type: 'whale', ex: 'binance', base, quote: 'USDT', usd: u });
      save(); resubscribe();
      return send(cid, `🐋 Tamam. ${base}/USDT tahtasında ${usd(u)} üstü emir görünce haber vereceğim.`);
    }

    const op = args[1];
    const price = +String(args[2] || '').replace(',', '.');
    const ex = (args[3] || 'binance').toLowerCase();
    if (!['>', '<'].includes(op) || !(price > 0)) return send(cid, 'Biçim: <code>/alarm BTC &gt; 100000</code>');
    if (!EXCHANGES.includes(ex)) return send(cid, `Borsa: ${EXCHANGES.join(', ')}`);
    const quote = defQuote(ex);
    const now = await spot(ex, base, quote);
    if (now == null) return send(cid, `${base}/${quote} ${ex.toUpperCase()}'ta bulunamadı.`);
    if ((op === '>' && now >= price) || (op === '<' && now <= price))
      return send(cid, `Şu an fiyat ${fmt(now)}; alarm hemen tetiklenirdi. Seviyeyi kontrol et.`);
    c.alarms.push({ id: c.nextId++, type: 'price', ex, base, quote, op, price });
    last[key(ex, base, quote)] ??= now;
    save(); resubscribe();
    return send(cid, `✅ ${base}/${quote} ${opx(op)} ${fmt(price)} · ${ex.toUpperCase()}\nŞu an: ${fmt(now)}`);
  }

  return send(cid, HELP);
}

// ---------- Telegram uzun yoklama ----------
let offset = 0;
async function poll() {
  for (;;) {
    try {
      const j = await (await fetch(`${TG}/getUpdates?timeout=50&offset=${offset}`)).json();
      for (const u of j.result || []) {
        offset = u.update_id + 1;
        if (u.message?.text) await handle(u.message).catch(e => console.error('handle', e.message));
      }
    } catch (e) { console.error('poll', e.message); await new Promise(r => setTimeout(r, 3000)); }
  }
}

resubscribe(true);
setInterval(() => resubscribe(), 30e3);   // kopan bağlantıları ve değişiklikleri yakala
setInterval(() => { const t = Date.now(); for (const [k, v] of whaleSeen) if (t - v > WHALE_COOLDOWN) whaleSeen.delete(k); }, 60e3);
poll();
console.log('ObTrader alarm sunucusu çalışıyor');
