FROM php:8.2-cli

RUN apt-get update && apt-get install -y git unzip libzip-dev && docker-php-ext-install zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /app
WORKDIR /app

RUN composer install --no-dev

CMD echo "$OCI_PRIVATE_KEY_BASE64" | base64 -d > private_key.pem && export OCI_PRIVATE_KEY_FILENAME="$(pwd)/private_key.pem" && php -S 0.0.0.0:$PORT
