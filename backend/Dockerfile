FROM php:8.4-cli-alpine
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev linux-headers $PHPIZE_DEPS && docker-php-ext-install pdo_mysql mbstring intl zip pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
