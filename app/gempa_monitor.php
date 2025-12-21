<?php
// Konfigurasi
$SLACK_WEBHOOK = getenv('SLACK_WEBHOOK') ?: '';
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$TELEGRAM_CHAT_ID = getenv('TELEGRAM_CHAT_ID') ?: '';
$SEND_TO_SLACK = filter_var(getenv('SEND_TO_SLACK'), FILTER_VALIDATE_BOOLEAN);
$SEND_TO_TELEGRAM = filter_var(getenv('SEND_TO_TELEGRAM'), FILTER_VALIDATE_BOOLEAN);
$DATA_URLS = [
    'gempaterkini' => 'https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.xml',
    'autogempa' => 'https://data.bmkg.go.id/DataMKG/TEWS/autogempa.xml',
    'gempadirasakan' => 'https://data.bmkg.go.id/DataMKG/TEWS/gempadirasakan.xml'
];
$DB_FILE = '/data/gempa.db';
$CHECK_INTERVAL = 200; // ~3.3 menit (200 detik)
$RETRY_ATTEMPTS = 3; // Jumlah percobaan ulang
$RETRY_DELAY = 5; // Jeda antar percobaan (detik)
$MAX_AGE_HOURS = 24; // Hanya notifikasi gempa dalam 24 jam terakhir
$HTTP_TIMEOUT = 30; // Timeout untuk HTTP requests (detik)
$HTTP_CONNECT_TIMEOUT = 10; // Timeout untuk koneksi HTTP (detik)

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

// Fungsi untuk kirim notifikasi Slack
function send_slack_notification($gempa, $source) {
    global $SLACK_WEBHOOK, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    if (empty($SLACK_WEBHOOK)) {
        log_message('WARNING', 'Slack webhook not configured, skipping notification');
        return false;
    }
    
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
        log_message('INFO', "Slack notification sent for {$gempa['datetime']} from $source");
        return true;
    } else {
        log_message('ERROR', "Slack notification failed with HTTP $http_code: $response");
        return false;
    }
}

// Fungsi untuk kirim notifikasi Telegram
function send_telegram_notification($gempa, $source) {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    if (empty($TELEGRAM_BOT_TOKEN) || empty($TELEGRAM_CHAT_ID)) {
        log_message('WARNING', 'Telegram credentials not configured, skipping notification');
        return false;
    }
    
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
        log_message('INFO', "Telegram notification sent for {$gempa['datetime']} from $source");
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

// Fungsi untuk mengambil data XML dengan retry menggunakan cURL
function fetch_xml_with_retry($url, $source) {
    global $RETRY_ATTEMPTS, $RETRY_DELAY, $HTTP_TIMEOUT, $HTTP_CONNECT_TIMEOUT;
    
    for ($attempt = 1; $attempt <= $RETRY_ATTEMPTS; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => $HTTP_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            log_message('WARNING', "Attempt $attempt failed for $source: $curl_error");
        } elseif ($http_code >= 200 && $http_code < 300) {
            // Parse XML dari response
            libxml_use_internal_errors(true);
            $data = simplexml_load_string($response);
            if ($data !== false) {
                log_message('DEBUG', "Successfully fetched $source data");
                return $data;
            } else {
                $xml_errors = libxml_get_errors();
                $error_msg = !empty($xml_errors) ? $xml_errors[0]->message : 'Invalid XML';
                log_message('WARNING', "Attempt $attempt failed for $source: Invalid XML - $error_msg");
                libxml_clear_errors();
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

// Fungsi generic untuk proses data gempa dari berbagai sumber
function process_gempa_source($db, $source, $is_single_item = false) {
    global $DATA_URLS, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    $new_gempa = [];
    
    try {
        if (!isset($DATA_URLS[$source])) {
            log_message('ERROR', "Unknown source: $source");
            return 0;
        }
        
        $data = fetch_xml_with_retry($DATA_URLS[$source], $source);
        if ($data === false) {
            throw new Exception("Failed to load data for $source");
        }
        
        // Handle single item (autogempa) vs multiple items (gempaterkini, gempadirasakan)
        $items = $is_single_item ? [$data->gempa] : $data->gempa;
        
        foreach ($items as $gempa) {
            if (save_gempa($db, $gempa, $source)) {
                $datetime = normalize_datetime((string)$gempa->DateTime);
                if ($datetime && is_recent_gempa($datetime)) {
                    $gempa_data = [
                        'datetime' => $datetime,
                        'magnitude' => (string)$gempa->Magnitude,
                        'kedalaman' => (string)$gempa->Kedalaman,
                        'wilayah' => (string)$gempa->Wilayah,
                        'potensi' => $source === 'gempadirasakan' ? '' : (string)$gempa->Potensi,
                        'dirasakan' => $source === 'gempaterkini' ? '' : (string)$gempa->Dirasakan,
                        'shakemap' => $source === 'autogempa' ? (string)$gempa->Shakemap : ''
                    ];
                    $new_gempa[] = $gempa_data;
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
    
    // Loop untuk pengecekan berkala dengan graceful shutdown
    global $CHECK_INTERVAL, $shutdown_requested;
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
            process_gempa_data($db);
        }
    }
    
    log_message('INFO', 'Gempa monitor stopped gracefully');
} catch (Exception $e) {
    log_message('ERROR', "Fatal Error: " . $e->getMessage());
    exit(1);
}
?>
