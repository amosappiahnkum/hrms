FROM php:8.4-fpm

# Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libicu-dev \
    g++ \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
       pdo \
       pdo_mysql \
       bcmath \
       ctype \
       pcntl \
       mbstring \
       zip \
       intl \
       gd \
       xml \
       sockets

# Redis PHP extension
RUN pecl install redis && docker-php-ext-enable redis

# Opcache (performance)
RUN docker-php-ext-install opcache

# Raise upload limits
RUN printf "upload_max_filesize=100M\npost_max_size=105M\nmemory_limit=256M\nmax_execution_time=300\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Set working directory
WORKDIR /var/www

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
