# arctron-purchases-dashboard

Stack: **Symfony** (backend) · **PostgreSQL** (banco) · **RabbitMQ** (fila) · **React + Vite + TypeScript** (frontend)

## Estrutura

```
backend/   Symfony 8 (Doctrine ORM, Messenger)
frontend/  React + Vite + TypeScript
docker-compose.yml
```

## Subir o projeto

```bash
docker compose up -d --build
docker compose exec backend php bin/console doctrine:migrations:migrate -n
```

| Serviço    | Endereço                                   |
|------------|--------------------------------------------|
| Backend    | http://localhost:8088                      |
| Frontend   | http://localhost:5173                      |
| PostgreSQL | localhost:5432 (app/app, db `app`)         |
| RabbitMQ   | localhost:5672 · painel http://localhost:15672 (guest/guest) |
| Worker     | container `worker` (consome a fila do RabbitMQ) |

## Webhook do WooCommerce

`POST /api/webhooks/woocommerce` recebe os pedidos criados no WooCommerce:

1. valida a assinatura HMAC (`X-WC-Webhook-Signature`) com o segredo `WOOCOMMERCE_WEBHOOK_SECRET`
   (401 se for inválida);
2. grava o payload na tabela `integration_events` e publica a mensagem no RabbitMQ;
3. responde `202 Accepted` na hora;
4. o container `worker` converte o pedido em compra (canal `WOOCOMMERCE`), com as mesmas validações
   e o mesmo service do `POST /api/purchases`. O resultado fica no evento: `processed`, `ignored` ou `failed`.

No WooCommerce (Configurações > Avançado > Webhooks): tópico **Pedido criado**, URL de entrega
`https://<seu-dominio>/api/webhooks/woocommerce` e o mesmo segredo do `WOOCOMMERCE_WEBHOOK_SECRET`.
Em desenvolvimento o segredo é `dev-webhook-secret` (`backend/.env`); em produção, defina o valor real
em `backend/.env.local` ou numa variável de ambiente.

```bash
docker compose logs -f worker   # acompanhar o processamento dos pedidos
```

## Testes

```bash
docker compose exec backend php bin/phpunit --testsuite unit          # sem banco (mocks/stubs)
docker compose exec backend php bin/phpunit --testsuite integration   # banco de teste real (app_test)
docker compose exec backend php bin/phpunit --coverage-text           # todos, com cobertura
```

## Comandos úteis

```bash
docker compose exec backend php bin/console about
docker compose exec backend composer require <pacote>
docker compose exec backend php bin/console doctrine:migrations:migrate
```
