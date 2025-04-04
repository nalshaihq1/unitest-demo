# Use official PHP 8.2 image as base
FROM php:8.2-cli

# Set working directory
WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . /app

# Set permissions for /app
RUN chown -R www-data:www-data /app && chmod -R 775 /app

## Install PHP dependencies
#RUN composer install --dev --no-interaction --prefer-dist

# Command to keep container running or run tests
CMD ["php", "-a"]