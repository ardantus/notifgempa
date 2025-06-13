FROM php:8.1-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean

# Install SQLite
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get clean

# Set working directory
WORKDIR /app

# Copy application files
COPY app/ .

# Command to run the script
CMD ["php", "gempa_monitor.php"]