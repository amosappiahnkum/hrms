# Use the official PHP 8.2 image as the base image
FROM php:8.2-fpm

# Install system dependencies, including libzip for zip extension support
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_pgsql bcmath zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy the application code
COPY . /var/www

# Set the correct permissions
RUN chown -R www-data:www-data /var/www /var/www/storage

RUN chmod -R 777 /var/www/storage /var/www/bootstrap/cache

# Expose port 9000 and start PHP-FPM server
EXPOSE 9000
CMD ["php-fpm"]
