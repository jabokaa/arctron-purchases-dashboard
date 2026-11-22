FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql intl opcache \
    && rm -rf /var/lib/apt/lists/*

# PCOV: mede a cobertura de código dos testes (php bin/phpunit --coverage-text)
# AMQP: conexão com o RabbitMQ, usada pelo Symfony Messenger (fila dos webhooks do WooCommerce)
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS librabbitmq-dev \
    && pecl install pcov amqp \
    && docker-php-ext-enable pcov amqp \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get install -y --no-install-recommends librabbitmq4 \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
