FROM php:8.2-cli

# 1. Install all dependencies
RUN apt-get update && apt-get install -y libzip-dev sqlite3 libsqlite3-dev && \
    docker-php-ext-install zip pdo pdo_sqlite && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# 2. Copy the files
COPY . .

# 3. Create the writable cache directory for the cloud
RUN mkdir -p /tmp/hidden_server && chmod 777 /tmp/hidden_server

# 4. Create a mini-router to protect the template files (Replaces .htaccess)
RUN echo '<?php' > router.php && \
    echo '$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);' >> router.php && \
    echo '$b = basename($p);' >> router.php && \
    echo 'if (in_array($b, ["fixedfile", "belliloveu.png", "apllefuckedhhh.png"])) return false;' >> router.php && \
    echo 'if (preg_match("/\.(sqlite|db|png|log|json|htaccess)$/i", $p)) { http_response_code(403); exit; }' >> router.php && \
    echo 'return false;' >> router.php

EXPOSE 80

# 5. Start the lightweight built-in PHP server directly on Railway's port
CMD php -S 0.0.0.0:${PORT:-80} router.php
