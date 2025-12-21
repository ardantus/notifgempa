# Changelog - Perbaikan dan Optimasi

## Perbaikan yang Telah Dilakukan

### 1. ✅ Perbaikan Bug Interval Waktu
- **Masalah**: Komentar mengatakan "15 menit" tapi nilai 200 detik = ~3.3 menit
- **Perbaikan**: Update komentar menjadi "~3.3 menit (200 detik)" untuk akurasi
- **Lokasi**: Line 14 di `app/gempa_monitor.php`

### 2. ✅ Perbaikan fetch_xml_with_retry() - Gunakan cURL
- **Masalah**: Menggunakan `simplexml_load_file()` yang tidak bisa diatur timeout
- **Perbaikan**: 
  - Ganti dengan cURL yang memiliki timeout control
  - Tambah `CURLOPT_TIMEOUT` (30 detik) dan `CURLOPT_CONNECTTIMEOUT` (10 detik)
  - Tambah validasi HTTP status code
  - Tambah error handling untuk XML parsing
- **Lokasi**: Function `fetch_xml_with_retry()` di `app/gempa_monitor.php`

### 3. ✅ Tambah Timeout pada Semua cURL Requests
- **Masalah**: Tidak ada timeout pada cURL requests untuk Slack dan Telegram
- **Perbaikan**:
  - Tambah `CURLOPT_TIMEOUT` (30 detik)
  - Tambah `CURLOPT_CONNECTTIMEOUT` (10 detik)
  - Tambah `CURLOPT_SSL_VERIFYPEER` untuk keamanan
- **Lokasi**: Functions `send_slack_notification()` dan `send_telegram_notification()`

### 4. ✅ Validasi Environment Variables
- **Masalah**: Tidak ada validasi saat startup, bisa crash jika config salah
- **Perbaikan**:
  - Tambah function `validate_config()` yang cek semua required variables
  - Validasi dilakukan sebelum aplikasi mulai
  - Exit dengan error code jika validasi gagal
- **Lokasi**: Function `validate_config()` dan main logic

### 5. ✅ Escape Markdown untuk Keamanan Telegram
- **Masalah**: Markdown injection vulnerability pada pesan Telegram
- **Perbaikan**:
  - Tambah function `escape_markdown()` yang escape semua karakter berbahaya
  - Semua field di pesan Telegram di-escape sebelum dikirim
- **Lokasi**: Function `escape_markdown()` dan `send_telegram_notification()`

### 6. ✅ Graceful Shutdown Handler
- **Masalah**: Loop `while(true)` tidak handle signal SIGTERM/SIGINT
- **Perbaikan**:
  - Tambah signal handler untuk SIGTERM dan SIGINT
  - Tambah flag `$shutdown_requested` untuk kontrol graceful shutdown
  - Check flag di setiap iterasi loop
  - Log shutdown message
- **Lokasi**: Function `shutdown_handler()` dan main loop

### 7. ✅ Refactor Duplikasi Kode
- **Masalah**: 3 functions (`process_autogempa`, `process_gempaterkini`, `process_gempadirasakan`) sangat mirip
- **Perbaikan**:
  - Gabung menjadi 1 function generic: `process_gempa_source()`
  - Parameter `$is_single_item` untuk handle autogempa (single) vs lainnya (multiple)
  - Mengurangi ~150 baris duplikasi kode
- **Lokasi**: Function `process_gempa_source()` menggantikan 3 functions lama

### 8. ✅ HTTP Status Code Checking
- **Masalah**: Tidak cek HTTP status code pada cURL responses
- **Perbaikan**:
  - Tambah `curl_getinfo($ch, CURLINFO_HTTP_CODE)` untuk semua requests
  - Validasi status code 200-299 untuk success
  - Log error jika status code tidak valid
- **Lokasi**: Functions `send_slack_notification()`, `send_telegram_notification()`, dan `fetch_xml_with_retry()`

### 9. ✅ Optimasi Database Operations
- **Masalah**: Double query (SELECT lalu INSERT) untuk cek duplikasi
- **Perbaikan**:
  - Langsung gunakan `INSERT OR IGNORE` tanpa SELECT dulu
  - Cek `lastInsertId()` untuk tahu apakah data baru atau duplicate
  - Lebih efisien dan mengurangi database queries
- **Lokasi**: Function `save_gempa()`

### 10. ✅ Structured Logging dengan Levels
- **Masalah**: Hanya menggunakan `error_log()` tanpa level
- **Perbaikan**:
  - Tambah function `log_message($level, $message)` dengan levels: INFO, WARNING, ERROR, DEBUG
  - Semua log messages menggunakan function ini
  - Format: `[timestamp] [LEVEL] message`
- **Lokasi**: Function `log_message()` dan semua error_log() calls

### 11. ✅ Optimasi Dockerfile
- **Masalah**: 
  - Dua `apt-get update` terpisah (inefficient)
  - Tidak ada `--no-install-recommends`
  - Cleanup tidak optimal
- **Perbaikan**:
  - Gabung semua package installations dalam 1 RUN command
  - Tambah `--no-install-recommends` untuk ukuran image lebih kecil
  - Gabung `docker-php-ext-install` dalam 1 command
  - Tambah `rm -rf /var/lib/apt/lists/*` untuk cleanup
- **Lokasi**: `Dockerfile`

### 12. ✅ Update docker-compose.yml
- **Masalah**:
  - Volume `gempa_data` didefinisikan tapi tidak digunakan
  - Tidak ada healthcheck
  - Tidak ada resource limits
- **Perbaikan**:
  - Hapus unused volume `gempa_data`
  - Tambah healthcheck yang cek keberadaan database file
  - Tambah resource limits (memory: 256M limit, 128M reservation)
- **Lokasi**: `docker-compose.yml`

### 13. ✅ Buat .env.example
- **Masalah**: README menyebut `.env.example` tapi file tidak ada
- **Perbaikan**: Buat file `env.example` dengan template semua environment variables
- **Lokasi**: `env.example`

### 14. ✅ Perbaiki Error Handling
- **Masalah**: Inconsistent error handling (ada yang return false, ada yang throw exception)
- **Perbaikan**:
  - Konsisten menggunakan return false untuk non-fatal errors
  - Throw exception hanya untuk fatal errors
  - Semua errors di-log dengan level yang sesuai
- **Lokasi**: Semua functions

### 15. ✅ Hapus Parameter Tidak Digunakan
- **Masalah**: Parameter `$is_first_run` diteruskan tapi tidak pernah digunakan
- **Perbaikan**: Hapus parameter dari semua function signatures
- **Lokasi**: Functions `process_gempa_source()` dan `process_gempa_data()`

## Perbaikan Tambahan

### 16. ✅ Tambah Konstanta Timeout
- Tambah `$HTTP_TIMEOUT` dan `$HTTP_CONNECT_TIMEOUT` sebagai konstanta
- Memudahkan konfigurasi timeout di satu tempat

### 17. ✅ Perbaiki Normalisasi Datetime
- Tambah validasi empty string sebelum parsing
- Return null lebih cepat jika input kosong

### 18. ✅ Perbaiki Error Messages
- Semua error messages lebih informatif
- Include context (source, datetime, dll) dalam error messages

### 19. ✅ Tambah XML Error Handling
- Gunakan `libxml_use_internal_errors()` untuk handle XML parsing errors
- Clear errors setelah digunakan

### 20. ✅ Perbaiki Database Error Handling
- Handle UNIQUE constraint error secara khusus (bukan fatal error)
- Log database errors dengan level ERROR

## Statistik Perbaikan

- **Total Issues Fixed**: 20
- **Lines of Code Reduced**: ~150 (dari refactoring)
- **New Functions Added**: 3 (`validate_config`, `log_message`, `escape_markdown`, `shutdown_handler`)
- **Functions Removed**: 2 (`process_autogempa`, `process_gempaterkini`, `process_gempadirasakan`)
- **Functions Refactored**: 1 (`process_gempa_source` menggantikan 3 functions)

## Testing Recommendations

1. Test dengan environment variables yang tidak valid
2. Test dengan network timeout scenarios
3. Test graceful shutdown dengan SIGTERM
4. Test dengan data gempa yang memiliki karakter Markdown berbahaya
5. Test dengan database yang sudah ada data duplicate
6. Test healthcheck di Docker

## Breaking Changes

Tidak ada breaking changes. Semua perubahan backward compatible.
