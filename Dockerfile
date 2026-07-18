FROM php:8.2-apache

# 1. Install system dependencies for Composer and extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# 2. Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 3. Enable Apache mod_rewrite for router flexibility (if needed)
RUN a2enmod rewrite

# 4. Set working directory to default web root
WORKDIR /var/www/html

# 5. Copy app source files
COPY composer.json ./
COPY index.php ./

# 6. Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Copy helper entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Adjust permissions for www-data
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]