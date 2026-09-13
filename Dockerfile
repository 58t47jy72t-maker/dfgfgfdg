FROM php:8.2-apache

# Install sqlite3, zip extensions in one layer
RUN apt-get update && apt-get install -y libzip-dev sqlite3 && \
    docker-php-ext-install zip pdo pdo_sqlite && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy server files
COPY hidden3.php check.php index.php bypass_complete_report.php badfile.php \
     downloads.28.png BLDatabaseManager3.png Accounts3.sqlite ./
COPY Maker/ ./Maker/
COPY cache/ ./cache/ 2>/dev/null || true

# Ensure /tmp is writable (used for cache/ratelimit/etc on cloud)
RUN mkdir -p /tmp/hidden_server && chmod 777 /tmp/hidden_server

# Apache: enable rewrite, allow overrides
RUN a2enmod rewrite && \
    echo '<Directory /var/www/html>\n    AllowOverride All\n    Options -Indexes\n</Directory>' \
    > /etc/apache2/conf-available/app.conf && \
    a2enconf app

# Apache listens on PORT env var (Railway sets this dynamically)
RUN sed -i 's/Listen 80/Listen ${PORT:-80}/' /etc/apache2/ports.conf && \
    sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT:-80}>/' /etc/apache2/sites-enabled/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]
