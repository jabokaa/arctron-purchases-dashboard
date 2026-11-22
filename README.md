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

## Login

Depois de subir o projeto, rode as migrations e o seeder do usuário padrão:

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:fixtures:load --append --group=users
```

> Use sempre `--append`: sem ele o Doctrine apaga **todas** as tabelas antes de rodar o seeder.
> O seeder não duplica: se o usuário já existe, nada muda.

| Usuário padrão | |
|---|---|
| E-mail | `admin@cbf.com.br` |
| Senha  | `cbf@2026` |

Os dados vêm de `DEFAULT_USER_NAME`, `DEFAULT_USER_EMAIL` e `DEFAULT_USER_PASSWORD` no `backend/.env`
(em produção, troque a senha via `.env.local` ou variável de ambiente).

| Endpoint | Auth | Descrição |
|---|---|---|
| `POST /api/auth/login` | pública | `{"email", "password"}` → `{"token", "token_type", "expires_at", "user"}` |
| `GET /api/auth/me` | Bearer | usuário logado |
| `POST /api/auth/logout` | Bearer | invalida o token (204) |

O token vale `AUTH_TOKEN_TTL` segundos (padrão 8 h) e é enviado como `Authorization: Bearer <token>`.
No banco fica só o hash SHA-256 do token. O front chama `/api` pelo proxy do Vite (sem CORS).

## Produtos de teste

Seeder com 11 artigos de futebol (chuteiras, bolas, luvas, camisas da Seleção Brasileira...), com imagem:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --group=products
```

Cada produto também recebe o seu código no canal `API` (igual ao código interno, ex.: `CHU-001`),
então uma compra pelo `POST /api/purchases` com `"external_id": "CHU-001"` usa o produto cadastrado.
As imagens são fotos com licença livre do Wikimedia Commons; autor e licença de cada uma estão em
`backend/src/DataFixtures/ProductFixtures.php`.

CRUD do catálogo: `GET/POST /api/products` e `GET/PUT/DELETE /api/products/{id}`
(campos `name`, `internal_code` e `image_url`; produto que já foi vendido não pode ser removido).

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
