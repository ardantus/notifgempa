# Migration Guide: PHP to Cloudflare Worker

Dokumen ini menjelaskan perbedaan dan cara migrasi dari versi PHP ke Cloudflare Worker.

## Perbedaan Utama

### 1. Runtime Environment

**PHP Version:**
- Berjalan di Docker container
- Long-running process dengan `while(true)` loop
- Menggunakan SQLite file database
- Bisa menggunakan `sleep()` untuk delay

**Cloudflare Worker:**
- Berjalan di Cloudflare edge network
- Event-driven dengan Cron Triggers
- Menggunakan D1 Database (cloud SQL)
- Tidak bisa `sleep()` - gunakan `setTimeout()` atau Promise delay

### 2. Scheduling

**PHP:**
```php
while (true) {
    sleep($CHECK_INTERVAL);
    process_gempa_data($db);
}
```

**Cloudflare Worker:**
```toml
# wrangler.toml
triggers = { crons = ["*/3 * * * *"] }
```

### 3. Database

**PHP:**
```php
$db = new PDO("sqlite:$DB_FILE");
```

**Cloudflare Worker:**
```javascript
// D1 Database via binding
await env.DB.prepare("SELECT ...").run();
```

### 4. HTTP Requests

**PHP:**
```php
$ch = curl_init($url);
curl_setopt_array($ch, [...]);
$response = curl_exec($ch);
```

**Cloudflare Worker:**
```javascript
const response = await fetch(url, {
  method: 'POST',
  body: JSON.stringify(data)
});
```

### 5. XML Parsing

**PHP:**
```php
$data = simplexml_load_string($response);
```

**Cloudflare Worker:**
```javascript
const parser = new DOMParser();
const xmlDoc = parser.parseFromString(xmlText, 'text/xml');
```

### 6. Error Handling

**PHP:**
- Try-catch dengan exceptions
- `error_log()` untuk logging

**Cloudflare Worker:**
- Try-catch dengan async/await
- `console.log()` untuk logging (terlihat di `wrangler tail`)

## Mapping Functions

| PHP Function | JavaScript Function | Notes |
|--------------|---------------------|-------|
| `log_message()` | `logMessage()` | Same logic |
| `validate_config()` | `validateConfig()` | Same logic |
| `init_db()` | `initDb()` | D1 instead of SQLite |
| `escape_markdown()` | `escapeMarkdown()` | Same logic |
| `send_slack_notification()` | `sendSlackNotification()` | Uses `fetch()` instead of cURL |
| `send_telegram_notification()` | `sendTelegramNotification()` | Uses `fetch()` instead of cURL |
| `normalize_datetime()` | `normalizeDatetime()` | Uses JavaScript Date |
| `is_recent_gempa()` | `isRecentGempa()` | Uses JavaScript Date |
| `save_gempa()` | `saveGempa()` | D1 prepared statements |
| `fetch_xml_with_retry()` | `fetchXMLWithRetry()` | Uses `fetch()` with AbortController |
| `process_gempa_source()` | `processGempaSource()` | Same logic, async |
| `process_gempa_data()` | `processGempaData()` | Same logic, async |

## Environment Variables

**PHP (Docker):**
```bash
# .env file
SLACK_WEBHOOK=...
TELEGRAM_BOT_TOKEN=...
```

**Cloudflare Worker:**
```bash
# Set as secrets
wrangler secret put SLACK_WEBHOOK
wrangler secret put TELEGRAM_BOT_TOKEN
```

## Deployment

**PHP:**
```bash
docker-compose up -d
```

**Cloudflare Worker:**
```bash
npm run deploy
```

## Monitoring

**PHP:**
```bash
docker-compose logs -f
```

**Cloudflare Worker:**
```bash
wrangler tail
```

## Cost Comparison

### PHP Version
- Server hosting: $5-20/month
- Bandwidth: Included
- Storage: Included

### Cloudflare Worker
- **Free Tier**: 100K requests/day
- **Paid**: $5/month untuk 10M requests
- D1 Database: Free tier cukup untuk penggunaan normal

**Kesimpulan**: Cloudflare Worker lebih murah untuk penggunaan normal!

## Keuntungan Cloudflare Worker

1. ✅ **No Server Management** - Tidak perlu maintain server
2. ✅ **Global Edge Network** - Lebih cepat dari lokasi manapun
3. ✅ **Auto Scaling** - Handle traffic spike otomatis
4. ✅ **Pay-per-use** - Hanya bayar yang dipakai
5. ✅ **Built-in Monitoring** - Dashboard dan logs terintegrasi

## Kekurangan Cloudflare Worker

1. ❌ **Cron Minimum 1 menit** - Tidak bisa kurang dari 1 menit
2. ❌ **Execution Time Limit** - Max 30 detik per request (Cron: 15 menit)
3. ❌ **No Persistent Storage** - Harus pakai D1/KV
4. ❌ **Cold Start** - Bisa ada delay pertama kali

## Tips Migration

1. **Test XML Parsing** - Struktur XML BMKG mungkin perlu disesuaikan
2. **Test Database Queries** - D1 syntax sedikit berbeda dari SQLite
3. **Monitor Logs** - Gunakan `wrangler tail` untuk debugging
4. **Test Cron** - Pastikan cron trigger jalan sesuai jadwal
5. **Backup Data** - Export data dari SQLite sebelum migrasi

## Next Steps

1. Setup Cloudflare account
2. Install Wrangler CLI
3. Create D1 database
4. Deploy worker
5. Set secrets
6. Monitor logs
7. Test notifications
