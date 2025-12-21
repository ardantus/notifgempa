# Gempa Monitor - Cloudflare Worker

Versi Cloudflare Worker dari aplikasi monitoring gempa BMKG. Aplikasi ini berjalan di Cloudflare Workers dan menggunakan D1 Database untuk menyimpan data gempa.

## Fitur

- ✅ Fetch data gempa dari BMKG (autogempa, gempaterkini, gempadirasakan)
- ✅ Menyimpan data ke D1 Database dengan deduplication
- ✅ Mengirim notifikasi ke Slack dan Telegram untuk gempa baru
- ✅ Scheduled execution menggunakan Cron Triggers (setiap 3 menit)
- ✅ Manual trigger via HTTP endpoint
- ✅ Health check endpoint

## Prerequisites

1. **Cloudflare Account** - Daftar di [cloudflare.com](https://cloudflare.com)
2. **Node.js** - Versi 18 atau lebih baru
3. **Wrangler CLI** - Install dengan `npm install -g wrangler`

## Setup

### 1. Install Dependencies

```bash
cd workergempa
npm install
```

### 2. Login ke Cloudflare

```bash
wrangler login
```

### 3. Buat D1 Database

```bash
# Buat database
wrangler d1 create gempa-db

# Copy database_id yang dihasilkan, lalu update di wrangler.toml
# [[d1_databases]]
# binding = "DB"
# database_name = "gempa-db"
# database_id = "YOUR_DATABASE_ID_HERE"  # Paste database_id di sini
```

### 4. Setup Database Schema

```bash
wrangler d1 execute gempa-db --file=schema.sql
```

### 5. Set Environment Variables (Secrets)

```bash
# Set Slack Webhook
wrangler secret put SLACK_WEBHOOK
# Paste webhook URL saat diminta

# Set Telegram Bot Token
wrangler secret put TELEGRAM_BOT_TOKEN
# Paste bot token saat diminta

# Set Telegram Chat ID
wrangler secret put TELEGRAM_CHAT_ID
# Paste chat ID saat diminta

# Set flags (optional, default: true)
wrangler secret put SEND_TO_SLACK
# Ketik: true

wrangler secret put SEND_TO_TELEGRAM
# Ketik: true
```

### 6. Update wrangler.toml

Edit `wrangler.toml` dan ganti `YOUR_DATABASE_ID_HERE` dengan database ID yang didapat dari step 3.

## Development

### Run Locally

```bash
npm run dev
```

Worker akan berjalan di `http://localhost:8787`

### Test Manual Trigger

```bash
curl -X POST http://localhost:8787/trigger
```

### Health Check

```bash
curl http://localhost:8787/health
```

## Deployment

### Deploy ke Cloudflare

```bash
npm run deploy
```

### View Logs

```bash
npm run tail
```

## Konfigurasi Cron Trigger

Cron trigger dikonfigurasi di `wrangler.toml`:

```toml
triggers = { crons = ["*/3 * * * *"] }  # Setiap 3 menit
```

**Catatan**: Cloudflare Workers Cron minimum adalah 1 menit. Jika ingin interval yang lebih pendek, pertimbangkan menggunakan multiple cron triggers atau service yang berbeda.

Format cron: `minute hour day month weekday`

Contoh:
- `*/3 * * * *` - Setiap 3 menit
- `*/5 * * * *` - Setiap 5 menit
- `0 * * * *` - Setiap jam
- `0 */6 * * *` - Setiap 6 jam

## Endpoints

### GET /health
Health check endpoint. Returns status dan timestamp.

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2024-01-01T00:00:00.000Z"
}
```

### POST /trigger
Manual trigger untuk menjalankan proses monitoring gempa.

**Response:**
```json
{
  "success": true,
  "newEvents": 2,
  "timestamp": "2024-01-01T00:00:00.000Z"
}
```

## Struktur Project

```
workergempa/
├── src/
│   └── index.js          # Main worker code
├── schema.sql            # D1 Database schema
├── wrangler.toml         # Cloudflare Worker configuration
├── package.json          # Dependencies
└── README.md            # Dokumentasi ini
```

## Perbedaan dengan Versi PHP

| Aspek | PHP Version | Cloudflare Worker |
|-------|-------------|-------------------|
| Language | PHP | JavaScript |
| Database | SQLite (file) | D1 Database (cloud) |
| Scheduling | `while(true)` loop | Cron Triggers |
| HTTP Client | cURL | `fetch()` API |
| XML Parsing | SimpleXML | DOMParser |
| Environment | Docker container | Cloudflare edge |
| Cost | Server hosting | Pay-per-use |

## Troubleshooting

### Database tidak terhubung
- Pastikan `database_id` di `wrangler.toml` sudah benar
- Pastikan database sudah dibuat dengan `wrangler d1 create`
- Pastikan schema sudah dijalankan dengan `wrangler d1 execute`

### Notifikasi tidak terkirim
- Cek secrets sudah di-set dengan benar: `wrangler secret list`
- Cek logs dengan `wrangler tail`
- Pastikan `SEND_TO_SLACK` atau `SEND_TO_TELEGRAM` di-set ke `"true"` (string)

### Cron tidak jalan
- Pastikan worker sudah di-deploy: `npm run deploy`
- Cek cron triggers di Cloudflare Dashboard
- Minimum interval adalah 1 menit

### XML parsing error
- BMKG mungkin mengubah format XML
- Cek response XML dengan manual fetch
- Update `xmlToObject()` function jika diperlukan

## Monitoring

### View Logs
```bash
wrangler tail
```

### View di Cloudflare Dashboard
1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Pilih Workers & Pages
3. Pilih worker `gempa-monitor`
4. Lihat logs dan metrics

## Cost Estimation

Cloudflare Workers pricing (per 2024):
- **Free Tier**: 100,000 requests/hari
- **Paid Tier**: $5/month untuk 10M requests

Dengan cron setiap 3 menit:
- 20 requests/jam
- 480 requests/hari
- **Masih dalam free tier!**

D1 Database pricing:
- **Free Tier**: 5GB storage, 5M reads/hari, 100K writes/hari
- **Paid Tier**: $0.001 per 1M reads, $1.00 per 1M writes

## License

MIT License - sama dengan project utama
