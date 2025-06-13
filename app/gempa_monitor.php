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
$CHECK_INTERVAL = 200; // 15 menit dalam detik
$RETRY_ATTEMPTS = 3; // Jumlah percobaan ulang
$RETRY_DELAY = 5; // Jeda antar percobaan (detik)
$MAX_AGE_HOURS = 24; // Hanya notifikasi gempa dalam 24 jam terakhir

// Inisialisasi database SQLite
function init_db() {
    global $DB_FILE;
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
    
    return $db;
}

// Fungsi untuk kirim notifikasi Slack
function send_slack_notification($gempa, $source) {
    global $SLACK_WEBHOOK;
    
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

    if ($source === 'autogempa' && $gempa['shakemap']) {
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
        CURLOPT_RETURNTRANSFER => true
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log("Slack notification failed: " . curl_error($ch));
    } else {
        error_log("Slack notification sent for {$gempa['datetime']} from $source");
    }
    curl_close($ch);
}

// Fungsi untuk kirim notifikasi Telegram
function send_telegram_notification($gempa, $source) {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID;
    
    error_log("Attempting to send Telegram notification for {$gempa['datetime']} from $source");
    
    $title = $source === 'autogempa' ? "Gempa Terbaru" : 
             ($source === 'gempaterkini' ? "Gempa Terkini" : "Gempa Dirasakan");
    
    $message = "🚨 *{$title} Terdeteksi!* 🚨\n\n" .
               "*Magnitudo:* M{$gempa['magnitude']}\n" .
               "*Kedalaman:* {$gempa['kedalaman']}\n" .
               "*Lokasi:* {$gempa['wilayah']}\n" .
               "*Waktu:* {$gempa['datetime']}\n" .
               "*Potensi:* " . ($gempa['potensi'] ?: 'N/A') . "\n" .
               "*Dirasakan:* " . ($gempa['dirasakan'] ?: 'N/A') . "\n";

    if ($source === 'autogempa' && $gempa['shakemap']) {
        $message .= "*Shakemap:* https://data.bmkg.go.id/DataMKG/TEWS/{$gempa['shakemap']}\n";
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
        CURLOPT_RETURNTRANSFER => true
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log("Telegram notification failed: " . curl_error($ch));
    } else {
        $result = json_decode($response, true);
        if ($result['ok']) {
            error_log("Telegram notification sent for {$gempa['datetime']} from $source");
        } else {
            error_log("Telegram API error: " . $result['description']);
        }
    }
    curl_close($ch);
}

// Fungsi untuk normalisasi datetime
function normalize_datetime($datetime) {
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
        error_log("Failed to normalize datetime: $datetime - " . $e->getMessage());
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
            error_log("Gempa too old: $datetime (age: $hours hours)");
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log("Failed to check gempa age: $datetime - " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk simpan gempa ke database
function save_gempa($db, $gempa, $source) {
    $raw_datetime = isset($gempa->DateTime) ? (string)$gempa->DateTime : null;
    error_log("Processing gempa from $source with raw datetime: $raw_datetime");
    
    $datetime = normalize_datetime($raw_datetime);
    if (!$datetime) {
        error_log("Invalid datetime for gempa from source $source: $raw_datetime");
        return false;
    }

    // Periksa apakah gempa sudah ada
    $stmt = $db->prepare("SELECT id FROM gempa WHERE datetime = :datetime AND source = :source");
    $stmt->execute([':datetime' => $datetime, ':source' => $source]);
    if ($stmt->fetch()) {
        error_log("Gempa already exists: $datetime from $source");
        return false;
    }

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
    
    $stmt->execute($data);
    $is_new = $db->lastInsertId() > 0;
    if ($is_new) {
        error_log("New gempa saved: $datetime from $source");
    }
    return $is_new;
}

// Fungsi untuk mengambil data dengan retry
function fetch_xml_with_retry($url, $source) {
    global $RETRY_ATTEMPTS, $RETRY_DELAY;
    
    for ($attempt = 1; $attempt <= $RETRY_ATTEMPTS; $attempt++) {
        $data = simplexml_load_file($url);
        if ($data !== false) {
            return $data;
        }
        error_log("Attempt $attempt failed for $source: Unable to load $url");
        if ($attempt < $RETRY_ATTEMPTS) {
            sleep($RETRY_DELAY);
        }
    }
    error_log("$source error: Failed to load $url after $RETRY_ATTEMPTS attempts");
    return false;
}

// Fungsi untuk proses data autogempa
function process_autogempa($db, $is_first_run) {
    global $DATA_URLS, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    $new_gempa = [];
    
    try {
        $data = fetch_xml_with_retry($DATA_URLS['autogempa'], 'autogempa');
        if ($data === false) {
            throw new Exception("Failed to load autogempa.xml");
        }
        if (save_gempa($db, $data->gempa, 'autogempa')) {
            $datetime = normalize_datetime((string)$data->gempa->DateTime);
            if ($datetime && is_recent_gempa($datetime)) {
                $new_gempa[] = [
                    'datetime' => $datetime,
                    'magnitude' => (string)$data->gempa->Magnitude,
                    'kedalaman' => (string)$data->gempa->Kedalaman,
                    'wilayah' => (string)$data->gempa->Wilayah,
                    'potensi' => (string)$data->gempa->Potensi,
                    'dirasakan' => (string)$data->gempa->Dirasakan,
                    'shakemap' => (string)$data->gempa->Shakemap
                ];
            }
        }

        if (!empty($new_gempa)) {
            foreach ($new_gempa as $gempa) {
                if ($SEND_TO_SLACK) {
                    send_slack_notification($gempa, 'autogempa');
                }
                if ($SEND_TO_TELEGRAM) {
                    send_telegram_notification($gempa, 'autogempa');
                }
            }
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Autogempa checked. New events: " . count($new_gempa) . "\n";
        return count($new_gempa);
    } catch (Exception $e) {
        error_log("Autogempa error: " . $e->getMessage());
        return 0;
    }
}

// Fungsi untuk proses data gempaterkini
function process_gempaterkini($db, $is_first_run) {
    global $DATA_URLS, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    $new_gempa = [];
    
    try {
        $data = fetch_xml_with_retry($DATA_URLS['gempaterkini'], 'gempaterkini');
        if ($data === false) {
            throw new Exception("Failed to load gempaterkini.xml");
        }
        foreach ($data->gempa as $gempa) {
            if (save_gempa($db, $gempa, 'gempaterkini')) {
                $datetime = normalize_datetime((string)$gempa->DateTime);
                if ($datetime && is_recent_gempa($datetime)) {
                    $new_gempa[] = [
                        'datetime' => $datetime,
                        'magnitude' => (string)$gempa->Magnitude,
                        'kedalaman' => (string)$gempa->Kedalaman,
                        'wilayah' => (string)$gempa->Wilayah,
                        'potensi' => (string)$gempa->Potensi,
                        'dirasakan' => '',
                        'shakemap' => ''
                    ];
                }
            }
        }

        if (!empty($new_gempa)) {
            foreach ($new_gempa as $gempa) {
                if ($SEND_TO_SLACK) {
                    send_slack_notification($gempa, 'gempaterkini');
                }
                if ($SEND_TO_TELEGRAM) {
                    send_telegram_notification($gempa, 'gempaterkini');
                }
            }
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Gempaterkini checked. New events: " . count($new_gempa) . "\n";
        return count($new_gempa);
    } catch (Exception $e) {
        error_log("Gempaterkini error: " . $e->getMessage());
        return 0;
    }
}

// Fungsi untuk proses data gempadirasakan
function process_gempadirasakan($db, $is_first_run) {
    global $DATA_URLS, $SEND_TO_SLACK, $SEND_TO_TELEGRAM;
    $new_gempa = [];
    
    try {
        $data = fetch_xml_with_retry($DATA_URLS['gempadirasakan'], 'gempadirasakan');
        if ($data === false) {
            throw new Exception("Failed to load gempadirasakan.xml");
        }
        foreach ($data->gempa as $gempa) {
            if (save_gempa($db, $gempa, 'gempadirasakan')) {
                $datetime = normalize_datetime((string)$gempa->DateTime);
                if ($datetime && is_recent_gempa($datetime)) {
                    $new_gempa[] = [
                        'datetime' => $datetime,
                        'magnitude' => (string)$gempa->Magnitude,
                        'kedalaman' => (string)$gempa->Kedalaman,
                        'wilayah' => (string)$gempa->Wilayah,
                        'potensi' => '',
                        'dirasakan' => (string)$gempa->Dirasakan,
                        'shakemap' => ''
                    ];
                }
            }
        }

        if (!empty($new_gempa)) {
            foreach ($new_gempa as $gempa) {
                if ($SEND_TO_SLACK) {
                    send_slack_notification($gempa, 'gempadirasakan');
                }
                if ($SEND_TO_TELEGRAM) {
                    send_telegram_notification($gempa, 'gempadirasakan');
                }
            }
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Gempadirasakan checked. New events: " . count($new_gempa) . "\n";
        return count($new_gempa);
    } catch (Exception $e) {
        error_log("Gempadirasakan error: " . $e->getMessage());
        return 0;
    }
}

// Fungsi utama untuk proses semua sumber
function process_gempa_data($db, $is_first_run = false) {
    $total_new = 0;
    $total_new += process_autogempa($db, $is_first_run);
    sleep(1); // Jeda antar permintaan
    $total_new += process_gempaterkini($db, $is_first_run);
    sleep(1); // Jeda antar permintaan
    $total_new += process_gempadirasakan($db, $is_first_run);
    
    echo "[" . date('Y-m-d H:i:s') . "] Total new events: $total_new\n";
}

// Main Logic
try {
    $db = init_db();
    
    // Proses pertama kali
    process_gempa_data($db, true);
    
    // Loop untuk pengecekan berkala
    while (true) {
        sleep($CHECK_INTERVAL);
        process_gempa_data($db, false);
    }
} catch (Exception $e) {
    error_log("Fatal Error: " . $e->getMessage());
    exit(1);
}
?>
