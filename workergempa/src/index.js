// Konfigurasi
const GEMPA_URLS = {
  gempaterkini: 'https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.json',
  autogempa: 'https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json',
  gempadirasakan: 'https://data.bmkg.go.id/DataMKG/TEWS/gempadirasakan.json'
};

const WARNING_RSS_URL = 'https://www.bmkg.go.id/alerts/nowcast/id';
const WEATHER_BASE_URL = 'https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=';

const RETRY_ATTEMPTS = 3;
const RETRY_DELAY = 5000; // 5 detik dalam milliseconds
const MAX_AGE_HOURS = 24;
const HTTP_TIMEOUT = 30000; // 30 detik dalam milliseconds
const WARNING_INTERVAL_MIN = 5; // setiap 5 menit
const WEATHER_INTERVAL_MIN = 60; // setiap 60 menit

// Fungsi logging dengan level
function logMessage(level, message) {
  const timestamp = new Date().toISOString();
  console.log(`[${timestamp}] [${level}] ${message}`);
}

// Validasi environment variables
function validateConfig(env) {
  const { SEND_TO_SLACK, SEND_TO_TELEGRAM, SLACK_WEBHOOK, TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID } = env;

  if (SEND_TO_SLACK === 'true' && !SLACK_WEBHOOK) {
    logMessage('ERROR', 'SEND_TO_SLACK is enabled but SLACK_WEBHOOK is empty');
    return false;
  }

  if (SEND_TO_TELEGRAM === 'true') {
    if (!TELEGRAM_BOT_TOKEN) {
      logMessage('ERROR', 'SEND_TO_TELEGRAM is enabled but TELEGRAM_BOT_TOKEN is empty');
      return false;
    }
    if (!TELEGRAM_CHAT_ID) {
      logMessage('ERROR', 'SEND_TO_TELEGRAM is enabled but TELEGRAM_CHAT_ID is empty');
      return false;
    }
  }

  return true;
}

// Inisialisasi database D1
async function initDb(db) {
  try {
    if (!db || typeof db.exec !== 'function') {
      throw new Error('DB binding is undefined or invalid (env.DB)');
    }

    const ddl = `CREATE TABLE IF NOT EXISTS gempa (
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
      );`;

    const ddlWarning = `CREATE TABLE IF NOT EXISTS warning (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier TEXT,
        title TEXT,
        link TEXT,
        pubDate TEXT,
        description TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(identifier)
      );`;

    const ddlWeather = `CREATE TABLE IF NOT EXISTS cuaca (
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
      );`;

    // Gunakan prepare().run() agar hasil objek tidak undefined di lingkungan D1
    await db.prepare(ddl).run();
    await db.prepare(ddlWarning).run();
    await db.prepare(ddlWeather).run();
    logMessage('INFO', 'Database initialized successfully');
    return true;
  } catch (error) {
    logMessage('ERROR', 'Database initialization failed: ' + error.message);
    throw error;
  }
}

// Escape Markdown untuk Telegram
function escapeMarkdown(text) {
  if (!text) {
    return '';
  }
  // Escape karakter Markdown yang berbahaya
  return text
    .replace(/\*/g, '\\*')
    .replace(/_/g, '\\_')
    .replace(/\[/g, '\\[')
    .replace(/\]/g, '\\]')
    .replace(/\(/g, '\\(')
    .replace(/\)/g, '\\)')
    .replace(/~/g, '\\~')
    .replace(/`/g, '\\`')
    .replace(/>/g, '\\>')
    .replace(/#/g, '\\#')
    .replace(/\+/g, '\\+')
    .replace(/-/g, '\\-')
    .replace(/=/g, '\\=')
    .replace(/\|/g, '\\|')
    .replace(/\{/g, '\\{')
    .replace(/\}/g, '\\}')
    .replace(/\./g, '\\.')
    .replace(/!/g, '\\!');
}

// Fungsi untuk kirim notifikasi Slack
async function sendSlackNotification(data, source, env) {
  const { SLACK_WEBHOOK } = env;

  if (!SLACK_WEBHOOK) {
    logMessage('WARNING', 'Slack webhook not configured, skipping notification');
    return false;
  }

  let message;
  
  // Handle warning notification
  if (source === 'warning' && data.message) {
    message = {
      blocks: [
        {
          type: 'section',
          text: {
            type: 'mrkdwn',
            text: data.message
          }
        }
      ]
    };
  } else {
    // Handle gempa notification
    const gempa = data;
    const title = source === 'autogempa' ? 'Gempa Terbaru' :
      (source === 'gempaterkini' ? 'Gempa Terkini' : 'Gempa Dirasakan');

    message = {
      blocks: [
        {
          type: 'section',
          text: {
            type: 'mrkdwn',
            text: `🚨 *${title} Terdeteksi!* 🚨`
          }
        },
        {
          type: 'section',
          fields: [
            {
              type: 'mrkdwn',
              text: `*Magnitudo:*\nM${gempa.magnitude}`
            },
            {
              type: 'mrkdwn',
              text: `*Kedalaman:*\n${gempa.kedalaman}`
            },
            {
              type: 'mrkdwn',
              text: `*Lokasi:*\n${gempa.wilayah}`
            },
            {
              type: 'mrkdwn',
              text: `*Waktu:*\n${gempa.datetime || 'N/A'}`
            },
            {
              type: 'mrkdwn',
              text: `*Potensi:*\n${gempa.potensi || 'N/A'}`
            },
            {
              type: 'mrkdwn',
              text: `*Dirasakan:*\n${gempa.dirasakan || 'N/A'}`
            }
          ]
        }
      ]
    };

    if (source === 'autogempa' && gempa.shakemap) {
      message.blocks.push({
        type: 'image',
        image_url: `https://data.bmkg.go.id/DataMKG/TEWS/${gempa.shakemap}`,
        alt_text: 'Shakemap Gempa'
      });
    }
  }

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), HTTP_TIMEOUT);

    const response = await fetch(SLACK_WEBHOOK, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(message),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    if (response.ok) {
      if (source === 'warning') {
        logMessage('INFO', 'Slack notification sent for warning');
      } else {
        logMessage('INFO', `Slack notification sent for ${data.datetime || 'unknown'} from ${source}`);
      }
      return true;
    } else {
      const errorText = await response.text();
      logMessage('ERROR', `Slack notification failed with HTTP ${response.status}: ${errorText}`);
      return false;
    }
  } catch (error) {
    if (error.name === 'AbortError') {
      logMessage('ERROR', 'Slack notification failed: Request timeout');
    } else {
      logMessage('ERROR', `Slack notification failed: ${error.message}`);
    }
    return false;
  }
}

// Fungsi untuk kirim notifikasi Telegram
async function sendTelegramNotification(data, source, env) {
  const { TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID } = env;

  if (!TELEGRAM_BOT_TOKEN || !TELEGRAM_CHAT_ID) {
    logMessage('WARNING', 'Telegram credentials not configured, skipping notification');
    return false;
  }

  let message;
  
  // Handle warning notification
  if (source === 'warning' && data.message) {
    message = data.message;
  } else {
    // Handle gempa notification
    const gempa = data;
    const title = source === 'autogempa' ? 'Gempa Terbaru' :
      (source === 'gempaterkini' ? 'Gempa Terkini' : 'Gempa Dirasakan');

    // Escape semua field untuk keamanan Markdown
    const magnitude = escapeMarkdown(gempa.magnitude);
    const kedalaman = escapeMarkdown(gempa.kedalaman);
    const wilayah = escapeMarkdown(gempa.wilayah);
    const datetime = escapeMarkdown(gempa.datetime || 'N/A');
    const potensi = escapeMarkdown(gempa.potensi || 'N/A');
    const dirasakan = escapeMarkdown(gempa.dirasakan || 'N/A');

    message = `🚨 *${escapeMarkdown(title)} Terdeteksi!* 🚨\n\n` +
      `*Magnitudo:* M${magnitude}\n` +
      `*Kedalaman:* ${kedalaman}\n` +
      `*Lokasi:* ${wilayah}\n` +
      `*Waktu:* ${datetime}\n` +
      `*Potensi:* ${potensi}\n` +
      `*Dirasakan:* ${dirasakan}\n`;

    if (source === 'autogempa' && gempa.shakemap) {
      const shakemapUrl = `https://data.bmkg.go.id/DataMKG/TEWS/${gempa.shakemap}`;
      message += `*Shakemap:* ${shakemapUrl}\n`;
    }
  }

  const url = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
  const params = new URLSearchParams({
    chat_id: TELEGRAM_CHAT_ID,
    text: message,
    parse_mode: 'Markdown'
  });

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), HTTP_TIMEOUT);

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: params.toString(),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    const result = await response.json();
    if (response.ok && result.ok) {
      if (source === 'warning') {
        logMessage('INFO', 'Telegram notification sent for warning');
      } else {
        logMessage('INFO', `Telegram notification sent for ${data.datetime || 'unknown'} from ${source}`);
      }
      return true;
    } else {
      const errorMsg = result.description || `HTTP ${response.status}`;
      logMessage('ERROR', `Telegram API error: ${errorMsg}`);
      return false;
    }
  } catch (error) {
    if (error.name === 'AbortError') {
      logMessage('ERROR', 'Telegram notification failed: Request timeout');
    } else {
      logMessage('ERROR', `Telegram notification failed: ${error.message}`);
    }
    return false;
  }
}

// Fungsi untuk normalisasi datetime
function normalizeDatetime(datetime) {
  if (!datetime) {
    return null;
  }

  try {
    // Coba beberapa format yang mungkin dari BMKG
    const formats = [
      // ISO 8601
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/,
      // ISO 8601 tanpa timezone
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/,
      // Standard format
      /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/
    ];

    // Coba parse langsung
    const date = new Date(datetime);
    if (!isNaN(date.getTime())) {
      return date.toISOString();
    }

    logMessage('WARNING', `Failed to normalize datetime: ${datetime}`);
    return null;
  } catch (error) {
    logMessage('WARNING', `Failed to normalize datetime: ${datetime} - ${error.message}`);
    return null;
  }
}

// Fungsi untuk memeriksa apakah gempa cukup baru
function isRecentGempa(datetime) {
  try {
    const gempaTime = new Date(datetime);
    const now = new Date();
    const diffMs = now - gempaTime;
    const diffHours = diffMs / (1000 * 60 * 60);

    if (diffHours > MAX_AGE_HOURS) {
      logMessage('DEBUG', `Gempa too old: ${datetime} (age: ${diffHours.toFixed(2)} hours)`);
      return false;
    }
    return true;
  } catch (error) {
    logMessage('WARNING', `Failed to check gempa age: ${datetime} - ${error.message}`);
    return false;
  }
}

// Fungsi untuk simpan gempa ke database
async function saveGempa(db, gempa, source) {
  const rawDatetime = gempa.DateTime || null;

  if (!rawDatetime) {
    logMessage('WARNING', `Empty datetime for gempa from source ${source}`);
    return false;
  }

  const datetime = normalizeDatetime(rawDatetime);
  if (!datetime) {
    logMessage('WARNING', `Invalid datetime for gempa from source ${source}: ${rawDatetime}`);
    return false;
  }

  try {
    // Langsung INSERT OR IGNORE
    const result = await db.prepare(`
      INSERT OR IGNORE INTO gempa (
        datetime, tanggal, jam, magnitude, kedalaman, wilayah, lintang, bujur,
        coordinates, potensi, dirasakan, shakemap, source
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).bind(
      datetime,
      gempa.Tanggal || null,
      gempa.Jam || null,
      gempa.Magnitude || null,
      gempa.Kedalaman || null,
      gempa.Wilayah || null,
      gempa.Lintang || null,
      gempa.Bujur || null,
      gempa.point?.coordinates || null,
      gempa.Potensi || null,
      gempa.Dirasakan || null,
      gempa.Shakemap || null,
      source
    ).run();

    if (result.meta.changes > 0) {
      logMessage('INFO', `New gempa saved: ${datetime} from ${source}`);
      return true;
    }
    return false;
  } catch (error) {
    // Jika error karena duplicate, return false (bukan error)
    if (error.message && error.message.includes('UNIQUE constraint')) {
      return false;
    }
    logMessage('ERROR', `Database error saving gempa: ${error.message}`);
    return false;
  }
}

// Fungsi untuk mengambil data JSON dengan retry
async function fetchJsonWithRetry(url, source) {
  for (let attempt = 1; attempt <= RETRY_ATTEMPTS; attempt++) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), HTTP_TIMEOUT);

      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          'User-Agent': 'GempaMonitor/1.0'
        }
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: HTTP ${response.status}`);
        if (attempt < RETRY_ATTEMPTS) {
          await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
        }
        continue;
      }

      const json = await response.json();
      logMessage('DEBUG', `Successfully fetched ${source} data`);
      return json;
    } catch (error) {
      if (error.name === 'AbortError') {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: Request timeout`);
      } else {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: ${error.message}`);
      }

      if (attempt < RETRY_ATTEMPTS) {
        await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
      }
    }
  }

  logMessage('ERROR', `${source} error: Failed to load ${url} after ${RETRY_ATTEMPTS} attempts`);
  return null;
}

// Parsing RSS sederhana (tanpa DOM)
function parseRssItems(rssText) {
  const items = [];
  const itemRegex = /<item>([\s\S]*?)<\/item>/gi;
  let match;
  while ((match = itemRegex.exec(rssText)) !== null) {
    const block = match[1];
    const getTag = (tag) => {
      const m = new RegExp(`<${tag}>([\\s\\S]*?)<\\/${tag}>`, 'i').exec(block);
      return m ? m[1].trim() : '';
    };
    items.push({
      title: getTag('title'),
      link: getTag('link'),
      description: getTag('description'),
      pubDate: getTag('pubDate'),
      identifier: getTag('guid') || getTag('link')
    });
  }
  return items;
}

// Fungsi generic untuk proses data gempa dari berbagai sumber
async function processGempaSource(db, source, isSingleItem, env) {
  const { SEND_TO_SLACK, SEND_TO_TELEGRAM } = env;
  const newGempa = [];

  try {
    if (!GEMPA_URLS[source]) {
      logMessage('ERROR', `Unknown source: ${source}`);
      return 0;
    }

    const data = await fetchJsonWithRetry(GEMPA_URLS[source], source);
    if (!data) {
      throw new Error(`Failed to load data for ${source}`);
    }

    // Struktur JSON BMKG: { Infogempa: { gempa: {...} atau [...] } }
    const info = data?.Infogempa;
    let items = [];

    if (isSingleItem) {
      const gempa = info?.gempa;
      items = gempa ? [gempa] : [];
    } else {
      const list = info?.gempa;
      if (Array.isArray(list)) {
        items = list;
      } else if (list) {
        items = [list];
      }
    }

    for (const gempa of items) {
      // Normalize gempa object structure
      const gempaObj = {
        DateTime: gempa.DateTime,
        Tanggal: gempa.Tanggal,
        Jam: gempa.Jam,
        Magnitude: gempa.Magnitude,
        Kedalaman: gempa.Kedalaman,
        Wilayah: gempa.Wilayah,
        Lintang: gempa.Lintang,
        Bujur: gempa.Bujur,
        point: gempa.point,
        Potensi: gempa.Potensi,
        Dirasakan: gempa.Dirasakan,
        Shakemap: gempa.Shakemap
      };

      if (await saveGempa(db, gempaObj, source)) {
        const datetime = normalizeDatetime(gempaObj.DateTime);
        if (datetime && isRecentGempa(datetime)) {
          const gempaData = {
            datetime: datetime,
            magnitude: gempaObj.Magnitude || '',
            kedalaman: gempaObj.Kedalaman || '',
            wilayah: gempaObj.Wilayah || '',
            potensi: source === 'gempadirasakan' ? '' : (gempaObj.Potensi || ''),
            dirasakan: source === 'gempaterkini' ? '' : (gempaObj.Dirasakan || ''),
            shakemap: source === 'autogempa' ? (gempaObj.Shakemap || '') : ''
          };
          newGempa.push(gempaData);
        }
      }
    }

    // Kirim notifikasi untuk gempa baru
    if (newGempa.length > 0) {
      for (const gempa of newGempa) {
        if (SEND_TO_SLACK === 'true') {
          await sendSlackNotification(gempa, source, env);
        }
        if (SEND_TO_TELEGRAM === 'true') {
          await sendTelegramNotification(gempa, source, env);
        }
      }
    }

    const count = newGempa.length;
    const sourceName = source.charAt(0).toUpperCase() + source.slice(1);
    logMessage('INFO', `${sourceName} checked. New events: ${count}`);
    return count;
  } catch (error) {
    logMessage('ERROR', `${source} error: ${error.message}`);
    return 0;
  }
}

// Fungsi utama untuk proses semua sumber
async function processGempaData(db, env) {
  let totalNew = 0;

  // Process autogempa (single item)
  totalNew += await processGempaSource(db, 'autogempa', true, env);

  // Jeda antar permintaan
  await new Promise(resolve => setTimeout(resolve, 1000));

  // Process gempaterkini (multiple items)
  totalNew += await processGempaSource(db, 'gempaterkini', false, env);

  // Jeda antar permintaan
  await new Promise(resolve => setTimeout(resolve, 1000));

  // Process gempadirasakan (multiple items)
  totalNew += await processGempaSource(db, 'gempadirasakan', false, env);

  logMessage('INFO', `Total new events: ${totalNew}`);
  return totalNew;
}

// Simpan peringatan dini ke DB
async function saveWarning(db, item) {
  const identifier = item.identifier || item.link || item.title;
  
  // Cek dulu apakah data sudah ada
  const checkStmt = db.prepare('SELECT COUNT(*) as count FROM warning WHERE identifier = ?');
  const checkResult = await checkStmt.bind(identifier).first();
  const exists = checkResult && checkResult.count > 0;
  
  if (exists) {
    logMessage('DEBUG', `Warning already exists: ${item.title || 'Unknown'}`);
    return false; // Data sudah ada, tidak perlu insert
  }
  
  const stmt = db.prepare(`INSERT INTO warning
    (identifier, title, link, pubDate, description)
    VALUES (?, ?, ?, ?, ?)`);
  const res = await stmt.bind(
    identifier,
    item.title || '',
    item.link || '',
    item.pubDate || '',
    item.description || ''
  ).run();
  
  if (res.meta.changes > 0) {
    logMessage('DEBUG', `New warning saved: ${item.title || 'Unknown'}`);
    return true;
  } else {
    logMessage('WARNING', `Failed to save warning: ${item.title || 'Unknown'}`);
    return false;
  }
}

// Fetch RSS sebagai text (bukan JSON)
async function fetchRssWithRetry(url, source) {
  for (let attempt = 1; attempt <= RETRY_ATTEMPTS; attempt++) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), HTTP_TIMEOUT);

      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          'User-Agent': 'GempaMonitor/1.0'
        }
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: HTTP ${response.status}`);
        if (attempt < RETRY_ATTEMPTS) {
          await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
        }
        continue;
      }

      const text = await response.text();
      logMessage('DEBUG', `Successfully fetched ${source} RSS data`);
      return text;
    } catch (error) {
      if (error.name === 'AbortError') {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: Request timeout`);
      } else {
        logMessage('WARNING', `Attempt ${attempt} failed for ${source}: ${error.message}`);
      }

      if (attempt < RETRY_ATTEMPTS) {
        await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
      }
    }
  }

  logMessage('ERROR', `${source} error: Failed to load ${url} after ${RETRY_ATTEMPTS} attempts`);
  return null;
}

// Proses peringatan dini (RSS)
async function processWarnings(db, env) {
  const rssText = await fetchRssWithRetry(WARNING_RSS_URL, 'warning');
  if (!rssText) {
    return 0;
  }
  
  const items = parseRssItems(rssText);
  let newCount = 0;
  
  for (const item of items) {
    if (await saveWarning(db, item)) {
      newCount += 1;
      logMessage('INFO', `New warning: ${item.title || 'Unknown'}`);
      
      // Kirim notifikasi untuk peringatan baru
      const { SEND_TO_SLACK, SEND_TO_TELEGRAM } = env;
      if (SEND_TO_SLACK === 'true' || SEND_TO_TELEGRAM === 'true') {
        const message = `⚠️ *Peringatan Dini Cuaca*\n\n` +
          `*Judul:* ${escapeMarkdown(item.title || '')}\n` +
          `*Deskripsi:* ${escapeMarkdown(item.description || '')}\n` +
          `*Waktu:* ${escapeMarkdown(item.pubDate || '')}`;
        
        if (SEND_TO_SLACK === 'true') {
          await sendSlackNotification({ message }, 'warning', env);
        }
        if (SEND_TO_TELEGRAM === 'true') {
          await sendTelegramNotification({ message }, 'warning', env);
        }
      }
    }
  }
  
  logMessage('INFO', `Warnings checked. New events: ${newCount}`);
  return newCount;
}

// Cek apakah cuaca ekstrem
function isExtremeWeather(forecast) {
  const weatherDesc = (forecast.weather_desc || '').toLowerCase();
  const ws = forecast.ws || 0;
  const hu = forecast.hu || 0;
  const tp = forecast.tp || 0; // Curah hujan (mm)
  
  // Cuaca ekstrem berdasarkan deskripsi
  const extremeKeywords = ['hujan lebat', 'hujan sangat lebat', 'angin kencang', 'badai', 'petir', 'ekstrem'];
  for (const keyword of extremeKeywords) {
    if (weatherDesc.includes(keyword)) {
      return true;
    }
  }
  
  // Angin kencang (>40 km/jam)
  if (ws > 40) {
    return true;
  }
  
  // Hujan lebat (curah hujan >50 mm/jam atau tp > 50)
  if (tp > 50) {
    return true;
  }
  
  // Kelembapan sangat tinggi (>95%)
  if (hu > 95) {
    return true;
  }
  
  return false;
}

// Simpan prakiraan cuaca
async function saveWeather(db, adm4, forecast) {
  const localDt = forecast.local_datetime || '';
  
  // Cek dulu apakah data sudah ada
  const checkStmt = db.prepare('SELECT COUNT(*) as count FROM cuaca WHERE adm4 = ? AND local_datetime = ?');
  const checkResult = await checkStmt.bind(adm4, localDt).first();
  const exists = checkResult && checkResult.count > 0;
  
  if (exists) {
    logMessage('DEBUG', `Weather data already exists for ${adm4} at ${localDt}`);
    return { saved: false, forecast: null };
  }
  
  const stmt = db.prepare(`INSERT INTO cuaca
    (adm4, analysis_date, local_datetime, utc_datetime, suhu, kelembapan,
     cuaca, cuaca_en, angin_kecepatan, angin_arah, tutupan_awan, jarak_pandang, payload)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
  const res = await stmt.bind(
    adm4,
    forecast.analysis_date || '',
    forecast.local_datetime || '',
    forecast.utc_datetime || '',
    forecast.t ?? null,
    forecast.hu ?? null,
    forecast.weather_desc || '',
    forecast.weather_desc_en || '',
    forecast.ws ?? null,
    forecast.wd || '',
    forecast.tcc ?? null,
    forecast.vs_text || '',
    JSON.stringify(forecast)
  ).run();
  
  if (res.meta.changes > 0) {
    logMessage('DEBUG', `Saved weather for ${adm4} at ${localDt}`);
    return { saved: true, forecast, adm4 };
  } else {
    logMessage('WARNING', `Failed to save weather for ${adm4} at ${localDt}`);
    return { saved: false, forecast: null };
  }
}

// Proses prakiraan cuaca untuk list adm4
async function processWeather(db, env) {
  const list = (env.CUACA_WILAYAH || '').split(',').map(s => s.trim()).filter(Boolean);
  if (list.length === 0) {
    logMessage('WARNING', 'CUACA_WILAYAH is empty or invalid');
    return 0;
  }
  
  logMessage('INFO', `Processing weather for ${list.length} wilayah: ${list.join(', ')}`);
  let newCount = 0;
  const allNewForecasts = [];
  const allExtremeForecasts = [];
  
  for (const adm4 of list) {
    try {
      const url = `${WEATHER_BASE_URL}${adm4}`;
      logMessage('DEBUG', `Fetching weather for ${adm4} from ${url}`);
      const data = await fetchJsonWithRetry(url, `cuaca-${adm4}`);
      
      if (!data) {
        logMessage('WARNING', `Failed to fetch weather data for ${adm4}`);
        continue;
      }
      
      // Struktur BMKG: { lokasi: {...}, data: [{ lokasi: {...}, cuaca: [[{forecast1}, {forecast2}, ...]] }] }
      let forecastList = [];
      
      if (data.data && Array.isArray(data.data)) {
        logMessage('DEBUG', `Found 'data' key in response for ${adm4} with ${data.data.length} items`);
        for (const item of data.data) {
          if (item.cuaca && Array.isArray(item.cuaca)) {
            logMessage('DEBUG', `Processing ${item.cuaca.length} items in 'cuaca' array for ${adm4}`);
            for (const cuacaItem of item.cuaca) {
              // cuaca bisa berupa nested array: [[{forecast1}, {forecast2}, ...]]
              if (Array.isArray(cuacaItem) && cuacaItem.length > 0) {
                if (cuacaItem[0] && cuacaItem[0].local_datetime) {
                  logMessage('DEBUG', `Found nested array with ${cuacaItem.length} forecast items`);
                  forecastList.push(...cuacaItem);
                }
              } else if (cuacaItem && cuacaItem.local_datetime) {
                // Langsung forecast object
                forecastList.push(cuacaItem);
              }
            }
          } else if (item.local_datetime) {
            // Item langsung forecast object
            forecastList.push(item);
          }
        }
      } else if (Array.isArray(data)) {
        // Struktur langsung array of forecast objects
        if (data.length > 0 && data[0].local_datetime) {
          forecastList = data;
        }
      }
      
      if (forecastList.length === 0) {
        logMessage('WARNING', `Could not find forecast data in response for ${adm4}`);
        logMessage('DEBUG', `Full response structure: ${JSON.stringify(data).substring(0, 500)}`);
        continue;
      }
      
      logMessage('DEBUG', `Processing ${forecastList.length} valid forecast entries for ${adm4}`);
      
      const newForecasts = [];
      const extremeForecasts = [];
      
      for (const forecast of forecastList) {
        if (!forecast.local_datetime) {
          continue;
        }
        
        const result = await saveWeather(db, adm4, forecast);
        if (result.saved) {
          newCount += 1;
          newForecasts.push(result);
          
          // Cek apakah cuaca ekstrem
          if (isExtremeWeather(forecast)) {
            extremeForecasts.push(result);
          }
        }
      }
      
      allNewForecasts.push(...newForecasts);
      allExtremeForecasts.push(...extremeForecasts);
      
      // Kirim notifikasi untuk cuaca ekstrem (prioritas tinggi)
      const { SEND_TO_SLACK, SEND_TO_TELEGRAM } = env;
      if (extremeForecasts.length > 0 && (SEND_TO_SLACK === 'true' || SEND_TO_TELEGRAM === 'true')) {
        for (const result of extremeForecasts) {
          const f = result.forecast;
          const wilayahCode = result.adm4;
          const message = `⚠️ *Peringatan Cuaca Ekstrem*\n\n` +
            `*Wilayah:* ${wilayahCode}\n` +
            `*Waktu:* ${escapeMarkdown(f.local_datetime || '')}\n` +
            `*Cuaca:* ${escapeMarkdown(f.weather_desc || '')}\n` +
            `*Suhu:* ${f.t ?? 'N/A'}°C\n` +
            `*Kelembapan:* ${f.hu ?? 'N/A'}%\n` +
            `*Angin:* ${escapeMarkdown(f.wd || '')} ${f.ws ?? 'N/A'} km/jam\n` +
            `*Curah Hujan:* ${f.tp ?? '0'} mm\n` +
            `*Jarak Pandang:* ${escapeMarkdown(f.vs_text || '')}`;
          
          if (SEND_TO_SLACK === 'true') {
            await sendSlackNotification({ message }, 'warning', env);
          }
          if (SEND_TO_TELEGRAM === 'true') {
            await sendTelegramNotification({ message }, 'warning', env);
          }
          
          logMessage('INFO', `Extreme weather notification sent for ${wilayahCode} at ${f.local_datetime || ''}`);
        }
      }
    } catch (error) {
      logMessage('ERROR', `Weather error for ${adm4}: ${error.message}`);
    }
  }
  
  logMessage('INFO', `Weather processed. New rows: ${newCount}`);
  
  // Notifikasi harian untuk prakiraan cuaca (sekali saat ada data baru)
  const { SEND_TO_SLACK, SEND_TO_TELEGRAM } = env;
  if (newCount > 0 && (SEND_TO_SLACK === 'true' || SEND_TO_TELEGRAM === 'true')) {
    const dailySummary = {};
    
    for (const adm4 of list) {
      const stmt = db.prepare(`
        SELECT * FROM cuaca 
        WHERE adm4 = ? 
        AND datetime(local_datetime) >= datetime('now', 'localtime')
        AND datetime(local_datetime) <= datetime('now', 'localtime', '+24 hours')
        ORDER BY local_datetime ASC
        LIMIT 8
      `);
      const forecasts = await stmt.bind(adm4).all();
      
      if (forecasts.results && forecasts.results.length > 0) {
        dailySummary[adm4] = forecasts.results;
      }
    }
    
    if (Object.keys(dailySummary).length > 0) {
      let message = `📅 *Prakiraan Cuaca Harian*\n\n`;
      
      for (const [adm4, forecasts] of Object.entries(dailySummary)) {
        message += `*Wilayah: ${adm4}*\n`;
        message += `Prakiraan 24 jam ke depan:\n`;
        
        for (const f of forecasts.slice(0, 8)) {
          const time = new Date(f.local_datetime).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
          const cuaca = f.cuaca || 'N/A';
          const suhu = f.suhu !== null ? `${f.suhu}°C` : 'N/A';
          message += `• ${time}: ${cuaca}, ${suhu}\n`;
        }
        message += `\n`;
      }
      
      if (SEND_TO_SLACK === 'true') {
        await sendSlackNotification({ message }, 'warning', env);
      }
      if (SEND_TO_TELEGRAM === 'true') {
        await sendTelegramNotification({ message }, 'warning', env);
      }
      
      logMessage('INFO', `Daily weather forecast notification sent for ${Object.keys(dailySummary).length} wilayah`);
    }
  }
  
  return newCount;
}

// Main handler untuk Cloudflare Worker
export default {
  async scheduled(event, env, ctx) {
    // Handler untuk Cron Trigger
    logMessage('INFO', 'Starting scheduled gempa check...');

    // Validasi konfigurasi
    if (!validateConfig(env)) {
      logMessage('ERROR', 'Configuration validation failed. Please check your environment variables.');
      return;
    }

    // Inisialisasi database
    await initDb(env.DB);

    // Proses data gempa (selalu)
    await processGempaData(env.DB, env);

    const now = new Date(event.scheduledTime);
    const minute = now.getUTCMinutes();

    // Peringatan dini: setiap 5 menit (minute % 5 === 0)
    if (minute % WARNING_INTERVAL_MIN === 0) {
      logMessage('INFO', `Processing warnings (minute ${minute} is divisible by ${WARNING_INTERVAL_MIN})`);
      await processWarnings(env.DB, env);
    } else {
      logMessage('DEBUG', `Skipping warnings: minute=${minute} (not divisible by ${WARNING_INTERVAL_MIN})`);
    }

    // Prakiraan cuaca: setiap 60 menit (minute % 60 === 0, yaitu saat menit = 0)
    if (minute % WEATHER_INTERVAL_MIN === 0) {
      logMessage('INFO', `Processing weather (minute ${minute} is divisible by ${WEATHER_INTERVAL_MIN})`);
      if (!env.CUACA_WILAYAH) {
        logMessage('WARNING', 'CUACA_WILAYAH not configured, skipping weather check');
      } else {
        logMessage('DEBUG', `CUACA_WILAYAH configured: ${env.CUACA_WILAYAH}`);
        await processWeather(env.DB, env);
      }
    } else {
      logMessage('DEBUG', `Skipping weather: minute=${minute} (not divisible by ${WEATHER_INTERVAL_MIN})`);
    }
  },

  async fetch(request, env, ctx) {
    // Handler untuk HTTP requests (optional - untuk manual trigger atau health check)
    const url = new URL(request.url);

    if (url.pathname === '/health') {
      return new Response(JSON.stringify({ status: 'ok', timestamp: new Date().toISOString() }), {
        headers: { 'Content-Type': 'application/json' }
      });
    }

    if (url.pathname === '/trigger' && request.method === 'POST') {
      // Manual trigger untuk testing
      logMessage('INFO', 'Manual trigger received');

      if (!validateConfig(env)) {
        return new Response(JSON.stringify({ error: 'Configuration validation failed' }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' }
        });
      }

      await initDb(env.DB);
      const totalNew = await processGempaData(env.DB, env);
      const warningCount = await processWarnings(env.DB, env);
      const weatherCount = await processWeather(env.DB, env);

      return new Response(JSON.stringify({ 
        success: true, 
        gempaEvents: totalNew,
        warningEvents: warningCount,
        weatherEvents: weatherCount,
        timestamp: new Date().toISOString()
      }), {
        headers: { 'Content-Type': 'application/json' }
      });
    }

    return new Response('Not Found', { status: 404 });
  }
};
