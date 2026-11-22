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

**Para testar sem montar nada**, a pasta [`woocommerce/`](woocommerce/README.md) sobe uma loja WooCommerce de
desenvolvimento (WordPress + WooCommerce + campos de CPF/CNPJ + produtos + este webhook já configurado). Ela é
incluída no `docker-compose.yml` da raiz, então o mesmo `docker compose up -d` sobe tudo; a loja fica em
http://localhost:8081 (admin / admin).

É configuração **só de desenvolvimento** (senhas fracas e um mu-plugin que libera o webhook para hosts internos).

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

Seeder com 11 artigos de futebol (chuteiras, bolas, luvas, camisas da Seleção Brasileira...), com imagem:

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
algumas com desconto. É o que enche o dashboard, os rankings e o gráfico:

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
