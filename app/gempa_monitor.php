<?php
// Konfigurasi
$SLACK_WEBHOOK = getenv('SLACK_WEBHOOK') ?: '';
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$TELEGRAM_CHAT_ID = getenv('TELEGRAM_CHAT_ID') ?: '';
$SEND_TO_SLACK = filter_var(getenv('SEND_TO_SLACK'), FILTER_VALIDATE_BOOLEAN);
$SEND_TO_TELEGRAM = filter_var(getenv('SEND_TO_TELEGRAM'), FILTER_VALIDATE_BOOLEAN);
$GEMPA_URLS = [
    'gempaterkini' => 'https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.json',
    'autogempa' => 'https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json',
    'gempadirasakan' => 'https://data.bmkg.go.id/DataMKG/TEWS/gempadirasakan.json'
];
$WARNING_RSS_URL = 'https://www.bmkg.go.id/alerts/nowcast/id';
$WEATHER_BASE_URL = 'https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=';
$CUACA_WILAYAH = getenv('CUACA_WILAYAH') ?: ''; // Kode wilayah adm4 (comma-separated)

$DB_FILE = '/data/gempa.db';
$CHECK_INTERVAL = 200; // ~3.3 menit (200 detik)
$RETRY_ATTEMPTS = 3; // Jumlah percobaan ulang
$RETRY_DELAY = 5; // Jeda antar percobaan (detik)
$MAX_AGE_HOURS = 24; // Hanya notifikasi gempa dalam 24 jam terakhir
$HTTP_TIMEOUT = 30; // Timeout untuk HTTP requests (detik)
$HTTP_CONNECT_TIMEOUT = 10; // Timeout untuk koneksi HTTP (detik)
$WARNING_INTERVAL_SEC = 300; // Setiap 5 menit (300 detik)
$WEATHER_INTERVAL_SEC = 3600; // Setiap 60 menit (3600 detik)

// Flag untuk graceful shutdown
$shutdown_requested = false;

// Fungsi logging dengan level
function log_message($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [$level] $message");
}

// Validasi environment variables
function validate_config() {
    global $SEND_TO_SLACK, $SEND_TO_TELEGRAM, $SLACK_WEBHOOK, $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID;
    
    if ($SEND_TO_SLACK && empty($SLACK_WEBHOOK)) {
        log_message('ERROR', 'SEND_TO_SLACK is enabled but SLACK_WEBHOOK is empty');
        return false;
    }
    
    if ($SEND_TO_TELEGRAM) {
        if (empty($TELEGRAM_BOT_TOKEN)) {
            log_message('ERROR', 'SEND_TO_TELEGRAM is enabled but TELEGRAM_BOT_TOKEN is empty');
            return false;
        }
        if (empty($TELEGRAM_CHAT_ID)) {
            log_message('ERROR', 'SEND_TO_TELEGRAM is enabled but TELEGRAM_CHAT_ID is empty');
            return false;
        }
    }
    
    return true;
}

// Graceful shutdown handler
function shutdown_handler($signal) {
    global $shutdown_requested;
    $shutdown_requested = true;
    log_message('INFO', "Received signal $signal, shutting down gracefully...");
}

// Setup signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'shutdown_handler');
    pcntl_signal(SIGINT, 'shutdown_handler');
}

// Inisialisasi database SQLite
function init_db() {
    global $DB_FILE;
    try {
        $db = new PDO("sqlite:$DB_FILE");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $db->exec("CREATE TABLE IF NOT EXISTS gempa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            datetime TEXT,
            tanggal TEXT,
            jam TEXT,
            magnitude TEXT,
            kedalaman TEXT,
            wilayah TEXT,
            lintang TEXT,
            bujur TEXT,
            coordinates TEXT,
            potensi TEXT,
            dirasakan TEXT,
            shakemap TEXT,
            source TEXT,
            UNIQUE(datetime, source)
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS warning (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT,
            title TEXT,
            link TEXT,
            pubDate TEXT,
            description TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(identifier)
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS cuaca (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            adm4 TEXT,
            analysis_date TEXT,
            local_datetime TEXT,
            utc_datetime TEXT,
            suhu REAL,
            kelembapan REAL,
            cuaca TEXT,
            cuaca_en TEXT,
            angin_kecepatan REAL,
            angin_arah TEXT,
            tutupan_awan REAL,
            jarak_pandang TEXT,
            payload TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(adm4, local_datetime)
        )");
        
        log_message('INFO', 'Database initialized successfully');
        return $db;
    } catch (PDOException $e) {
        log_message('ERROR', 'Database initialization failed: ' . $e->getMessage());
        throw $e;
    }
}

// Escape Markdown untuk Telegram
function escape_markdown($text) {
    if (empty($text)) {
        return '';
    }
    // Escape karakter Markdown yang berbahaya
    return str_replace(
        ['*', '_', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
        ['\\*', '\\_', '\\[', '\\]', '\\(', '\\)', '\\~', '\\`', '\\>', '\\#', '\\+', '\\-', '\\=', '\\|', '\\{', '\\}', '\\.', '\\!'],
        $text
    );
}

// Fungsi untuk kirim notifikasi Slack (support gempa dan warning)
function send_slack_notification($data, $source) {
    global $SLACK_WEBHOOK, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    if (empty($SLACK_WEBHOOK)) {
        log_message('WARNING', 'Slack webhook not configured, skipping notification');
        return false;
    }
    
    // Handle warning notification
    if ($source === 'warning' && isset($data['message'])) {
        $message = [
            'blocks' => [
                [
                    "type" => "section",
                    "text" => [
                        "type" => "mrkdwn",
                        "text" => $data['message']
                    ]
                ]
            ]
        ];
    } else {
        // Handle gempa notification
        $gempa = $data;
        $title = $source === 'autogempa' ? "Gempa Terbaru" : 
                 ($source === 'gempaterkini' ? "Gempa Terkini" : "Gempa Dirasakan");
        
        $message = [
            'blocks' => [
                [
                    "type" => "section",
                    "text" => [
                        "type" => "mrkdwn",
                        "text" => "🚨 *{$title} Terdeteksi!* 🚨"
                    ]
                ],
                [
                    "type" => "section",
                    "fields" => [
                        [
                            "type" => "mrkdwn",
                            "text" => "*Magnitudo:*\nM{$gempa['magnitude']}"
                        ],
                        [
                            "type" => "mrkdwn",
                            "text" => "*Kedalaman:*\n{$gempa['kedalaman']}"
                        ],
                        [
                            "type" => "mrkdwn",
                            "text" => "*Lokasi:*\n{$gempa['wilayah']}"
                        ],
                        [
                            "type" => "mrkdwn",
                            "text" => "*Waktu:*\n{$gempa['datetime']}"
                        ],
                        [
                            "type" => "mrkdwn",
                            "text" => "*Potensi:*\n" . ($gempa['potensi'] ?: 'N/A')
                        ],
                        [
                            "type" => "mrkdwn",
                            "text" => "*Dirasakan:*\n" . ($gempa['dirasakan'] ?: 'N/A')
                        ]
                    ]
                ]
            ]
        ];

        if ($source === 'autogempa' && !empty($gempa['shakemap'])) {
            $message['blocks'][] = [
                "type" => "image",
                "image_url" => "https://data.bmkg.go.id/DataMKG/TEWS/{$gempa['shakemap']}",
                "alt_text" => "Shakemap Gempa"
            ];
        }
    }

    $ch = curl_init($SLACK_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => $HTTP_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        log_message('ERROR', "Slack notification failed: $curl_error");
        return false;
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        if ($source === 'warning') {
            log_message('INFO', "Slack notification sent for warning");
        } else {
            $datetime_str = isset($data['datetime']) ? $data['datetime'] : 'unknown';
            log_message('INFO', "Slack notification sent for $datetime_str from $source");
        }
        return true;
    } else {
        log_message('ERROR', "Slack notification failed with HTTP $http_code: $response");
        return false;
    }
}

// Fungsi untuk kirim notifikasi Telegram (support gempa dan warning)
function send_telegram_notification($data, $source) {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    if (empty($TELEGRAM_BOT_TOKEN) || empty($TELEGRAM_CHAT_ID)) {
        log_message('WARNING', 'Telegram credentials not configured, skipping notification');
        return false;
    }
    
    // Handle warning notification
    if ($source === 'warning' && isset($data['message'])) {
        $message = $data['message'];
    } else {
        // Handle gempa notification
        $gempa = $data;
        $title = $source === 'autogempa' ? "Gempa Terbaru" : 
                 ($source === 'gempaterkini' ? "Gempa Terkini" : "Gempa Dirasakan");
        
        // Escape semua field untuk keamanan Markdown
        $magnitude = escape_markdown($gempa['magnitude']);
        $kedalaman = escape_markdown($gempa['kedalaman']);
        $wilayah = escape_markdown($gempa['wilayah']);
        $datetime = escape_markdown($gempa['datetime']);
        $potensi = escape_markdown($gempa['potensi'] ?: 'N/A');
        $dirasakan = escape_markdown($gempa['dirasakan'] ?: 'N/A');
        
        $message = "🚨 *" . escape_markdown($title) . " Terdeteksi!* 🚨\n\n" .
                   "*Magnitudo:* M{$magnitude}\n" .
                   "*Kedalaman:* {$kedalaman}\n" .
                   "*Lokasi:* {$wilayah}\n" .
                   "*Waktu:* {$datetime}\n" .
                   "*Potensi:* {$potensi}\n" .
                   "*Dirasakan:* {$dirasakan}\n";

        if ($source === 'autogempa' && !empty($gempa['shakemap'])) {
            $shakemap_url = "https://data.bmkg.go.id/DataMKG/TEWS/{$gempa['shakemap']}";
            $message .= "*Shakemap:* {$shakemap_url}\n";
        }
    }

    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";
    $data = [
        'chat_id' => $TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => $HTTP_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        log_message('ERROR', "Telegram notification failed: $curl_error");
        return false;
    }
    
    $result = json_decode($response, true);
    if ($http_code >= 200 && $http_code < 300 && isset($result['ok']) && $result['ok']) {
        if ($source === 'warning') {
            log_message('INFO', "Telegram notification sent for warning");
        } else {
            $datetime_str = isset($data['datetime']) ? $data['datetime'] : 'unknown';
            log_message('INFO', "Telegram notification sent for $datetime_str from $source");
        }
        return true;
    } else {
        $error_msg = isset($result['description']) ? $result['description'] : "HTTP $http_code";
        log_message('ERROR', "Telegram API error: $error_msg");
        return false;
    }
}

// Fungsi untuk normalisasi datetime
function normalize_datetime($datetime) {
    if (empty($datetime)) {
        return null;
    }
    
    try {
        // Coba beberapa format yang mungkin dari BMKG
        $formats = [
            DateTime::ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'd M Y H:i:s T'
        ];
        
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $datetime);
            if ($dt !== false) {
                return $dt->format(DateTime::ATOM);
            }
        }
        
        // Jika tidak ada format yang cocok, coba parse tanpa format spesifik
        $dt = new DateTime($datetime);
        return $dt->format(DateTime::ATOM);
    } catch (Exception $e) {
        log_message('WARNING', "Failed to normalize datetime: $datetime - " . $e->getMessage());
        return null;
    }
}

// Fungsi untuk memeriksa apakah gempa cukup baru
function is_recent_gempa($datetime) {
    global $MAX_AGE_HOURS;
    try {
        $gempa_time = new DateTime($datetime);
        $now = new DateTime();
        $interval = $now->diff($gempa_time);
        $hours = $interval->h + ($interval->days * 24);
        if ($hours > $MAX_AGE_HOURS) {
            log_message('DEBUG', "Gempa too old: $datetime (age: $hours hours)");
            return false;
        }
        return true;
    } catch (Exception $e) {
        log_message('WARNING', "Failed to check gempa age: $datetime - " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk simpan gempa ke database (optimized - langsung INSERT OR IGNORE)
function save_gempa($db, $gempa, $source) {
    $raw_datetime = isset($gempa->DateTime) ? (string)$gempa->DateTime : null;
    
    if (empty($raw_datetime)) {
        log_message('WARNING', "Empty datetime for gempa from source $source");
        return false;
    }
    
    $datetime = normalize_datetime($raw_datetime);
    if (!$datetime) {
        log_message('WARNING', "Invalid datetime for gempa from source $source: $raw_datetime");
        return false;
    }

    // Langsung INSERT OR IGNORE (lebih efisien daripada cek dulu)
    $stmt = $db->prepare("INSERT OR IGNORE INTO gempa (
        datetime, tanggal, jam, magnitude, kedalaman, wilayah, lintang, bujur, 
        coordinates, potensi, dirasakan, shakemap, source
    ) VALUES (
        :datetime, :tanggal, :jam, :magnitude, :kedalaman, :wilayah, :lintang, :bujur, 
        :coordinates, :potensi, :dirasakan, :shakemap, :source
    )");
    
    $data = [
        ':datetime' => $datetime,
        ':tanggal' => isset($gempa->Tanggal) ? (string)$gempa->Tanggal : null,
        ':jam' => isset($gempa->Jam) ? (string)$gempa->Jam : null,
        ':magnitude' => isset($gempa->Magnitude) ? (string)$gempa->Magnitude : null,
        ':kedalaman' => isset($gempa->Kedalaman) ? (string)$gempa->Kedalaman : null,
        ':wilayah' => isset($gempa->Wilayah) ? (string)$gempa->Wilayah : null,
        ':lintang' => isset($gempa->Lintang) ? (string)$gempa->Lintang : null,
        ':bujur' => isset($gempa->Bujur) ? (string)$gempa->Bujur : null,
        ':coordinates' => isset($gempa->point->coordinates) ? (string)$gempa->point->coordinates : null,
        ':potensi' => isset($gempa->Potensi) ? (string)$gempa->Potensi : null,
        ':dirasakan' => isset($gempa->Dirasakan) ? (string)$gempa->Dirasakan : null,
        ':shakemap' => isset($gempa->Shakemap) ? (string)$gempa->Shakemap : null,
        ':source' => $source
    ];
    
    try {
        $stmt->execute($data);
        $is_new = $db->lastInsertId() > 0;
        if ($is_new) {
            log_message('INFO', "New gempa saved: $datetime from $source");
        }
        return $is_new;
    } catch (PDOException $e) {
        // Jika error karena duplicate, return false (bukan error)
        if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            return false;
        }
        log_message('ERROR', "Database error saving gempa: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk mengambil data JSON dengan retry menggunakan cURL
function fetch_json_with_retry($url, $source) {
    global $RETRY_ATTEMPTS, $RETRY_DELAY, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    for ($attempt = 1; $attempt <= $RETRY_ATTEMPTS; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => $HTTP_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'GempaMonitor/1.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            log_message('WARNING', "Attempt $attempt failed for $source: $curl_error");
        } elseif ($http_code >= 200 && $http_code < 300) {
            $data = json_decode($response, true);
            if ($data !== null) {
                log_message('DEBUG', "Successfully fetched $source data");
                return $data;
            } else {
                log_message('WARNING', "Attempt $attempt failed for $source: Invalid JSON");
            }
        } else {
            log_message('WARNING', "Attempt $attempt failed for $source: HTTP $http_code");
        }
        
        if ($attempt < $RETRY_ATTEMPTS) {
            sleep($RETRY_DELAY);
        }
    }
    
    log_message('ERROR', "$source error: Failed to load $url after $RETRY_ATTEMPTS attempts");
    return false;
}

// Fungsi untuk mengambil data RSS dengan retry menggunakan cURL
function fetch_rss_with_retry($url, $source) {
    global $RETRY_ATTEMPTS, $RETRY_DELAY, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    for ($attempt = 1; $attempt <= $RETRY_ATTEMPTS; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => $HTTP_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'GempaMonitor/1.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            log_message('WARNING', "Attempt $attempt failed for $source: $curl_error");
        } elseif ($http_code >= 200 && $http_code < 300) {
            log_message('DEBUG', "Successfully fetched $source RSS data");
            return $response;
        } else {
            log_message('WARNING', "Attempt $attempt failed for $source: HTTP $http_code");
        }
        
        if ($attempt < $RETRY_ATTEMPTS) {
            sleep($RETRY_DELAY);
        }
    }
    
    log_message('ERROR', "$source error: Failed to load $url after $RETRY_ATTEMPTS attempts");
    return false;
}

// Parse RSS items dari RSS text
function parse_rss_items($rss_text) {
    $items = [];
    if (preg_match_all('/<item>([\s\S]*?)<\/item>/i', $rss_text, $matches)) {
        foreach ($matches[1] as $item_block) {
            $get_tag = function($tag) use ($item_block) {
                if (preg_match("/<$tag>([\s\S]*?)<\/$tag>/i", $item_block, $m)) {
                    return trim($m[1]);
                }
                return '';
            };
            
            $items[] = [
                'title' => $get_tag('title'),
                'link' => $get_tag('link'),
                'description' => $get_tag('description'),
                'pubDate' => $get_tag('pubDate'),
                'identifier' => $get_tag('guid') ?: $get_tag('link')
            ];
        }
    }
    return $items;
}

// Fungsi generic untuk proses data gempa dari berbagai sumber
function process_gempa_source($db, $source, $is_single_item = false) {
    global $GEMPA_URLS, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    $new_gempa = [];
    
    try {
        if (!isset($GEMPA_URLS[$source])) {
            log_message('ERROR', "Unknown source: $source");
            return 0;
        }
        
        $data = fetch_json_with_retry($GEMPA_URLS[$source], $source);
        if ($data === false) {
            throw new Exception("Failed to load data for $source");
        }
        
        // Struktur JSON BMKG: { Infogempa: { gempa: {...} atau [...] } }
        $info = $data['Infogempa'] ?? null;
        if (!$info) {
            throw new Exception("Invalid data structure for $source");
        }
        
        $items = [];
        if ($is_single_item) {
            $gempa = $info['gempa'] ?? null;
            $items = $gempa ? [$gempa] : [];
        } else {
            $list = $info['gempa'] ?? [];
            $items = is_array($list) ? $list : ($list ? [$list] : []);
        }
        
        foreach ($items as $gempa) {
            // Convert array to object-like structure untuk kompatibilitas
            $gempa_obj = (object)$gempa;
            
            if (save_gempa($db, $gempa_obj, $source)) {
                $datetime = normalize_datetime($gempa['DateTime'] ?? '');
                if ($datetime && is_recent_gempa($datetime)) {
                    $gempa_data = [
                        'datetime' => $datetime,
                        'magnitude' => $gempa['Magnitude'] ?? '',
                        'kedalaman' => $gempa['Kedalaman'] ?? '',
                        'wilayah' => $gempa['Wilayah'] ?? '',
                        'potensi' => $source === 'gempadirasakan' ? '' : ($gempa['Potensi'] ?? ''),
                        'dirasakan' => $source === 'gempaterkini' ? '' : ($gempa['Dirasakan'] ?? ''),
                        'shakemap' => $source === 'autogempa' ? ($gempa['Shakemap'] ?? '') : ''
                    ];
                    $new_gempa[] = $gempa_data;
                } else {
                    // Log jika datetime tidak valid atau terlalu lama
                    $raw_dt = $gempa['DateTime'] ?? 'N/A';
                    if (!$datetime) {
                        log_message('DEBUG', "Skipping notification: invalid datetime '$raw_dt' for $source");
                    } else {
                        log_message('DEBUG', "Skipping notification: datetime '$datetime' is too old for $source");
                    }
                }
            }
        }

        // Kirim notifikasi untuk gempa baru
        if (!empty($new_gempa)) {
            foreach ($new_gempa as $gempa) {
                if ($SEND_TO_SLACK) {
                    send_slack_notification($gempa, $source);
                }
                if ($SEND_TO_TELEGRAM) {
                    send_telegram_notification($gempa, $source);
                }
            }
        }
        
        $count = count($new_gempa);
        $source_name = ucfirst($source);
        log_message('INFO', "$source_name checked. New events: $count");
        echo "[" . date('Y-m-d H:i:s') . "] $source_name checked. New events: $count\n";
        return $count;
    } catch (Exception $e) {
        log_message('ERROR', "$source error: " . $e->getMessage());
        return 0;
    }
}

// Fungsi utama untuk proses semua sumber
function process_gempa_data($db) {
    global $shutdown_requested;
    $total_new = 0;
    
    // Process autogempa (single item)
    $total_new += process_gempa_source($db, 'autogempa', true);
    
    if ($shutdown_requested) {
        return $total_new;
    }
    
    sleep(1); // Jeda antar permintaan
    
    // Process gempaterkini (multiple items)
    $total_new += process_gempa_source($db, 'gempaterkini', false);
    
    if ($shutdown_requested) {
        return $total_new;
    }
    
    sleep(1); // Jeda antar permintaan
    
    // Process gempadirasakan (multiple items)
    $total_new += process_gempa_source($db, 'gempadirasakan', false);
    
    log_message('INFO', "Total new events: $total_new");
    echo "[" . date('Y-m-d H:i:s') . "] Total new events: $total_new\n";
    return $total_new;
}

// Simpan peringatan dini ke database
function save_warning($db, $item) {
    $identifier = $item['identifier'] ?: $item['link'] ?: $item['title'];
    
    // Cek dulu apakah data sudah ada
    $check_stmt = $db->prepare("SELECT COUNT(*) FROM warning WHERE identifier = :identifier");
    $check_stmt->execute([':identifier' => $identifier]);
    $exists = $check_stmt->fetchColumn() > 0;
    
    if ($exists) {
        log_message('DEBUG', "Warning already exists: " . ($item['title'] ?? 'Unknown'));
        return false; // Data sudah ada, tidak perlu insert
    }
    
    $stmt = $db->prepare("INSERT INTO warning (identifier, title, link, pubDate, description) VALUES (:identifier, :title, :link, :pubDate, :description)");
    $stmt->execute([
        ':identifier' => $identifier,
        ':title' => $item['title'] ?? '',
        ':link' => $item['link'] ?? '',
        ':pubDate' => $item['pubDate'] ?? '',
        ':description' => $item['description'] ?? ''
    ]);
    
    if ($stmt->rowCount() > 0) {
        log_message('DEBUG', "New warning saved: " . ($item['title'] ?? 'Unknown'));
        return true;
    } else {
        log_message('WARNING', "Failed to save warning: " . ($item['title'] ?? 'Unknown'));
        return false;
    }
}

// Proses peringatan dini cuaca
function process_warnings($db) {
    global $WARNING_RSS_URL, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    
    try {
        $rss_text = fetch_rss_with_retry($WARNING_RSS_URL, 'warning');
        if (!$rss_text) {
            return 0;
        }
        
        $items = parse_rss_items($rss_text);
        $new_count = 0;
        
        foreach ($items as $item) {
            if (save_warning($db, $item)) {
                $new_count++;
                log_message('INFO', "New warning: " . ($item['title'] ?? 'Unknown'));
                
                // Kirim notifikasi untuk peringatan baru
                if ($SEND_TO_SLACK || $SEND_TO_TELEGRAM) {
                    $message = "⚠️ *Peringatan Dini Cuaca*\n\n" .
                               "*Judul:* " . escape_markdown($item['title'] ?? '') . "\n" .
                               "*Deskripsi:* " . escape_markdown($item['description'] ?? '') . "\n" .
                               "*Waktu:* " . escape_markdown($item['pubDate'] ?? '');
                    
                    if ($SEND_TO_SLACK) {
                        send_slack_notification(['message' => $message], 'warning');
                    }
                    if ($SEND_TO_TELEGRAM) {
                        send_telegram_notification(['message' => $message], 'warning');
                    }
                }
            }
        }
        
        log_message('INFO', "Warnings checked. New events: $new_count");
        return $new_count;
    } catch (Exception $e) {
        log_message('ERROR', "Warning error: " . $e->getMessage());
        return 0;
    }
}

// Cek apakah cuaca ekstrem
function is_extreme_weather($forecast) {
    $weather_desc = strtolower($forecast['weather_desc'] ?? '');
    $ws = $forecast['ws'] ?? 0;
    $hu = $forecast['hu'] ?? 0;
    $tp = $forecast['tp'] ?? 0; // Curah hujan (mm)
    
    // Cuaca ekstrem berdasarkan deskripsi
    $extreme_keywords = ['hujan lebat', 'hujan sangat lebat', 'angin kencang', 'badai', 'petir', 'ekstrem'];
    foreach ($extreme_keywords as $keyword) {
        if (strpos($weather_desc, $keyword) !== false) {
            return true;
        }
    }
    
    // Angin kencang (>40 km/jam)
    if ($ws > 40) {
        return true;
    }
    
    // Hujan lebat (curah hujan >50 mm/jam atau tp > 50)
    if ($tp > 50) {
        return true;
    }
    
    // Kelembapan sangat tinggi (>95%)
    if ($hu > 95) {
        return true;
    }
    
    return false;
}

// Simpan prakiraan cuaca ke database
function save_weather($db, $adm4, $forecast) {
    // Cek dulu apakah data sudah ada
    $local_dt = $forecast['local_datetime'] ?? '';
    $check_stmt = $db->prepare("SELECT COUNT(*) FROM cuaca WHERE adm4 = :adm4 AND local_datetime = :local_datetime");
    $check_stmt->execute([':adm4' => $adm4, ':local_datetime' => $local_dt]);
    $exists = $check_stmt->fetchColumn() > 0;
    
    if ($exists) {
        log_message('DEBUG', "Weather data already exists for $adm4 at $local_dt");
        return ['saved' => false, 'forecast' => null]; // Data sudah ada, tidak perlu insert
    }
    
    $stmt = $db->prepare("INSERT INTO cuaca (adm4, analysis_date, local_datetime, utc_datetime, suhu, kelembapan, cuaca, cuaca_en, angin_kecepatan, angin_arah, tutupan_awan, jarak_pandang, payload) VALUES (:adm4, :analysis_date, :local_datetime, :utc_datetime, :suhu, :kelembapan, :cuaca, :cuaca_en, :angin_kecepatan, :angin_arah, :tutupan_awan, :jarak_pandang, :payload)");
    
    try {
        $result = $stmt->execute([
            ':adm4' => $adm4,
            ':analysis_date' => $forecast['analysis_date'] ?? '',
            ':local_datetime' => $forecast['local_datetime'] ?? '',
            ':utc_datetime' => $forecast['utc_datetime'] ?? '',
            ':suhu' => $forecast['t'] ?? null,
            ':kelembapan' => $forecast['hu'] ?? null,
            ':cuaca' => $forecast['weather_desc'] ?? '',
            ':cuaca_en' => $forecast['weather_desc_en'] ?? '',
            ':angin_kecepatan' => $forecast['ws'] ?? null,
            ':angin_arah' => $forecast['wd'] ?? '',
            ':tutupan_awan' => $forecast['tcc'] ?? null,
            ':jarak_pandang' => $forecast['vs_text'] ?? '',
            ':payload' => json_encode($forecast)
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            log_message('DEBUG', "Saved weather for $adm4 at $local_dt");
            return ['saved' => true, 'forecast' => $forecast, 'adm4' => $adm4];
        } else {
            log_message('WARNING', "Failed to save weather for $adm4 at $local_dt (rowCount: " . $stmt->rowCount() . ")");
            return ['saved' => false, 'forecast' => null];
        }
    } catch (PDOException $e) {
        log_message('ERROR', "Database error saving weather for $adm4: " . $e->getMessage());
        return ['saved' => false, 'forecast' => null];
    }
}

// Proses prakiraan cuaca untuk list wilayah
function process_weather($db) {
    global $WEATHER_BASE_URL, $CUACA_WILAYAH;
    
    if (empty($CUACA_WILAYAH)) {
        log_message('WARNING', 'CUACA_WILAYAH is empty, skipping weather check');
        return 0;
    }
    
    $wilayah_list = array_filter(array_map('trim', explode(',', $CUACA_WILAYAH)));
    if (empty($wilayah_list)) {
        log_message('WARNING', 'CUACA_WILAYAH is invalid, skipping weather check');
        return 0;
    }
    
    log_message('INFO', "Processing weather for " . count($wilayah_list) . " wilayah: " . implode(', ', $wilayah_list));
    $new_count = 0;
    
    foreach ($wilayah_list as $adm4) {
        try {
            $url = $WEATHER_BASE_URL . $adm4;
            log_message('DEBUG', "Fetching weather for $adm4 from $url");
            $data = fetch_json_with_retry($url, "cuaca-$adm4");
            
            if ($data === false) {
                log_message('WARNING', "Failed to fetch weather data for $adm4 (fetch_json_with_retry returned false)");
                continue;
            }
            
            if ($data === null) {
                log_message('WARNING', "Weather data is null for $adm4");
                continue;
            }
            
            // Log raw response untuk debugging
            log_message('DEBUG', "Raw weather response type for $adm4: " . gettype($data));
            if (is_string($data)) {
                log_message('DEBUG', "Weather response is string (first 500 chars): " . substr($data, 0, 500));
                // Coba parse ulang
                $parsed = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data = $parsed;
                    log_message('DEBUG', "Successfully parsed JSON string for $adm4");
                } else {
                    log_message('ERROR', "JSON parse error for $adm4: " . json_last_error_msg());
                    continue;
                }
            }
            
            if (!is_array($data)) {
                log_message('WARNING', "Weather data invalid format for $adm4: expected array, got " . gettype($data));
                log_message('DEBUG', "Weather data sample: " . substr(json_encode($data), 0, 500));
                continue;
            }
            
            // Cek apakah array kosong
            if (empty($data)) {
                log_message('WARNING', "Weather data array is empty for $adm4");
                continue;
            }
            
            // Log struktur data untuk debugging
            log_message('DEBUG', "Weather data structure for $adm4: " . json_encode(array_keys($data)));
            
            // Struktur BMKG: { lokasi: "...", data: [{ lokasi: "...", cuaca: [...] }] }
            // atau langsung array of forecast objects
            $forecast_list = [];
            
            if (isset($data['data']) && is_array($data['data'])) {
                log_message('DEBUG', "Found 'data' key in response for $adm4 with " . count($data['data']) . " items");
                // data berisi array, setiap item mungkin punya "cuaca" array
                foreach ($data['data'] as $item_idx => $item) {
                    if (!is_array($item)) {
                        log_message('DEBUG', "Skipping data item $item_idx: not an array");
                        continue;
                    }
                    
                    $item_keys = array_keys($item);
                    log_message('DEBUG', "Data item $item_idx has keys: " . implode(', ', $item_keys));
                    
                    // Cek apakah item langsung forecast object (punya local_datetime)
                    if (isset($item['local_datetime'])) {
                        log_message('DEBUG', "Item $item_idx is a direct forecast object");
                        $forecast_list[] = $item;
                    } 
                    // Atau item punya key "cuaca" yang berisi array forecast
                    elseif (isset($item['cuaca'])) {
                        log_message('DEBUG', "Found 'cuaca' key in data item $item_idx for $adm4, type: " . gettype($item['cuaca']));
                        if (is_array($item['cuaca'])) {
                            log_message('DEBUG', "Processing " . count($item['cuaca']) . " items in 'cuaca' array for $adm4");
                            foreach ($item['cuaca'] as $cuaca_idx => $cuaca_item) {
                                // cuaca bisa berupa array of arrays atau array of objects
                                if (is_array($cuaca_item)) {
                                    // Cek apakah ini nested array: [[{forecast1}, {forecast2}, ...]]
                                    if (!empty($cuaca_item) && isset($cuaca_item[0]) && is_array($cuaca_item[0])) {
                                        // Cek apakah elemen pertama punya local_datetime (forecast object)
                                        if (isset($cuaca_item[0]['local_datetime'])) {
                                            // Ini nested array: [[{forecast1}, {forecast2}, ...]]
                                            log_message('DEBUG', "Found nested array in cuaca[$cuaca_idx] with " . count($cuaca_item) . " forecast items");
                                            foreach ($cuaca_item as $forecast) {
                                                if (is_array($forecast) && isset($forecast['local_datetime'])) {
                                                    $forecast_list[] = $forecast;
                                                }
                                            }
                                        } else {
                                            log_message('DEBUG', "cuaca[$cuaca_idx][0] is array but missing local_datetime (keys: " . implode(', ', array_keys($cuaca_item[0])) . ")");
                                        }
                                    } 
                                    // Cek apakah ini langsung forecast object
                                    elseif (isset($cuaca_item['local_datetime'])) {
                                        log_message('DEBUG', "cuaca[$cuaca_idx] is a direct forecast object");
                                        $forecast_list[] = $cuaca_item;
                                    } 
                                    // Jika tidak, log untuk debugging
                                    else {
                                        $keys = array_keys($cuaca_item);
                                        log_message('DEBUG', "cuaca[$cuaca_idx] is array but not forecast (keys: " . implode(', ', $keys) . ", first item type: " . (isset($cuaca_item[0]) ? gettype($cuaca_item[0]) : 'none') . ")");
                                    }
                                } else {
                                    log_message('DEBUG', "cuaca[$cuaca_idx] is not an array (type: " . gettype($cuaca_item) . ")");
                                }
                            }
                        } else {
                            log_message('WARNING', "'cuaca' is not an array for $adm4, type: " . gettype($item['cuaca']));
                        }
                    } else {
                        log_message('WARNING', "Data item $item_idx has no 'local_datetime' or 'cuaca' key for $adm4");
                    }
                }
            } elseif (isset($data[0]) && is_array($data[0])) {
                // Cek apakah array langsung berisi forecast objects
                if (isset($data[0]['local_datetime'])) {
                    log_message('DEBUG', "Response is direct array of forecast objects for $adm4");
                    $forecast_list = $data;
                } 
                // Atau array berisi objects dengan key "cuaca"
                elseif (isset($data[0]['cuaca']) && is_array($data[0]['cuaca'])) {
                    log_message('DEBUG', "Found 'cuaca' arrays in top-level array for $adm4");
                    foreach ($data as $item) {
                        if (is_array($item) && isset($item['cuaca']) && is_array($item['cuaca'])) {
                            foreach ($item['cuaca'] as $forecast) {
                                if (is_array($forecast) && isset($forecast['local_datetime'])) {
                                    $forecast_list[] = $forecast;
                                }
                            }
                        }
                    }
                }
            }
            
            if (empty($forecast_list)) {
                log_message('WARNING', "Could not find forecast data in response for $adm4");
                log_message('DEBUG', "Full response structure: " . substr(json_encode($data), 0, 1000));
                continue;
            }
            
            // Filter hanya forecast yang valid (punya local_datetime)
            $valid_forecasts = [];
            foreach ($forecast_list as $idx => $forecast) {
                if (!is_array($forecast)) {
                    log_message('DEBUG', "Skipping entry $idx: not an array for $adm4");
                    continue;
                }
                
                // Validasi minimal: harus ada local_datetime
                if (empty($forecast['local_datetime'])) {
                    log_message('DEBUG', "Skipping entry $idx: missing local_datetime (keys: " . implode(', ', array_keys($forecast)) . ")");
                    continue;
                }
                
                $valid_forecasts[] = $forecast;
            }
            
            log_message('DEBUG', "Processing " . count($valid_forecasts) . " valid forecast entries for $adm4 (from " . count($forecast_list) . " total)");
            
            $new_forecasts = [];
            $extreme_forecasts = [];
            
            foreach ($valid_forecasts as $forecast) {
                $result = save_weather($db, $adm4, $forecast);
                if ($result['saved']) {
                    $new_count++;
                    $new_forecasts[] = $result;
                    
                    // Cek apakah cuaca ekstrem
                    if (is_extreme_weather($forecast)) {
                        $extreme_forecasts[] = $result;
                    }
                }
            }
            
            // Kirim notifikasi untuk data cuaca baru
            global $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
            if (!empty($new_forecasts) && ($SEND_TO_SLACK || $SEND_TO_TELEGRAM)) {
                // Notifikasi untuk cuaca ekstrem (prioritas tinggi)
                foreach ($extreme_forecasts as $result) {
                    $f = $result['forecast'];
                    $wilayah_code = $result['adm4'];
                    $message = "⚠️ *Peringatan Cuaca Ekstrem*\n\n" .
                               "*Wilayah:* $wilayah_code\n" .
                               "*Waktu:* " . escape_markdown($f['local_datetime'] ?? '') . "\n" .
                               "*Cuaca:* " . escape_markdown($f['weather_desc'] ?? '') . "\n" .
                               "*Suhu:* " . ($f['t'] ?? 'N/A') . "°C\n" .
                               "*Kelembapan:* " . ($f['hu'] ?? 'N/A') . "%\n" .
                               "*Angin:* " . escape_markdown($f['wd'] ?? '') . " " . ($f['ws'] ?? 'N/A') . " km/jam\n" .
                               "*Curah Hujan:* " . ($f['tp'] ?? '0') . " mm\n" .
                               "*Jarak Pandang:* " . escape_markdown($f['vs_text'] ?? '');
                    
                    if ($SEND_TO_SLACK) {
                        send_slack_notification(['message' => $message], 'warning');
                    }
                    if ($SEND_TO_TELEGRAM) {
                        send_telegram_notification(['message' => $message], 'warning');
                    }
                    
                    log_message('INFO', "Extreme weather notification sent for $wilayah_code at " . ($f['local_datetime'] ?? ''));
                }
                
                // Notifikasi untuk data cuaca baru (non-ekstrem) - hanya notifikasi pertama kali
                if (count($new_forecasts) > count($extreme_forecasts)) {
                    $normal_count = count($new_forecasts) - count($extreme_forecasts);
                    if ($normal_count > 0) {
                        log_message('DEBUG', "New weather data saved: $normal_count normal forecasts for $adm4 (notifications skipped for normal weather)");
                    }
                }
            }
        } catch (Exception $e) {
            log_message('ERROR', "Weather error for $adm4: " . $e->getMessage());
        }
    }
    
    log_message('INFO', "Weather processed. New rows: $new_count");
    
    // Notifikasi harian untuk prakiraan cuaca (sekali sehari saat ada data baru)
    global $SEND_TO_SLACK, $SEND_TO_TELEGRAM, $CUACA_WILAYAH;
    if (($SEND_TO_SLACK || $SEND_TO_TELEGRAM) && $new_count > 0) {
        $wilayah_list = array_filter(array_map('trim', explode(',', $CUACA_WILAYAH)));
        
        // Ambil forecast terdekat untuk setiap wilayah (24 jam ke depan)
        $daily_summary = [];
        foreach ($wilayah_list as $adm4) {
            $stmt = $db->prepare("
                SELECT * FROM cuaca 
                WHERE adm4 = :adm4 
                AND datetime(local_datetime) >= datetime('now', 'localtime')
                AND datetime(local_datetime) <= datetime('now', 'localtime', '+24 hours')
                ORDER BY local_datetime ASC
                LIMIT 8
            ");
            $stmt->execute([':adm4' => $adm4]);
            $forecasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($forecasts)) {
                $daily_summary[$adm4] = $forecasts;
            }
        }
        
        // Kirim notifikasi harian jika ada data (hanya sekali per proses, saat ada data baru)
        if (!empty($daily_summary)) {
            $message = "📅 *Prakiraan Cuaca Harian*\n\n";
            
            foreach ($daily_summary as $adm4 => $forecasts) {
                $message .= "*Wilayah: $adm4*\n";
                $message .= "Prakiraan 24 jam ke depan:\n";
                
                foreach (array_slice($forecasts, 0, 8) as $f) {
                    $time = date('H:i', strtotime($f['local_datetime']));
                    $cuaca = $f['cuaca'] ?? 'N/A';
                    $suhu = $f['suhu'] ?? 'N/A';
                    $suhu_str = $suhu !== 'N/A' ? $suhu . '°C' : 'N/A';
                    $message .= "• $time: $cuaca, $suhu_str\n";
                }
                $message .= "\n";
            }
            
            if ($SEND_TO_SLACK) {
                send_slack_notification(['message' => $message], 'warning');
            }
            if ($SEND_TO_TELEGRAM) {
                send_telegram_notification(['message' => $message], 'warning');
            }
            
            log_message('INFO', "Daily weather forecast notification sent for " . count($daily_summary) . " wilayah");
        }
    }
    
    return $new_count;
}

// Main Logic
try {
    // Validasi konfigurasi
    if (!validate_config()) {
        log_message('ERROR', 'Configuration validation failed. Please check your environment variables.');
        exit(1);
    }
    
    log_message('INFO', 'Starting gempa monitor...');
    $db = init_db();
    
    // Proses pertama kali
    process_gempa_data($db);
    process_warnings($db);
    
    global $CUACA_WILAYAH;
    if (!empty($CUACA_WILAYAH)) {
        log_message('DEBUG', "Processing initial weather check. CUACA_WILAYAH: $CUACA_WILAYAH");
        process_weather($db);
    } else {
        log_message('WARNING', 'CUACA_WILAYAH not configured, skipping initial weather check');
    }
    
    // Loop untuk pengecekan berkala dengan graceful shutdown
    global $CHECK_INTERVAL, $WARNING_INTERVAL_SEC, $WEATHER_INTERVAL_SEC, $shutdown_requested;
    $last_warning_check = time();
    $last_weather_check = time();
    
    while (!$shutdown_requested) {
        // Handle signals jika menggunakan pcntl
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        if ($shutdown_requested) {
            break;
        }
        
        sleep($CHECK_INTERVAL);
        
        if (!$shutdown_requested) {
            // Proses gempa (selalu setiap interval)
            process_gempa_data($db);
            
            // Proses peringatan dini (setiap 5 menit)
            $now = time();
            if (($now - $last_warning_check) >= $WARNING_INTERVAL_SEC) {
                process_warnings($db);
                $last_warning_check = $now;
            }
            
            // Proses prakiraan cuaca (setiap 60 menit)
            if (($now - $last_weather_check) >= $WEATHER_INTERVAL_SEC) {
                global $CUACA_WILAYAH;
                if (empty($CUACA_WILAYAH)) {
                    log_message('WARNING', 'CUACA_WILAYAH not configured, skipping weather check');
                } else {
                    log_message('DEBUG', "Processing weather. CUACA_WILAYAH: $CUACA_WILAYAH");
                    process_weather($db);
                }
                $last_weather_check = $now;
            }
        }
    }
    
    log_message('INFO', 'Gempa monitor stopped gracefully');
} catch (Exception $e) {
    log_message('ERROR', "Fatal Error: " . $e->getMessage());
    exit(1);
}
?>
