# FreeScout editable-source Dockerfile for tester-env
# Uses php:8.2-apache with MariaDB via docker-compose
# Source is bind-mounted for mutation workflow

FROM php:8.2-apache-bookworm

# Install PHP extensions required by FreeScout/Laravel
# Note: json, ctype, tokenizer, fileinfo are built-in PHP 8.2
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libonig-dev \
        libxml2-dev \
        mariadb-client \
        git \
        unzip \
        ; \
    docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
        ; \
    docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        xml \
        zip \
        gd \
        bcmath \
        ; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Configure Apache for FreeScout's public/ directory
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf; \
    sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/c\<Directory /var/www/html/public>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>' /etc/apache2/apache2.conf; \
    echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# PHP config
RUN { \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 50M'; \
        echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/freescout.ini

# Copy entrypoint
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]