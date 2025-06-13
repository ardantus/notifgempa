# Earthquake Notification System

This project is a PHP-based application that monitors earthquake data from BMKG (Indonesian Meteorological, Climatological, and Geophysical Agency) and sends real-time notifications to Slack and Telegram. It fetches data from BMKG's XML feeds (`autogempa`, `gempaterkini`, and `gempadirasakan`), stores unique events in a SQLite database, and ensures notifications are sent only for recent earthquakes (within the last 24 hours).

## Features
- Fetches earthquake data from BMKG's XML feeds.
- Stores data in a SQLite database with deduplication.
- Sends notifications to Slack and Telegram for new earthquakes.
- Configurable via environment variables.
- Runs in a Docker container for easy deployment.

## Requirements
- Docker and Docker Compose
- PHP 8.0+ with PDO-SQLite and cURL extensions
- Slack Webhook URL and/or Telegram Bot Token and Chat ID

## Installation
1. Clone the repository:
   ```bash
   git clone https://github.com/ardantus/notifgempa
   cd notifgempa
   ```
2. Create a `.env` file based on `.env.example`:
   ```bash
   cp .env.example .env
   ```
3. Configure `.env` with your Slack and Telegram credentials:
   ```env
   SLACK_WEBHOOK=https://hooks.slack.com/services/your/webhook/url
   TELEGRAM_BOT_TOKEN=your_telegram_bot_token
   TELEGRAM_CHAT_ID=your_telegram_chat_id
   SEND_TO_SLACK=true
   SEND_TO_TELEGRAM=true
   ```
4. Build and run the Docker container:
   ```bash
   docker-compose up --build -d
   ```

## Usage
- The application runs continuously, checking BMKG feeds every 15 minutes.
- New earthquake events trigger notifications to configured Slack and/or Telegram channels.
- Logs are available via:
  ```bash
  docker-compose logs -f
  ```
- Earthquake data is stored in `data/gempa.db` (SQLite database).

## Project Structure
```
project/
├── Dockerfile
├── app/
│   └── gempa_monitor.php
├── docker-compose.yml
├── .env
└── data/
    └── gempa.db
```

## Configuration
Edit `.env` to customize:
- `SLACK_WEBHOOK`: Slack Webhook URL for notifications.
- `TELEGRAM_BOT_TOKEN`: Telegram bot token.
- `TELEGRAM_CHAT_ID`: Telegram chat ID for notifications.
- `SEND_TO_SLACK`: Enable/disable Slack notifications (`true`/`false`).
- `SEND_TO_TELEGRAM`: Enable/disable Telegram notifications (`true`/`false`).

## Debugging
- Check logs for errors or notification status:
  ```bash
  docker-compose logs -f
  ```
- Inspect the SQLite database:
  ```bash
  sqlite3 data/gempa.db
  SELECT * FROM gempa;
  ```
- Test Telegram configuration:
  ```bash
  curl -X POST "https://api.telegram.org/bot<your_bot_token>/sendMessage" -d "chat_id=<your_chat_id>&text=Test"
  ```

## License
This project is licensed under the MIT License. See [LICENSE](#license) for details.

### MIT License
Copyright (c) 2025 Ardan Ari Tri Wibowo

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.