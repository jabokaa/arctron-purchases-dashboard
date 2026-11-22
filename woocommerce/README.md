# WooCommerce de desenvolvimento

> **Só para desenvolvimento e testes.** Esta pasta sobe uma loja WooCommerce descartável para testar a integração
> com o painel (webhook de pedido criado → compra). Usa senhas fracas (`admin` / `admin`) e um *mu-plugin* que
> desliga proteções do WordPress. **Não use em produção.**

O `docker-compose.yml` desta pasta é incluído (`include:`) pelo compose da raiz: os serviços fazem parte do mesmo
projeto e da mesma rede, e por isso a loja chama o backend em `http://backend:8000`. As variáveis vêm do `.env`
desta pasta.

## Subir

```bash
docker compose up -d     # na raiz: sobe o painel e a loja juntos
```

A primeira vez demora alguns minutos: o container `wp_cli` instala o WordPress e o WooCommerce. Acompanhe com:

```bash
docker compose logs -f wp_cli     # termina com "Pronto!"
```

| O quê | Endereço |
|---|---|
| Loja | http://localhost:8081 |
| Admin | http://localhost:8081/wp-admin (`admin` / `admin`) |

## O que existe aqui

| Item | Para quê |
|---|---|
| `docker-compose.yml` | Três serviços: `woo_db` (MariaDB), `wordpress` (Apache) e `wp_cli` (roda o setup uma vez e encerra) |
| `.env` | Porta, login do admin, banco e o webhook (URL e segredo) |
| `scripts/setup.sh` | Deixa a loja pronta sem nenhum clique (veja abaixo). Pode rodar de novo sem duplicar nada |
| `scripts/catalog.php` | Categorias, produtos (foto, descrição, preço e promoção) e páginas em português |
| `theme/` | Visual da loja: tema filho do Twenty Twenty-Five (veja abaixo) |
| `scripts/create-test-order.php` | Cria um pedido de teste com CPF ou CNPJ, sem passar pelo checkout |
| `mu-plugins/dev-webhooks.php` | Libera o webhook para o backend dentro do Docker (veja abaixo) |

### O que o `setup.sh` faz

1. instala o WordPress (idioma pt_BR) e os links permanentes `/%postname%/` (a REST API `/wp-json` precisa deles);
2. instala o **WooCommerce** e o plugin **Brazilian Market on WooCommerce**, que acrescenta ao checkout os campos
   `billing_persontype`, `billing_cpf` e `billing_cnpj` (o backend exige o documento do cliente). O checkout usa o
   shortcode clássico, porque o plugin não funciona no checkout em blocos;
3. configura a loja (moeda BRL, país BR-RJ) e liga o pagamento na entrega (`cod`), para conseguir finalizar um pedido;
4. roda o `catalog.php`: 4 categorias e os 11 produtos do catálogo do painel (o SKU é o código interno, ex.:
   `CHU-001`), com os mesmos preços do seeder de compras, alguns em promoção, e as mesmas fotos do seeder do backend
   (`backend/src/DataFixtures/images/products`, com o crédito de cada foto na descrição do produto). Também põe as
   páginas em português (`/loja`, `/carrinho`, `/finalizar-compra`, `/minha-conta`) e apaga o conteúdo de exemplo
   do WordPress;
5. ativa o tema `arctron-loja`;
6. cria o webhook **"Pedido criado"** apontando para `http://backend:8000/api/webhooks/woocommerce`, com o segredo
   `WOO_WEBHOOK_SECRET`. Se ele já existir, é recriado com o endereço e o segredo do `.env`.

### O tema (`theme/`)

Tema filho do Twenty Twenty-Five, montado direto no container (o compose define `WP_DEVELOPMENT_MODE=theme`, então
editar um arquivo aparece ao recarregar a página):

| Arquivo | O quê |
|---|---|
| `theme.json` | Cores (verde e amarelo), fonte Manrope, botões arredondados e largura do conteúdo |
| `style.css` | Cabeçalho, página inicial, cards de produto, rodapé, carrinho e checkout |
| `functions.php` | Carrega o `style.css` e tem os helpers dos padrões (ícones, logo, vitrine de produtos) |
| `templates/front-page.html` | Página inicial: destaque, vantagens, categorias, mais pedidos, banner retrô e ofertas |
| `templates/archive-product.html` | Loja e páginas de categoria, em 4 colunas (`taxonomy-product_cat.html` é igual) |
| `parts/` e `patterns/` | Cabeçalho, rodapé e as seções das páginas (o menu lista as categorias sozinho) |

### Por que existe o mu-plugin

O WooCommerce entrega webhooks com `wp_safe_remote_request`, que recusa destinos que não pareçam seguros. Dentro do
Docker isso trava a entrega para o backend:

- `http_request_host_is_external`: libera hosts com IP privado, como `http://backend`;
- `http_allowed_safe_ports`: só as portas 80, 443 e 8080 são aceitas; o backend escuta na **8000**;
- `woocommerce_webhook_deliver_async` = `false`: entrega na hora, em vez de esperar o Action Scheduler (que só roda
  quando alguém acessa o site e por isso atrasa o webhook);
- `pre_wp_mail`: sem servidor de e-mail no dev, evita o erro de `sendmail` a cada pedido.

## Testar o fluxo completo

**Pelo checkout:** abra http://localhost:8081, adicione um produto ao carrinho e finalize com
"Pagamento na entrega", preenchendo o CPF (ou escolhendo pessoa jurídica e o CNPJ).

**Pelo script (mais rápido):**

```bash
docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cpf
docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cnpj
```

O WooCommerce dispara o webhook, o backend grava o evento e publica na fila, e o `worker` converte o pedido em
uma compra com código `WC-<número do pedido>` (canal `WOOCOMMERCE`). Ela aparece na tela **Compras** do painel
(http://localhost:5173).

```bash
docker compose logs -f worker      # acompanha o processamento
```

Se algo falhar, veja *WooCommerce → Status → Logs* no wp-admin (os logs `webhooks-delivery`) e a tabela
`integration_events` do backend (`processed`, `ignored` ou `failed`, com o motivo).

> O ping que o WooCommerce manda ao criar o webhook (corpo `webhook_id=...`, form-encoded) é reconhecido pelo
> backend e respondido com `200`, sem gravar nada. Sem isso o WooCommerce poderia desativar o webhook por falhas.

## O segredo do webhook

`WOO_WEBHOOK_SECRET` (em `woocommerce/.env`) **precisa ser igual** ao `WOOCOMMERCE_WEBHOOK_SECRET` do
`backend/.env`: o WooCommerce assina o corpo (`X-WC-Webhook-Signature` = `base64(hmac_sha256(corpo, segredo))`) e o
backend confere. Se mudar um, mude o outro e rode o setup de novo:

```bash
docker compose up --force-recreate wp_cli
```

## Parar e recomeçar do zero

```bash
docker compose stop wordpress woo_db                       # para só a loja (mantém os dados)
docker compose rm -sf wordpress woo_db wp_cli && docker volume rm arctron-purchases-dashboard_woo_db_data arctron-purchases-dashboard_wp_data
                                                           # apaga a loja do zero (o painel continua)
docker compose down -v                                     # apaga tudo, inclusive o banco do painel
```

## Observações

- A loja usa sempre o WordPress e o WooCommerce mais recentes (o WooCommerce atual exige o WordPress mais novo).
- No backend o produto de um pedido é procurado primeiro pelo **id do produto no WooCommerce** e, se ainda não for
  conhecido, pelo **SKU**, que é o código interno do catálogo do painel (ex.: `CAM-001`). Encontrado pelo SKU, o
  produto do catálogo é usado (com a imagem dele) e ganha o id do WooCommerce, então os próximos pedidos o encontram
  direto. Um produto sem SKU (ou com um SKU que não existe no catálogo) vira um produto novo no painel.
