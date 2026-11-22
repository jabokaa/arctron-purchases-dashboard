FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql intl opcache \
    && rm -rf /var/lib/apt/lists/*

# PCOV: mede a cobertura de código dos testes (php bin/phpunit --coverage-text)
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
