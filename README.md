# arctron-purchases-dashboard

Dashboard de compras: um serviço REST que recebe e consulta compras e devolve dados agregados, um painel em React
e a integração com uma loja WooCommerce, que envia cada pedido novo para o serviço.

**Stack:** Symfony 8 (PHP 8.4) · PostgreSQL 16 · RabbitMQ · React + Vite + TypeScript · WooCommerce

```
backend/      API em Symfony (Doctrine ORM, Messenger)
frontend/     Painel em React + Vite + TypeScript + Tailwind
woocommerce/  Loja WooCommerce de desenvolvimento, já ligada ao webhook do backend
```

## Sobre o ambiente Docker

O `docker-compose.yml` foi pensado **só para desenvolvimento**: o objetivo é subir tudo com um comando, já com
dados de exemplo, para avaliar e testar o projeto. **Ele não deve ser usado em produção.** Alguns motivos:

- o backend roda no servidor embutido do PHP (`php -S`) e o frontend no servidor de desenvolvimento do Vite, em vez
  de um servidor web de produção (Nginx/PHP-FPM e arquivos estáticos do `npm run build`);
- o código local é montado dentro dos containers, para as alterações aparecerem sem rebuild;
- senhas, tokens e o segredo do webhook são valores fixos de desenvolvimento (`backend/.env`, `woocommerce/.env`);
- a loja WooCommerce usa um plugin (`woocommerce/mu-plugins/dev-webhooks.php`) que libera o webhook para hosts
  internos, algo que não se faz numa loja real;
- as portas dos bancos e do RabbitMQ ficam abertas no host.

Em produção, os segredos devem vir de variáveis de ambiente (ou `backend/.env.local`) e cada serviço precisa de uma
imagem própria para produção.

## Como executar

Requisito: Docker com Docker Compose v2.

```bash
docker compose up -d --build
```

Pronto. Na primeira vez a loja WooCommerce leva alguns minutos para ser instalada; acompanhe com
`docker compose logs -f wp_cli` até aparecer "Pronto!".

| O quê | Endereço | Acesso |
|---|---|---|
| Painel (frontend) | http://localhost:5173 | `admin@cbf.com.br` / `cbf@2026` |
| API (backend) | http://localhost:8088 | `Authorization: Bearer dev-api-access-token` |
| Loja WooCommerce | http://localhost:8081 · admin em `/wp-admin` | `admin` / `admin` |
| RabbitMQ (painel) | http://localhost:15672 | `guest` / `guest` |
| PostgreSQL | localhost:5432 | `app` / `app`, banco `app` |

O banco já começa com um usuário, 11 produtos e 40 compras de exemplo, então o dashboard abre preenchido.

### Testar

**Criar uma compra pela API:**

```bash
curl -X POST http://localhost:8088/api/purchases \
  -H "Authorization: Bearer dev-api-access-token" \
  -H "Content-Type: application/json" \
  -d '{
    "reference_code": "PED-1001",
    "purchased_at": "2026-11-21T14:30:00-03:00",
    "notes": "Produto enviado para o cliente por SEDEX",
    "customer": { "name": "João Silva", "email": "joao.silva@email.com", "cpf": "529.982.247-25" },
    "products": [
      { "external_id": "CHU-001", "name": "Chuteira", "quantity": 2, "unit_price": 25000, "discount": 5000 }
    ]
  }'
```

Enviar o mesmo `reference_code` de novo devolve `409` e nada é gravado.

**Criar um pedido no WooCommerce** (pelo checkout da loja ou pelo script abaixo). O pedido chega ao backend pelo
webhook e aparece em **Compras** no painel com o código `WC-<número do pedido>`:

```bash
docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cpf
```

**Testes automatizados e análise estática:**

```bash
docker compose exec backend php bin/phpunit                 # testes unitários e de integração
docker compose exec backend vendor/bin/phpstan analyse      # PHPStan
docker compose exec frontend npm test                       # Vitest
docker compose exec frontend npm run lint                   # oxlint
```

O mesmo roda no CI (`.github/workflows/ci.yml`) a cada pull request.

### Parar e limpar

```bash
docker compose down       # para os containers e mantém os dados
docker compose down -v    # apaga tudo, inclusive os bancos (na próxima subida os dados de exemplo voltam)
```

## Como funciona o build

O `docker compose up -d --build` sobe 8 containers. O `docker-compose.yml` da raiz define o painel e inclui
(`include:`) o `woocommerce/docker-compose.yml`, então tudo fica no mesmo projeto e na mesma rede.

```
database (PostgreSQL) ─┐
rabbitmq ──────────────┼─> backend ──> frontend
                       └─> worker
woo_db (MariaDB) ──> wordpress ──> wp_cli (configura a loja e encerra)
```

Os containers só sobem depois que as dependências passam no healthcheck (`depends_on` com `service_healthy`).

**backend** — imagem construída pelo `backend/Dockerfile`:

1. parte do `php:8.4-cli` e instala as extensões `pdo_pgsql`, `intl`, `opcache`, `amqp` (RabbitMQ) e `pcov`
   (cobertura de testes);
2. copia primeiro só o `composer.json`/`composer.lock` e roda `composer install`: enquanto as dependências não
   mudam, o Docker reaproveita essa camada e o build fica rápido;
3. copia o código e sobe o servidor na porta 8000 (8088 no host).

No compose, a pasta `backend/` é montada em `/app` para as alterações valerem na hora, e o `/app/vendor` fica num
volume próprio para não ser escondido pela pasta local. Por isso, **depois de mudar dependências do Composer**,
suba com `-V` para recriar esse volume: `docker compose up -d --build -V`.

Assim que o backend sobe, o `post_start` roda as migrations. Num banco novo elas criam as tabelas e cadastram os
dados de exemplo; num banco existente só rodam as migrations novas.

**worker** — usa a mesma imagem do backend, mas roda `messenger:consume`: lê da fila do RabbitMQ os pedidos que
chegaram pelo webhook do WooCommerce e os transforma em compras. Reinicia a cada hora para não acumular memória.

**frontend** — não tem imagem própria: usa `node:24-alpine`, roda `npm install` e o servidor de desenvolvimento do
Vite. O Vite repassa `/api` e `/uploads` para o backend, então o navegador não precisa de CORS.

**WooCommerce** — `woo_db` (MariaDB) e `wordpress` são imagens oficiais. O `wp_cli` roda o
`woocommerce/scripts/setup.sh` uma vez: instala o WooCommerce em pt-BR com campos de CPF/CNPJ, cadastra os mesmos
produtos do painel e cria o webhook **Pedido criado** apontando para `http://backend:8000/api/webhooks/woocommerce`.
Pode rodar de novo sem duplicar nada.

### Fluxo de um pedido do WooCommerce

1. A loja envia o pedido para `POST /api/webhooks/woocommerce`, assinado com HMAC.
2. O backend confere a assinatura, grava o pedido em `integration_events`, publica na fila e responde `202`.
3. O `worker` processa a mensagem com o mesmo serviço do `POST /api/purchases` e marca o evento como
   `processed`, `ignored` (compra repetida) ou `failed`.

O segredo do webhook precisa ser igual nos dois lados: `WOO_WEBHOOK_SECRET` (`woocommerce/.env`) e
`WOOCOMMERCE_WEBHOOK_SECRET` (`backend/.env`).

## API

Todas as rotas exigem `Authorization: Bearer <token>`: o token do login (`POST /api/auth/login`) ou o token fixo
`API_ACCESS_TOKEN` (em dev, `dev-api-access-token`). Os valores em dinheiro são inteiros em centavos
(`25000` = R$ 250,00).

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/api/auth/login` | Login (`email`, `password`); devolve o token |
| `POST` | `/api/purchases` | Processa uma compra (`201`; `409` se o código de referência já existe; `422` se inválida) |
| `GET` | `/api/purchases` | Lista e filtra compras (paginado) |
| `GET` | `/api/purchases/{id}` | Detalhe de uma compra |
| `GET` | `/api/reports/summary` | Quantidade, totais e médias de compras, descontos e produtos |
| `GET` | `/api/reports/customers` | Total e média de compras por cliente |
| `GET` | `/api/reports/products` | Total e média de compras por produto |
| `GET` | `/api/reports/timeline` | Compras por dia, semana ou mês (`interval`) |
| `GET` `POST` `PUT` `DELETE` | `/api/products[/{id}]` | Catálogo de produtos |
| `POST` | `/api/webhooks/woocommerce` | Pedidos do WooCommerce (autenticado pela assinatura HMAC) |

O valor e o desconto da compra são calculados a partir dos itens, para nunca ficarem inconsistentes.

**Filtros de `GET /api/purchases`** (todos opcionais e combináveis):

| Parâmetro | Filtro |
|---|---|
| `reference_code` | Parte do código de referência |
| `customer_name` · `customer_email` · `customer_document` | Nome, e-mail ou CPF/CNPJ do cliente |
| `date_from` · `date_to` | Período, ex.: `2026-11-01` |
| `amount_min` · `amount_max` | Faixa de valor, em centavos |
| `page` · `per_page` | Paginação (padrão 1 e 20) |

```bash
curl "http://localhost:8088/api/purchases?customer_name=silva&date_from=2026-11-01" \
  -H "Authorization: Bearer dev-api-access-token"
```

Os erros têm sempre o mesmo formato (`title`, `status`, `detail` e, na validação, `violations`).
