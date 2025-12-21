# Dokumentasi API BMKG - Prakiraan Cuaca & Peringatan Dini

Dokumentasi lengkap untuk mengakses data Prakiraan Cuaca dan Peringatan Dini Cuaca dari BMKG.

## 📋 Daftar Isi

1. [Prakiraan Cuaca](#prakiraan-cuaca)
2. [Peringatan Dini Cuaca (Nowcast)](#peringatan-dini-cuaca-nowcast)
3. [Batas Akses](#batas-akses)
4. [Contoh Implementasi](#contoh-implementasi)

---

## 🌤️ Prakiraan Cuaca

### Deskripsi
Data prakiraan cuaca untuk kelurahan dan desa di Indonesia dalam waktu 3 harian. Data diperbarui dua kali sehari dengan interval prakiraan setiap 3 jam.

### Endpoint

```
https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4={kode_wilayah_tingkat_iv}
```

**Parameter:**
- `adm4`: Kode wilayah administrasi tingkat IV (kelurahan/desa) sesuai Keputusan Menteri Dalam Negeri Nomor 100.1.1-6117 Tahun 2022

### Format Data
**JSON** (default)

### Contoh Request

```bash
# Kota Yogyakarta - Gondomanan (kode: 34.71.01.1001)
curl https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=34.71.01.1001

# Sleman - Depok (kode: 34.04.07.1001)
curl https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=34.04.07.1001

# Bantul (kode: 34.02.01.2001)
curl https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=34.02.01.2001
```

### Struktur Response JSON

```json
{
  "utc_datetime": "2024-01-01 00:00:00",
  "local_datetime": "2024-01-01 07:00:00",
  "t": 28,
  "hu": 75,
  "weather_desc": "Berawan",
  "weather_desc_en": "Partly Cloudy",
  "ws": 10,
  "wd": "Barat",
  "tcc": 50,
  "vs_text": "10 km",
  "analysis_date": "2024-01-01T00:00:00"
}
```

### Parameter Data

| Parameter | Deskripsi | Unit/Format |
|-----------|-----------|-------------|
| `utc_datetime` | Waktu dalam UTC | YYYY-MM-DD HH:mm:ss |
| `local_datetime` | Waktu lokal (WIB) | YYYY-MM-DD HH:mm:ss |
| `t` | Suhu udara | °C |
| `hu` | Kelembapan udara | % |
| `weather_desc` | Kondisi cuaca (ID) | String |
| `weather_desc_en` | Kondisi cuaca (EN) | String |
| `ws` | Kecepatan angin | km/jam |
| `wd` | Arah angin | String |
| `tcc` | Tutupan awan | % |
| `vs_text` | Jarak pandang | km |
| `analysis_date` | Waktu produksi data | YYYY-MM-DDTHH:mm:ss (UTC) |

### Cara Mencari Kode Wilayah (adm4)

Kode wilayah tingkat IV dapat ditemukan di:
- Portal data BMKG: https://data.bmkg.go.id/prakiraan-cuaca/
- Keputusan Menteri Dalam Negeri Nomor 100.1.1-6117 Tahun 2022

Format kode: `XX.XX.XX.XXXX` (contoh: `31.71.03.1001`)

---

## ⚠️ Peringatan Dini Cuaca (Nowcast)

### Deskripsi
Data peringatan dini cuaca (nowcast) hingga tingkat kecamatan dalam format XML berbasis **Common Alerting Protocol (CAP)**. Data diperbarui secara real-time.

### Endpoint RSS Feed

**Bahasa Indonesia:**
```
https://www.bmkg.go.id/alerts/nowcast/id
```

**Bahasa Inggris:**
```
https://www.bmkg.go.id/alerts/nowcast/en
```

### Format Data
**XML (RSS Feed)** dan **XML (CAP)**

### Struktur RSS Feed

RSS feed berisi daftar peringatan dini cuaca aktif dengan struktur:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Peringatan Dini Cuaca BMKG</title>
    <link>https://www.bmkg.go.id</link>
    <description>Daftar peringatan dini cuaca aktif</description>
    <item>
      <title>Peringatan Dini Cuaca Provinsi DKI Jakarta</title>
      <link>https://www.bmkg.go.id/alerts/nowcast/id/JAKARTA_alert.xml</link>
      <description>Wilayah terdampak: Jakarta Pusat, Jakarta Utara</description>
      <author>BMKG</author>
      <pubDate>Mon, 01 Jan 2024 07:00:00 WIB</pubDate>
    </item>
  </channel>
</rss>
```

### Parameter RSS Feed

| Parameter | Deskripsi |
|-----------|-----------|
| `title` | Judul peringatan dini cuaca provinsi |
| `link` | Tautan detail CAP tiap provinsi |
| `description` | Deskripsi wilayah terdampak |
| `author` | Pembuat rilis peringatan |
| `pubDate` | Waktu publikasi lokal (RFC 1123) |
| `lastBuildDate` | Waktu pemutakhiran data UTC (RFC 1123) |

### Detail CAP Alert

Untuk mendapatkan detail peringatan, gunakan kode dari RSS feed:

```
https://www.bmkg.go.id/alerts/nowcast/id/{kode_detail_cap}_alert.xml
```

**Contoh:**
```
https://www.bmkg.go.id/alerts/nowcast/id/JAKARTA_alert.xml
```

### Struktur CAP XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>BMKG-NOWCAST-JAKARTA-20240101-001</identifier>
  <sender>BMKG</sender>
  <sent>2024-01-01T00:00:00+00:00</sent>
  <status>Actual</status>
  <msgType>Alert</msgType>
  <scope>Public</scope>
  <info>
    <event>Hujan Lebat</event>
    <effective>2024-01-01T07:00:00+07:00</effective>
    <expires>2024-01-01T10:00:00+07:00</expires>
    <senderName>BMKG</senderName>
    <headline>Peringatan Dini Cuaca Provinsi DKI Jakarta</headline>
    <description>Hujan lebat disertai angin kencang berpotensi terjadi di wilayah Jakarta Pusat dan Jakarta Utara</description>
    <web>https://www.bmkg.go.id/infografis/nowcast/jakarta</web>
    <area>
      <areaDesc>Jakarta Pusat, Jakarta Utara</areaDesc>
      <polygon>...</polygon>
    </area>
  </info>
</alert>
```

### Parameter CAP Nowcast

| Parameter | Deskripsi | Format |
|-----------|-----------|--------|
| `event` | Jenis kejadian peringatan | String |
| `effective` | Waktu mulai peringatan | ISO 8601 |
| `expires` | Waktu berakhir peringatan | ISO 8601 |
| `senderName` | Pembuat rilis peringatan | String |
| `headline` | Judul peringatan provinsi | String |
| `description` | Deskripsi wilayah terdampak | String |
| `web` | Tautan infografik peringatan | URL |
| `area` | Polygon wilayah terdampak | GeoJSON/Polygon |

### Jenis Event (Event Types)

- Hujan Lebat
- Hujan Lebat Disertai Angin Kencang
- Hujan Lebat Disertai Petir
- Angin Kencang
- Gelombang Tinggi
- Cuaca Ekstrem

---

## 🚦 Batas Akses

**Rate Limit:** 60 permintaan per menit per IP

**Penting:** Wajib mencantumkan BMKG sebagai sumber data dan menampilkannya pada aplikasi/sistem Anda.

---

## 💻 Contoh Implementasi

### PHP - Prakiraan Cuaca

```php
<?php
function getPrakiraanCuaca($kodeWilayah) {
    $url = "https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=" . $kodeWilayah;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return null;
}

// Contoh penggunaan
$data = getPrakiraanCuaca('34.71.01.1001'); // Gondomanan, Yogyakarta
if ($data) {
    echo "Suhu: " . $data['t'] . "°C\n";
    echo "Cuaca: " . $data['weather_desc'] . "\n";
    echo "Kelembapan: " . $data['hu'] . "%\n";
}
?>
```

### PHP - Peringatan Dini (RSS)

```php
<?php
function getPeringatanDiniRSS($lang = 'id') {
    $url = "https://www.bmkg.go.id/alerts/nowcast/" . $lang;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $xml = simplexml_load_string($response);
        return $xml;
    }
    
    return null;
}

// Contoh penggunaan
$rss = getPeringatanDiniRSS('id');
if ($rss) {
    foreach ($rss->channel->item as $item) {
        echo "Judul: " . $item->title . "\n";
        echo "Link: " . $item->link . "\n";
        echo "Deskripsi: " . $item->description . "\n";
        echo "---\n";
    }
}
?>
```

### JavaScript/Node.js - Prakiraan Cuaca

```javascript
async function getPrakiraanCuaca(kodeWilayah) {
  const url = `https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=${kodeWilayah}`;
  
  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: {
        'User-Agent': 'GempaMonitor/1.0'
      }
    });
    
    if (response.ok) {
      return await response.json();
    }
    
    return null;
  } catch (error) {
    console.error('Error fetching prakiraan cuaca:', error);
    return null;
  }
}

// Contoh penggunaan
const data = await getPrakiraanCuaca('34.71.01.1001'); // Gondomanan, Yogyakarta
if (data) {
  console.log(`Suhu: ${data.t}°C`);
  console.log(`Cuaca: ${data.weather_desc}`);
  console.log(`Kelembapan: ${data.hu}%`);
}
```

### JavaScript - Peringatan Dini (RSS)

```javascript
async function getPeringatanDiniRSS(lang = 'id') {
  const url = `https://www.bmkg.go.id/alerts/nowcast/${lang}`;
  
  try {
    const response = await fetch(url);
    if (response.ok) {
      const xmlText = await response.text();
      const parser = new DOMParser();
      const xmlDoc = parser.parseFromString(xmlText, 'text/xml');
      return xmlDoc;
    }
    
    return null;
  } catch (error) {
    console.error('Error fetching peringatan dini:', error);
    return null;
  }
}

// Contoh penggunaan
const rss = await getPeringatanDiniRSS('id');
if (rss) {
  const items = rss.querySelectorAll('item');
  items.forEach(item => {
    const title = item.querySelector('title')?.textContent;
    const link = item.querySelector('link')?.textContent;
    console.log(`Judul: ${title}`);
    console.log(`Link: ${link}`);
  });
}
```

---

## 📚 Referensi

- **Portal Data BMKG**: https://data.bmkg.go.id/
- **Prakiraan Cuaca**: https://data.bmkg.go.id/prakiraan-cuaca/
- **Peringatan Dini Cuaca**: https://data.bmkg.go.id/peringatan-dini-cuaca/
- **GitHub BMKG - Data CAP**: https://github.com/infoBMKG/data-cap
- **GitHub BMKG - Data Gempabumi**: https://github.com/infoBMKG/data-gempabumi

---

## ⚠️ Catatan Penting

1. **Sumber Data**: Wajib mencantumkan BMKG sebagai sumber data
2. **Rate Limiting**: Maksimal 60 request per menit per IP
3. **Update Frequency**: 
   - Prakiraan Cuaca: 2x sehari
   - Peringatan Dini: Real-time
4. **Format Data**: 
   - Prakiraan Cuaca: JSON
   - Peringatan Dini: XML (RSS + CAP)

---

**Terakhir Diperbarui**: 21 Desember 2024
