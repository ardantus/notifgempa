// Konfigurasi
const DATA_URLS = {
  gempaterkini: 'https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.json',
  autogempa: 'https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json',
  gempadirasakan: 'https://data.bmkg.go.id/DataMKG/TEWS/gempadirasakan.json'
};

const RETRY_ATTEMPTS = 3;
const RETRY_DELAY = 5000; // 5 detik dalam milliseconds
const MAX_AGE_HOURS = 24;
const HTTP_TIMEOUT = 30000; // 30 detik dalam milliseconds

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

    // Gunakan prepare().run() agar hasil objek tidak undefined di lingkungan D1
    await db.prepare(ddl).run();
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
async function sendSlackNotification(gempa, source, env) {
  const { SLACK_WEBHOOK } = env;

  if (!SLACK_WEBHOOK) {
    logMessage('WARNING', 'Slack webhook not configured, skipping notification');
    return false;
  }

  const title = source === 'autogempa' ? 'Gempa Terbaru' :
    (source === 'gempaterkini' ? 'Gempa Terkini' : 'Gempa Dirasakan');

  const message = {
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
            text: `*Waktu:*\n${gempa.datetime}`
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
      logMessage('INFO', `Slack notification sent for ${gempa.datetime} from ${source}`);
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
async function sendTelegramNotification(gempa, source, env) {
  const { TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID } = env;

  if (!TELEGRAM_BOT_TOKEN || !TELEGRAM_CHAT_ID) {
    logMessage('WARNING', 'Telegram credentials not configured, skipping notification');
    return false;
  }

  const title = source === 'autogempa' ? 'Gempa Terbaru' :
    (source === 'gempaterkini' ? 'Gempa Terkini' : 'Gempa Dirasakan');

  // Escape semua field untuk keamanan Markdown
  const magnitude = escapeMarkdown(gempa.magnitude);
  const kedalaman = escapeMarkdown(gempa.kedalaman);
  const wilayah = escapeMarkdown(gempa.wilayah);
  const datetime = escapeMarkdown(gempa.datetime);
  const potensi = escapeMarkdown(gempa.potensi || 'N/A');
  const dirasakan = escapeMarkdown(gempa.dirasakan || 'N/A');

  let message = `🚨 *${escapeMarkdown(title)} Terdeteksi!* 🚨\n\n` +
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

  const url = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
  const data = new URLSearchParams({
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
      body: data.toString(),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    const result = await response.json();
    if (response.ok && result.ok) {
      logMessage('INFO', `Telegram notification sent for ${gempa.datetime} from ${source}`);
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

// Fungsi generic untuk proses data gempa dari berbagai sumber
async function processGempaSource(db, source, isSingleItem, env) {
  const { SEND_TO_SLACK, SEND_TO_TELEGRAM } = env;
  const newGempa = [];

  try {
    if (!DATA_URLS[source]) {
      logMessage('ERROR', `Unknown source: ${source}`);
      return 0;
    }

    const data = await fetchJsonWithRetry(DATA_URLS[source], source);
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

    // Proses data gempa
    await processGempaData(env.DB, env);
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

      return new Response(JSON.stringify({ 
        success: true, 
        newEvents: totalNew,
        timestamp: new Date().toISOString()
      }), {
        headers: { 'Content-Type': 'application/json' }
      });
    }

    return new Response('Not Found', { status: 404 });
  }
};
