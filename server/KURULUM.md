# ObTrader alarm sunucusu — kurulum

## 1. Telegram botu (2 dk)
1. Telegram'da **@BotFather**'ı aç → `/newbot`
2. Ad: `ObTrader Alarm` · kullanıcı adı: `ObTraderAlarmBot` (alınmışsa `obtrader_alarm_bot`)
3. Verilen **token'ı kimseyle paylaşma**, sadece aşağıdaki 4. adımda sunucuya yapıştır.

## 2. Sunucu (Hetzner CX22, Ubuntu 24.04)
SSH ile bağlandıktan sonra:

```bash
# Node 22
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs git

# kod
git clone https://github.com/nezegre/obtrader.git /opt/obtrader
cd /opt/obtrader/server
```

## 3. Servis olarak çalıştır (7/24, çökünce kendini açar)
```bash
cat > /etc/systemd/system/obtrader-alarm.service <<'EOF'
[Unit]
Description=ObTrader alarm
After=network-online.target

[Service]
WorkingDirectory=/opt/obtrader/server
EnvironmentFile=/opt/obtrader/server/.env
ExecStart=/usr/bin/node alarm.js
Restart=always
RestartSec=5
User=nobody
Group=nogroup

[Install]
WantedBy=multi-user.target
EOF
chown -R nobody:nogroup /opt/obtrader/server
```

## 4. Token'ı gir (bunu sen yaz)
```bash
nano /opt/obtrader/server/.env
# içine tek satır:  BOT_TOKEN=buraya_token
chmod 600 /opt/obtrader/server/.env && chown nobody:nogroup /opt/obtrader/server/.env
systemctl daemon-reload && systemctl enable --now obtrader-alarm
journalctl -u obtrader-alarm -f      # "çalışıyor" yazısını gör, Ctrl+C ile çık
```

## 5. Test
Botuna `/start` yaz, sonra `/alarm BTC > 1` gibi hemen tetiklenmeyecek bir seviye dene,
`/liste` ile gör, `/sil 1` ile sil.

## Güncelleme
```bash
cd /opt/obtrader && git pull && systemctl restart obtrader-alarm
```

---

# Ücretsiz sürüm: paylaşımlı hosting (cPanel) — şu an canlı olan

`server/php/` klasörü PHP + cron ile çalışır (dakikada bir kontrol).

1. `lib.php`, `hook.php`, `cron.php` dosyalarını web kökünün **dışındaki** bir klasöre koy (ör. `/home/kullanici/obt-alarm/`), yanına `Require all denied` içeren bir `.htaccess` ekle.
2. `config.example.php` → `config.php` olarak kopyala, token'ı yapıştır, `HOOK_SECRET`'a rastgele uzun bir metin yaz.
3. Web kökünde tek satırlık bir dosya aç (ör. `public_html/api/obt-hook.php`):
   `<?php require '/home/kullanici/obt-alarm/hook.php';`
4. cPanel → Cron: her dakika `/usr/local/bin/php /home/kullanici/obt-alarm/cron.php >/dev/null 2>&1`
5. İlk cron çalışmasında webhook kendiliğinden Telegram'a tanıtılır (`.webhook_ok` dosyası oluşur).
