# Gempa Monitor - Cloudflare Worker

Versi Cloudflare Worker dari aplikasi monitoring gempa BMKG. Aplikasi ini berjalan di Cloudflare Workers dan menggunakan D1 Database untuk menyimpan data gempa.

## Fitur

- ✅ **Monitoring Gempa** - Fetch data gempa dari BMKG (autogempa, gempaterkini, gempadirasakan)
- ✅ **Peringatan Dini Cuaca** - Monitor peringatan dini cuaca real-time dari BMKG (setiap 5 menit)
- ✅ **Prakiraan Cuaca** - Monitor prakiraan cuaca untuk wilayah tertentu (setiap 60 menit)
- ✅ Menyimpan data ke D1 Database dengan deduplication
- ✅ Mengirim notifikasi ke Slack dan Telegram untuk gempa baru, peringatan dini, dan cuaca ekstrem
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

### 5. Set Environment Variables

#### A. Set Secrets (untuk data sensitif)

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
```

#### B. Set Environment Variables (bisa via wrangler.toml atau deploy command)

**Opsi 1: Via wrangler.toml** (disarankan untuk development)

Edit `wrangler.toml` dan tambahkan di section `[vars]`:

```toml
[vars]
SEND_TO_SLACK = "true"
SEND_TO_TELEGRAM = "true"
CUACA_WILAYAH = "34.71.01.1001,34.04.07.1001"  # Kode wilayah adm4 (comma-separated)
```

**Opsi 2: Via deploy command**

```bash
npx wrangler deploy --var SEND_TO_SLACK="true" --var SEND_TO_TELEGRAM="true" --var CUACA_WILAYAH="34.71.01.1001,34.04.07.1001"
```

**Opsi 3: Via Cloudflare Dashboard**

1. Buka [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Pilih Workers & Pages → gempa-monitor
3. Settings → Variables → Environment Variables
4. Tambah variables:
   - `SEND_TO_SLACK` = `true`
   - `SEND_TO_TELEGRAM` = `true`
   - `CUACA_WILAYAH` = `34.71.01.1001,34.04.07.1001` (sesuaikan dengan wilayah yang ingin dimonitor)

**Catatan Kode Wilayah (adm4):**
- Format: `XX.XX.XX.XXXX` (contoh: `34.71.01.1001` untuk Gondomanan, Yogyakarta)
- Lihat daftar lengkap di `KODE_WILAYAH_YOGYAKARTA.md`
- Multiple wilayah: pisahkan dengan koma (contoh: `34.71.01.1001,34.04.07.1001,34.02.01.2001`)

### 6. Update wrangler.toml

Edit `wrangler.toml` (copy dari `wrangler.toml.example` jika belum ada) dan:
- Ganti `YOUR_DATABASE_ID_HERE` dengan database ID yang didapat dari step 3
- Pastikan binding = `"DB"` (bukan `"gempa_db"`)
- Tambahkan `CUACA_WILAYAH` di section `[vars]` jika menggunakan Opsi 1

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

**Dengan environment variables di wrangler.toml:**
```bash
npm run deploy
```

**Dengan environment variables via command:**
```bash
npx wrangler deploy --var SEND_TO_SLACK="true" --var SEND_TO_TELEGRAM="true" --var CUACA_WILAYAH="34.71.01.1001,34.04.07.1001"
```

### View Logs

```bash
npm run tail
```

Log akan menampilkan:
- ✅ Gempa monitoring (setiap 3 menit)
- ✅ Peringatan dini cuaca (setiap 5 menit)
- ✅ Prakiraan cuaca (setiap 60 menit)

## Konfigurasi Cron Trigger

Cron trigger dikonfigurasi di `wrangler.toml`:

```toml
[triggers]
crons = ["*/3 * * * *"]  # Setiap 3 menit
```

**Catatan**: Cloudflare Workers Cron minimum adalah 1 menit.

### Interval Internal (di dalam kode)

Worker dipanggil setiap 3 menit, tapi fitur dijalankan dengan interval berbeda:

| Fitur | Interval | Keterangan |
|-------|----------|------------|
| **Gempa** | Setiap 3 menit | Selalu dijalankan setiap cron trigger |
| **Peringatan Dini** | Setiap 5 menit | Dijalankan saat `minute % 5 === 0` |
| **Prakiraan Cuaca** | Setiap 60 menit | Dijalankan saat `minute % 60 === 0` |

**Format cron**: `minute hour day month weekday`

Contoh:
- `*/3 * * * *` - Setiap 3 menit (default)
- `*/5 * * * *` - Setiap 5 menit
- `0 * * * *` - Setiap jam
- `0 */6 * * *` - Setiap 6 jam

**Mengubah Interval Internal:**

Edit konstanta di `src/index.js`:
```javascript
const WARNING_INTERVAL_MIN = 5;  // Ubah untuk peringatan dini
const WEATHER_INTERVAL_MIN = 60; // Ubah untuk prakiraan cuaca
```

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
- Pastikan binding = `"DB"` (bukan `"gempa_db"`)
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

### Prakiraan cuaca tidak jalan
- Pastikan `CUACA_WILAYAH` sudah di-set (cek dengan `wrangler tail`)
- Pastikan kode wilayah (adm4) valid (lihat `KODE_WILAYAH_YOGYAKARTA.md`)
- Cek format: comma-separated, tanpa spasi (contoh: `34.71.01.1001,34.04.07.1001`)
- Prakiraan cuaca hanya jalan setiap 60 menit (saat `minute % 60 === 0`)

### Peringatan dini tidak jalan
- Peringatan dini hanya jalan setiap 5 menit (saat `minute % 5 === 0`)
- Cek logs untuk error RSS parsing
- Pastikan endpoint BMKG masih accessible

### Error "DATA_URLS is not defined"
- Pastikan sudah deploy versi terbaru (sudah diubah ke `GEMPA_URLS`)
- Redeploy dengan `npm run deploy`

### Error "Cannot read properties of undefined (reading 'duration')"
- Pastikan binding D1 = `"DB"` di `wrangler.toml`
- Redeploy worker setelah update binding

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

### Perhitungan Request per Hari:

| Fitur | Interval | Request per Hari |
|-------|----------|------------------|
| Gempa (3 feed) | Setiap 3 menit | ~1,440 requests |
| Peringatan Dini | Setiap 5 menit | ~288 requests |
| Prakiraan Cuaca (6 wilayah) | Setiap 60 menit | ~144 requests |
| **Total** | | **~1,872 requests/hari** |

✅ **Masih dalam free tier!** (100K requests/hari)

D1 Database pricing:
- **Free Tier**: 5GB storage, 5M reads/hari, 100K writes/hari
- **Paid Tier**: $0.001 per 1M reads, $1.00 per 1M writes

**Catatan**: Jika menambah banyak wilayah cuaca, pertimbangkan untuk mengurangi interval atau jumlah wilayah untuk tetap dalam free tier.

## License

MIT License - sama dengan project utama
