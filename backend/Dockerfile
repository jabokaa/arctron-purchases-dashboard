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

# Dependências instaladas no build, em /app/vendor.
# Só os arquivos do Composer entram primeiro: enquanto eles não mudam, o Docker reaproveita esta camada.
# --no-scripts: os scripts do Symfony (cache:clear, assets:install) precisam do código e do .env; o cache é
# gerado sozinho quando a aplicação sobe.
# No docker-compose.yml o /app/vendor é um volume próprio, para a pasta local (./backend:/app) não esconder
# o vendor/ da imagem. Depois de mudar as dependências: docker compose up -d --build -V
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-interaction

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
