# arctron-purchases-dashboard

Stack: **Symfony** (backend) · **PostgreSQL** (banco) · **RabbitMQ** (fila) · **React + Vite + TypeScript** (frontend)

## Estrutura

```
backend/      Symfony 8 (Doctrine ORM, Messenger)
frontend/     React + Vite + TypeScript + Tailwind CSS 4 (fonte Inter, ícones Lucide)
woocommerce/  WooCommerce de desenvolvimento (incluído no docker-compose.yml), para testar o webhook
docker-compose.yml
```

## Subir o projeto

```bash
docker compose up -d --build
```

As migrations rodam sozinhas quando o container do backend sobe (`post_start` no `docker-compose.yml`), com o banco
já pronto. Num banco novo, elas também cadastram os dados iniciais (usuário padrão, produtos e compras de exemplo);
num banco que já existe, só as migrations novas rodam. Para conferir:
`docker compose exec backend php bin/console doctrine:migrations:status`.

As dependências do backend (`composer install`) são instaladas no build da imagem, em `/app/vendor`, que no
container é um volume separado da pasta local. Depois de mudar o `composer.json`/`composer.lock`, suba com `-V`
(renova esse volume; sem ele o container continua com o `vendor/` antigo):

```bash
docker compose up -d --build -V
```

| Serviço    | Endereço                                   |
|------------|--------------------------------------------|
| Backend    | http://localhost:8088                      |
| Frontend   | http://localhost:5173 (`admin@cbf.com.br` / `cbf@2026`) |
| Loja (WooCommerce) | http://localhost:8081 · admin http://localhost:8081/wp-admin (admin/admin) |
| PostgreSQL | localhost:5432 (app/app, db `app`)         |
| MariaDB da loja | localhost:3308 (wordpress/wordpress, db `wordpress`) |
| RabbitMQ   | localhost:5672 · painel http://localhost:15672 (guest/guest) |
| Worker     | container `worker` (consome a fila do RabbitMQ) |

Na primeira subida a loja leva alguns minutos para ficar pronta (o container `wp_cli` instala e configura tudo):
acompanhe com `docker compose logs -f wp_cli`, que termina com "Pronto!".

## API

Base: http://localhost:8088. Todas as rotas exigem `Authorization: Bearer <token>`, seja o token do login, seja o
`API_ACCESS_TOKEN` (em dev, `dev-api-access-token`); detalhes em [Login](#login). Corpo e respostas em JSON, com
chaves em `snake_case`. **Valores monetários são sempre inteiros em centavos** (`25000` = R$ 250,00).

### Principais endpoints

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/api/auth/login` | Login; devolve o token (pública) |
| `GET` | `/api/auth/me` · `POST /api/auth/logout` | Usuário logado · encerra a sessão |
| `POST` | `/api/purchases` | **Processa uma compra** (201; 409 se o código de referência já existe) |
| `GET` | `/api/purchases` | **Lista e filtra compras** (paginado) |
| `GET` | `/api/purchases/{id}` | Detalhe de uma compra, com cliente e itens |
| `GET` | `/api/reports/summary` | **Totais e médias** de compras, descontos e produtos |
| `GET` | `/api/reports/customers` | **Total e média de compras por cliente** (ranking) |
| `GET` | `/api/reports/products` | **Total e média de compras por produto** (ranking) |
| `GET` | `/api/reports/timeline` | Compras e descontos por dia, semana ou mês (gráfico do dashboard) |
| `GET` `POST` | `/api/products` | Lista (`search`, `page`, `per_page`) · cadastra produto |
| `GET` `PUT` `DELETE` | `/api/products/{id}` | Detalhe · edita · remove (409 se já foi vendido) |
| `POST` `DELETE` | `/api/products/{id}/image` | Envia (multipart, campo `image`) · remove a imagem |
| `POST` | `/api/webhooks/woocommerce` | Pedidos do WooCommerce (assinatura HMAC, sem token) |

### Processar uma compra: `POST /api/purchases`

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
      { "external_id": "CHU-001", "name": "Chuteira", "quantity": 2, "unit_price": 25000, "discount": 5000 },
      { "external_id": "BOL-001", "name": "Bola", "quantity": 1, "unit_price": 15990 }
    ]
  }'
```

| Campo | Regra |
|---|---|
| `reference_code` | Obrigatório e único. **Uma segunda compra com o mesmo código é recusada** (`409`) e nada é gravado |
| `purchased_at` | Obrigatório, ISO 8601. Gravado em UTC |
| `notes` | Opcional (observações) |
| `customer` | `name`, `email` e **um** documento: `cpf` (pessoa física) ou `cnpj` + `trade_name` opcional (pessoa jurídica). CPF/CNPJ são validados; o cliente é encontrado pelo documento e tem nome e e-mail atualizados |
| `products[]` | Pelo menos um. `external_id` (código no canal), `name`, `quantity` > 0, `unit_price`; `discount` (do item) e `internal_code` (código do catálogo) são opcionais |

O **valor** e o **desconto** da compra não são enviados: são calculados a partir dos itens
(`valor = Σ (quantity × unit_price − discount)`, `desconto = Σ discount`), para nunca ficarem inconsistentes com os
itens. Resposta `201` com a compra criada; `422` com a lista `violations` (campo + mensagem) se algo for inválido.

### Consultar compras: `GET /api/purchases`

Todos os filtros são opcionais e combináveis:

| Parâmetro | Filtro |
|---|---|
| `reference_code` | Parte do código de referência (sem diferenciar maiúsculas) |
| `customer_name` · `customer_email` | Parte do nome (ou razão social / nome fantasia) · parte do e-mail |
| `customer_document` | CPF ou CNPJ completo, com ou sem máscara |
| `date_from` · `date_to` | Período (inclusive): `2026-11-01` ou ISO 8601 com hora |
| `amount_min` · `amount_max` | Faixa do valor total, em centavos (inclusive) |
| `page` · `per_page` | Paginação (padrão 1 e 20; `per_page` até 100) |

```bash
curl "http://localhost:8088/api/purchases?customer_name=silva&date_from=2026-11-01&amount_min=10000" \
  -H "Authorization: Bearer dev-api-access-token"
```

```json
{
  "data": [{ "id": 46, "reference_code": "PED-1001", "sales_channel": "API", "amount": 60990, "discount": 5000,
             "purchased_at": "2026-11-21T17:30:00+00:00", "notes": "...", "customer": { "...": "..." },
             "items": [{ "product_id": 5, "name": "Chuteira", "quantity": 2, "unit_price": 25000, "discount": 5000, "total_price": 45000 }] }],
  "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
}
```

### Relatórios (dados agregados)

`GET /api/reports/summary`:

```json
{
  "purchases_count": 46,
  "amount":   { "total": 4701880, "average": 102215 },
  "discount": { "total": 12000,   "average": 261 },
  "products": { "total": 4713880, "average": 22235, "units_sold": 212 }
}
```

`amount` são os valores das compras; `discount`, os descontos; `products`, o valor dos itens antes do desconto
(a média é por unidade vendida: `total ÷ units_sold`).

`GET /api/reports/customers` e `GET /api/reports/products`: rankings paginados com `page`, `limit` (1 a 100),
`sort` (`total`, `average` e `count` para clientes; `total`, `average` e `quantity` para produtos) e
`order` (`asc` ou `desc`).

```json
{ "data": [{ "customer": { "id": 3, "name": "Maria Souza", "...": "..." }, "purchases_count": 6, "total": 1145620, "average": 190937 }],
  "meta": { "page": 1, "limit": 20, "total": 13, "total_pages": 1 } }

{ "data": [{ "product": { "id": 7, "name": "Camisa Retrô 1962", "internal_code": "CAM-001" }, "quantity_sold": 24, "purchases_count": 9, "total": 958260, "average": 39928 }],
  "meta": { "page": 1, "limit": 20, "total": 11, "total_pages": 1 } }
```

`GET /api/reports/timeline?interval=month` (`day`, `week` ou `month`):

```json
{ "interval": "month", "data": [{ "period": "2026-11", "start": "2026-11-01", "end": "2026-11-30", "purchases_count": 13, "amount": 1155520, "discount": 3000 }] }
```

### Erros

Formato único (`title`, `status`, `detail` e, nos erros de validação, `violations`), com mensagens em português:
`401` sem token ou token inválido, `404` recurso inexistente, `409` conflito (compra duplicada, código de produto
repetido), `422` dados inválidos. Erros `500` nunca expõem detalhes internos.

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

### Loja WooCommerce de desenvolvimento

**Para testar sem montar nada**, a pasta `woocommerce/` sobe uma loja WooCommerce de desenvolvimento
(WordPress + WooCommerce + campos de CPF/CNPJ + produtos + este webhook já configurado). O `docker-compose.yml`
dela é incluído (`include:`) pelo da raiz, então o mesmo `docker compose up -d` sobe tudo, na mesma rede (a loja
chama o backend em `http://backend:8000`). A loja fica em http://localhost:8081 (admin / admin).

É configuração **só de desenvolvimento**: senhas fracas e um mu-plugin (`woocommerce/mu-plugins/dev-webhooks.php`)
que libera o webhook para hosts internos e para a porta 8000, e entrega os webhooks na hora. **Não use em produção.**

| Arquivo | Para quê |
|---|---|
| `woocommerce/.env` | Porta, login do admin, banco e o webhook (URL e segredo) |
| `woocommerce/scripts/setup.sh` | Instala WordPress (pt_BR), WooCommerce e o plugin Brazilian Market (campos de CPF/CNPJ), configura a loja (BRL, pagamento na entrega), cadastra o catálogo e cria o webhook **Pedido criado**. Pode rodar de novo sem duplicar |
| `woocommerce/scripts/catalog.php` | Categorias e os 11 produtos do catálogo do painel (SKU = código interno, ex.: `CHU-001`) |
| `woocommerce/scripts/create-test-order.php` | Cria um pedido de teste com CPF ou CNPJ, sem passar pelo checkout |
| `woocommerce/theme/` | Tema filho do Twenty Twenty-Five usado na loja |

**Testar o fluxo completo**: pelo checkout (http://localhost:8081, finalizar com "Pagamento na entrega" e CPF ou
CNPJ) ou, mais rápido, pelo script:

```bash
docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cpf
docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cnpj
```

O pedido vira uma compra com código `WC-<número do pedido>` (canal `WOOCOMMERCE`) e aparece na tela **Compras**
do painel. Se algo falhar, veja *WooCommerce → Status → Logs* (`webhooks-delivery`) no wp-admin e a tabela
`integration_events` do backend (`processed`, `ignored` ou `failed`, com o motivo).

O produto do pedido é procurado primeiro pelo id do produto no WooCommerce e, se ainda não for conhecido, pelo
SKU (código interno do catálogo). Um produto sem SKU, ou com SKU fora do catálogo, vira um produto novo no painel.

O `WOO_WEBHOOK_SECRET` (`woocommerce/.env`) **precisa ser igual** ao `WOOCOMMERCE_WEBHOOK_SECRET`
(`backend/.env`). Se mudar um, mude o outro e recrie o webhook com `docker compose up --force-recreate wp_cli`.

```bash
docker compose rm -sf wordpress woo_db wp_cli && docker volume rm arctron-purchases-dashboard_woo_db_data arctron-purchases-dashboard_wp_data
                                # apaga a loja do zero (o painel continua)
docker compose down -v          # apaga tudo, inclusive o banco do painel
```

## Login

O usuário padrão já é criado pelas migrations na primeira subida (veja [Subir o projeto](#subir-o-projeto)),
então não é preciso rodar nada.

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

**Todas as rotas `/api` têm duas formas de acesso**, enviadas no cabeçalho `Authorization: Bearer <token>`:

1. **usuário logado**: o token devolvido pelo `POST /api/auth/login` (é o que o front usa);
2. **token fixo do `.env`**: o valor de `API_ACCESS_TOKEN` (`backend/.env`), para outros sistemas chamarem a API sem
   login. Em desenvolvimento é `dev-api-access-token`; em produção gere um valor longo e aleatório
   (`openssl rand -hex 32`) e defina em `backend/.env.local` ou numa variável de ambiente. Vazio desliga essa forma.

```bash
curl http://localhost:8088/api/products -H "Authorization: Bearer dev-api-access-token"
```

Sem token, ou com token inválido/expirado: `401` em JSON. Exceções: o `POST /api/auth/login`; o
`POST /api/webhooks/woocommerce`, que não tem como enviar o token e se autentica pela assinatura HMAC do corpo
(`401` se for inválida); e `GET /api/auth/me` e `POST /api/auth/logout`, que são só do usuário logado (o token
fixo recebe `403`). A regra fica no `access_control` do `backend/config/packages/security.yaml`. As imagens em
`/uploads/...` são arquivos estáticos e continuam abertas (para o `<img>` funcionar).

## Produtos de teste

Seeder com 11 artigos de futebol (chuteiras, bolas, luvas, camisas da Seleção Brasileira...), com imagem.
Já roda nas migrations da primeira subida; para rodar de novo à mão (use sempre `--append`: sem ele o Doctrine
apaga **todas** as tabelas):

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --group=products
```

As imagens ficam salvas no repositório, em `backend/src/DataFixtures/images/products/<código interno>.jpg`.
O seeder as copia para `backend/public/uploads/products/`, como se tivessem sido enviadas pela tela (o banco guarda
só o caminho, ex.: `/uploads/products/ab12….jpg`). Produto que já existe é mantido; só a imagem é copiada, se ainda
não for um arquivo local.

Cada produto também recebe o seu código no canal `API` (igual ao código interno, ex.: `CHU-001`),
então uma compra pelo `POST /api/purchases` com `"external_id": "CHU-001"` usa o produto cadastrado.
Cada item da compra também aceita o `internal_code` (opcional): quando o `external_id` ainda não é conhecido no canal,
o produto do catálogo com esse código é usado e ganha o `external_id`, em vez de virar um produto novo. É o que os
pedidos do WooCommerce fazem com o SKU de cada item.
As imagens são fotos com licença livre do Wikimedia Commons; autor e licença de cada uma estão em
`backend/src/DataFixtures/ProductFixtures.php`.

## Compras de teste

Seeder com 40 compras dos últimos ~4 meses, de 8 clientes (pessoas físicas e empresas) e com os produtos acima,
algumas com desconto. É o que enche o dashboard, os rankings e o gráfico. Também já roda nas migrations da
primeira subida; para rodar de novo à mão:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --group=purchases
```

As compras passam pelo mesmo service do `POST /api/purchases` e têm código de referência fixo (`SEED-001`...);
quem já existe é pulado, então pode rodar de novo sem duplicar. O seeder cadastra antes os produtos do
catálogo, se faltarem. Os dados são fixos (nada aleatório), com datas contadas a partir de hoje.

CRUD do catálogo: `GET/POST /api/products` e `GET/PUT/DELETE /api/products/{id}`
(campos `name` e `internal_code`; produto que já foi vendido não pode ser removido).

A imagem é enviada em arquivo, não como URL:

| Endpoint | Descrição |
|---|---|
| `POST /api/products/{id}/image` | `multipart/form-data`, campo `image` (JPG, PNG, WebP ou GIF, até 2 MB). Troca a imagem anterior e apaga o arquivo antigo. 422 se não for imagem |
| `DELETE /api/products/{id}/image` | Remove a imagem (e o arquivo) e devolve o produto |

O tipo do arquivo é conferido pelo conteúdo. A resposta traz `image_url` com o caminho público
(`/uploads/products/….jpg`), servido pelo próprio backend; o Vite repassa `/api` e `/uploads` para ele.
Os arquivos ficam em `backend/public/uploads/` (fora do git) e são apagados junto com o produto.

## Testes

Backend (PHPUnit):

```bash
docker compose exec backend php bin/phpunit --testsuite unit          # sem banco (mocks/stubs)
docker compose exec backend php bin/phpunit --testsuite integration   # banco de teste real (app_test)
docker compose exec backend php bin/phpunit --coverage-text           # todos, com cobertura
```

Frontend (Vitest + Testing Library, em jsdom): formatação de valores, datas e documentos (`src/lib/format.test.ts`),
montagem dos filtros e tratamento de erros da API (`src/services/purchases.test.ts`) e o componente de paginação
(`src/components/Pagination.test.tsx`).

```bash
docker compose exec frontend npm test             # roda uma vez
docker compose exec frontend npm run test:watch   # reexecuta ao salvar
```

### Análise estática

```bash
docker compose exec backend vendor/bin/phpstan analyse   # PHPStan nível 6, com as extensões de Symfony, Doctrine e PHPUnit
docker compose exec frontend npm run lint                # oxlint
docker compose exec frontend npm run build               # checagem de tipos do TypeScript
```

### CI

O workflow [`.github/workflows/ci.yml`](.github/workflows/ci.yml) roda em todo pull request para a `main` (e a cada
push na `main`), com dois jobs em paralelo:

- **backend**: PHP 8.4 + PostgreSQL 16 como serviço; `composer validate`, PHPStan, validação do mapeamento do
  Doctrine e os testes unitários e de integração;
- **frontend**: Node 24; oxlint, checagem de tipos + build e Vitest.

Para o PR só poder ser mesclado com o CI verde, ative em *Settings → Branches → Branch protection rules* da `main`
a opção *Require status checks to pass* com os dois jobs.

## Comandos úteis

```bash
docker compose exec backend php bin/console about
docker compose exec backend composer require <pacote>
docker compose exec backend php bin/console doctrine:migrations:migrate
```
