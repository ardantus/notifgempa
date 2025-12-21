# Rencana Implementasi - Prakiraan Cuaca & Peringatan Dini

Dokumen ini menjelaskan rencana implementasi untuk menambahkan fitur monitoring Prakiraan Cuaca dan Peringatan Dini Cuaca ke aplikasi gempa monitor.

## 🎯 Tujuan

Menambahkan fitur monitoring:
1. **Prakiraan Cuaca** - Monitoring cuaca untuk wilayah tertentu
2. **Peringatan Dini Cuaca** - Monitoring peringatan dini cuaca real-time

## 📋 Fitur yang Akan Ditambahkan

### 1. Prakiraan Cuaca Monitor

**Fitur:**
- Monitor prakiraan cuaca untuk wilayah tertentu (berdasarkan kode adm4)
- Notifikasi jika ada perubahan kondisi cuaca ekstrem
- Simpan history prakiraan cuaca ke database
- Support multiple wilayah monitoring

**Data yang Dimonitor:**
- Suhu udara (t)
- Kelembapan (hu)
- Kondisi cuaca (weather_desc)
- Kecepatan angin (ws)
- Arah angin (wd)
- Tutupan awan (tcc)
- Jarak pandang (vs_text)

**Trigger Notifikasi:**
- Cuaca ekstrem (hujan lebat, angin kencang, dll)
- Perubahan suhu drastis
- Kelembapan tinggi (>90%)
- Angin kencang (>40 km/jam)

### 2. Peringatan Dini Cuaca Monitor

**Fitur:**
- Monitor RSS feed peringatan dini cuaca
- Notifikasi real-time saat ada peringatan baru
- Simpan peringatan ke database
- Filter peringatan berdasarkan provinsi/wilayah
- Support multiple bahasa (ID/EN)

**Data yang Dimonitor:**
- Event type (jenis peringatan)
- Effective time (waktu mulai)
- Expires time (waktu berakhir)
- Headline (judul)
- Description (deskripsi wilayah)
- Area (polygon wilayah terdampak)

**Trigger Notifikasi:**
- Peringatan baru muncul di RSS feed
- Peringatan untuk wilayah yang dimonitor
- Peringatan dengan severity tinggi

## 🏗️ Arsitektur

### Database Schema

#### Tabel: `prakiraan_cuaca`

```sql
CREATE TABLE IF NOT EXISTS prakiraan_cuaca (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kode_wilayah TEXT,
  utc_datetime TEXT,
  local_datetime TEXT,
  suhu REAL,
  kelembapan INTEGER,
  kondisi_cuaca TEXT,
  kondisi_cuaca_en TEXT,
  kecepatan_angin REAL,
  arah_angin TEXT,
  tutupan_awan INTEGER,
  jarak_pandang TEXT,
  analysis_date TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(kode_wilayah, utc_datetime)
);
```

#### Tabel: `peringatan_dini_cuaca`

```sql
CREATE TABLE IF NOT EXISTS peringatan_dini_cuaca (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  identifier TEXT UNIQUE,
  event TEXT,
  effective TEXT,
  expires TEXT,
  sender_name TEXT,
  headline TEXT,
  description TEXT,
  web_url TEXT,
  area_desc TEXT,
  provinsi TEXT,
  status TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

### Struktur Kode

#### PHP Version

```
app/
├── gempa_monitor.php (existing)
├── cuaca_monitor.php (new)
│   ├── getPrakiraanCuaca()
│   ├── processPrakiraanCuaca()
│   ├── checkCuacaEkstrem()
│   └── sendCuacaNotification()
└── peringatan_dini_monitor.php (new)
    ├── getPeringatanDiniRSS()
    ├── parseCAPAlert()
    ├── processPeringatanDini()
    └── sendPeringatanDiniNotification()
```

#### Cloudflare Worker Version

```
workergempa/src/
├── index.js (existing - gempa)
├── cuaca.js (new)
│   ├── getPrakiraanCuaca()
│   ├── processPrakiraanCuaca()
│   └── checkCuacaEkstrem()
└── peringatanDini.js (new)
    ├── getPeringatanDiniRSS()
    ├── parseCAPAlert()
    └── processPeringatanDini()
```

## 🔄 Flow Proses

### Prakiraan Cuaca

```
1. Fetch data dari API BMKG
   ↓
2. Parse JSON response
   ↓
3. Check apakah data baru (cek database)
   ↓
4. Simpan ke database
   ↓
5. Check kondisi ekstrem
   ↓
6. Jika ekstrem → Kirim notifikasi
```

### Peringatan Dini

```
1. Fetch RSS feed dari BMKG
   ↓
2. Parse RSS XML
   ↓
3. Extract item links
   ↓
4. Fetch CAP XML untuk setiap item
   ↓
5. Parse CAP XML
   ↓
6. Check apakah peringatan baru (cek database)
   ↓
7. Simpan ke database
   ↓
8. Filter berdasarkan wilayah
   ↓
9. Kirim notifikasi
```

## ⚙️ Konfigurasi

### Environment Variables (Baru)

```env
# Prakiraan Cuaca
MONITOR_CUACA=true
# Contoh kode wilayah DIY Yogyakarta (comma-separated):
# Kota Yogyakarta: 34.71.01.1001 (Gondomanan), 34.71.03.1003 (Danurejan)
# Sleman: 34.04.07.1001 (Caturtunggal), 34.04.09.2003 (Pakem)
# Bantul: 34.02.01.2001 (Bantul), 34.02.07.2002 (Parangtritis)
# Gunung Kidul: 34.03.01.1001 (Wonosari)
# Kulon Progo: 34.01.01.1001 (Wates)
CUACA_WILAYAH=34.71.01.1001,34.71.03.1003,34.04.07.1001,34.02.01.2001,34.03.01.1001,34.01.01.1001
CUACA_CHECK_INTERVAL=3600  # 1 jam dalam detik
CUACA_NOTIFY_EXTREME=true

# Peringatan Dini
MONITOR_PERINGATAN_DINI=true
PERINGATAN_DINI_PROVINSI=Yogyakarta  # Untuk DIY Yogyakarta
PERINGATAN_DINI_CHECK_INTERVAL=300  # 5 menit dalam detik
PERINGATAN_DINI_LANG=id  # id atau en
```

## 📅 Scheduling

### PHP Version

```php
// Tambah ke cron atau separate process
// Prakiraan Cuaca: Setiap 1 jam
// Peringatan Dini: Setiap 5 menit
```

### Cloudflare Worker

```toml
# wrangler.toml
triggers = { 
  crons = [
    "*/3 * * * *",  # Gempa (existing)
    "0 * * * *",    # Prakiraan Cuaca (setiap jam)
    "*/5 * * * *"   # Peringatan Dini (setiap 5 menit)
  ] 
}
```

## 🔔 Format Notifikasi

### Prakiraan Cuaca - Slack

```json
{
  "blocks": [
    {
      "type": "section",
      "text": {
        "type": "mrkdwn",
        "text": "🌤️ *Prakiraan Cuaca Update*"
      }
    },
    {
      "type": "section",
      "fields": [
        {"type": "mrkdwn", "text": "*Wilayah:*\nGondomanan, Yogyakarta"},
        {"type": "mrkdwn", "text": "*Suhu:*\n28°C"},
        {"type": "mrkdwn", "text": "*Cuaca:*\nHujan Lebat"},
        {"type": "mrkdwn", "text": "*Kelembapan:*\n85%"},
        {"type": "mrkdwn", "text": "*Angin:*\n15 km/jam (Barat)"},
        {"type": "mrkdwn", "text": "*Waktu:*\n2024-01-01 07:00:00 WIB"}
      ]
    }
  ]
}
```

### Peringatan Dini - Telegram

```
⚠️ *Peringatan Dini Cuaca*

*Event:* Hujan Lebat Disertai Angin Kencang
*Wilayah:* Sleman, Kulon Progo
*Waktu Mulai:* 01 Jan 2024, 07:00 WIB
*Waktu Berakhir:* 01 Jan 2024, 10:00 WIB

*Deskripsi:*
Hujan lebat disertai angin kencang berpotensi terjadi di wilayah Kabupaten Sleman (Tempel, Turi, Pakem) dan Kabupaten Kulon Progo (Samigaluh, Kalibawang).

[Lihat Detail](https://www.bmkg.go.id/infografis/nowcast/yogyakarta)
```

## 🚀 Implementasi Bertahap

### Phase 1: Prakiraan Cuaca (Basic)
- [ ] Setup database schema
- [ ] Implementasi fetch & parse JSON
- [ ] Implementasi save ke database
- [ ] Basic notification

### Phase 2: Prakiraan Cuaca (Advanced)
- [ ] Deteksi kondisi ekstrem
- [ ] Multi-wilayah support
- [ ] History tracking
- [ ] Comparison dengan data sebelumnya

### Phase 3: Peringatan Dini (Basic)
- [ ] Setup database schema
- [ ] Implementasi fetch RSS
- [ ] Implementasi parse CAP XML
- [ ] Basic notification

### Phase 4: Peringatan Dini (Advanced)
- [ ] Filter berdasarkan provinsi
- [ ] Multi-bahasa support
- [ ] Polygon area parsing
- [ ] Expiry tracking

### Phase 5: Integration & Testing
- [ ] Integrasi dengan existing code
- [ ] Unit testing
- [ ] Integration testing
- [ ] Documentation

## 📊 Monitoring & Logging

### Metrics yang Perlu Dimonitor

1. **API Response Time**
   - Prakiraan Cuaca API
   - Peringatan Dini RSS
   - CAP XML fetch

2. **Data Quality**
   - Success rate fetch
   - Parse error rate
   - Duplicate detection rate

3. **Notification**
   - Notification sent count
   - Notification failed count
   - Notification delivery time

### Logging

```php
// PHP
log_message('INFO', "Prakiraan cuaca fetched for wilayah: $kodeWilayah");
log_message('WARNING', "Cuaca ekstrem detected: $kondisi");
log_message('ERROR', "Failed to fetch prakiraan cuaca: $error");
```

```javascript
// JavaScript
logMessage('INFO', `Peringatan dini fetched: ${identifier}`);
logMessage('WARNING', `New alert detected: ${headline}`);
logMessage('ERROR', `Failed to parse CAP XML: ${error}`);
```

## 🧪 Testing

### Test Cases

1. **Prakiraan Cuaca**
   - Test fetch dengan kode wilayah valid
   - Test fetch dengan kode wilayah invalid
   - Test parse JSON response
   - Test deteksi kondisi ekstrem
   - Test notification format

2. **Peringatan Dini**
   - Test fetch RSS feed
   - Test parse RSS XML
   - Test fetch CAP XML
   - Test parse CAP XML
   - Test filter provinsi
   - Test notification format

3. **Integration**
   - Test dengan existing gempa monitor
   - Test concurrent execution
   - Test database conflicts
   - Test rate limiting

## 📝 Notes

1. **Rate Limiting**: BMKG membatasi 60 request/menit per IP. Perlu implementasi rate limiting.
2. **Error Handling**: Handle network errors, parse errors, dan API errors dengan baik.
3. **Data Validation**: Validasi semua data sebelum save ke database.
4. **Backward Compatibility**: Pastikan tidak break existing functionality.

---

**Status**: Planning Phase
**Last Updated**: 21 Desember 2024
