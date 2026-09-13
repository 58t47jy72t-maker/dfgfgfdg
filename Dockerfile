FROM php:8.2-apache

# 1. Install dependencies
RUN apt-get update && apt-get install -y libzip-dev sqlite3 libsqlite3-dev && \
    docker-php-ext-install zip pdo pdo_sqlite && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# 2. Copy project files
COPY . .

# 3. Ensure cloud cache directory exists and is writable
RUN mkdir -p /tmp/hidden_server && chmod 777 /tmp/hidden_server

# 4. FIX FOR AH00534: Explicitly disable conflicting MPMs and enable only prefork
RUN a2dismod mpm_event mpm_worker || true && \
    a2enmod mpm_prefork || true && \
    a2enmod rewrite

# 5. Configure Apache overrides
RUN printf "<Directory /var/www/html>\n    AllowOverride All\n    Options -Indexes\n</Directory>\n" > /etc/apache2/conf-available/app.conf && \
    a2enconf app

# 6. Bind to Railway's dynamic PORT
RUN sed -i 's/Listen 80/Listen ${PORT:-80}/' /etc/apache2/ports.conf && \
    sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT:-80}>/' /etc/apache2/sites-enabled/000-default.conf

EXPOSE 80
CMD ["apache2-foreground"]
