FROM php:8.1-cli

# Install all dependencies in a single layer for better caching
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install curl pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy application files
COPY app/ .

# Command to run the script
CMD ["php", "gempa_monitor.php"]
