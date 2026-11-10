
# syntax=docker/dockerfile:1.7

FROM php:8.2-cli

# Limit parallel compilation to avoid exhausting 8GB RAM
ENV MAKEFLAGS="-j2"

# Install system dependencies and PHP extensions
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        curl \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip

# Install gRPC PHP extension
# Keep this layer before copying Laravel application files
RUN MAKEFLAGS="-j2" \
    pecl install grpc-1.85.0 \
    && docker-php-ext-enable grpc \
    && php --ri grpc

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy Laravel application
COPY . .

# Install Laravel dependencies
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
