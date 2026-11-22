#!/bin/sh
# Deixa a loja de desenvolvimento pronta, sem nenhum clique. Pode rodar quantas vezes quiser:
# cada passo confere se já foi feito antes.
set -eu

# memory_limit maior: instalar o WooCommerce estoura o padrão do PHP do wp-cli
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }

log() { printf '\n==> %s\n' "$1"; }

log "Esperando o WordPress e o banco"
until [ -f /var/www/html/wp-config.php ]; do sleep 2; done
until wp db check >/dev/null 2>&1; do sleep 3; done

log "Instalando o WordPress"
if ! wp core is-installed; then
  wp core install \
    --url="$WOO_URL" \
    --title="Arctron Esportes" \
    --admin_user="$WOO_ADMIN_USER" \
    --admin_password="$WOO_ADMIN_PASSWORD" \
    --admin_email="$WOO_ADMIN_EMAIL" \
    --skip-email
fi
wp language core install pt_BR --activate || echo "(idioma pt_BR não instalado; segue em inglês)"
wp option update blogname "Arctron Esportes"
wp option update blogdescription "Tudo para o seu jogo"
wp option update timezone_string America/Sao_Paulo

log "Links permanentes (necessário para a REST API /wp-json funcionar)"
# O .htaccess com as regras do WordPress já vem pronto do container wordpress
wp rewrite structure '/%postname%/'
wp rewrite flush

log "Plugins: WooCommerce e campos de CPF/CNPJ no checkout"
wp plugin is-installed woocommerce || wp plugin install woocommerce
wp plugin activate woocommerce
wp plugin is-installed woocommerce-extra-checkout-fields-for-brazil || wp plugin install woocommerce-extra-checkout-fields-for-brazil
wp plugin activate woocommerce-extra-checkout-fields-for-brazil
# Traduções do WooCommerce, do plugin e do tema pai ("Adicionar ao carrinho", "Finalizar compra"...)
wp language plugin install woocommerce pt_BR || true
wp language plugin install woocommerce-extra-checkout-fields-for-brazil pt_BR || true
wp language theme install twentytwentyfive pt_BR || true

log "Configurações da loja"
wp option update woocommerce_currency BRL
# Preço no formato brasileiro: R$ 1.299,90
wp option update woocommerce_currency_pos left_space
wp option update woocommerce_price_thousand_sep .
wp option update woocommerce_price_decimal_sep ,
wp option update woocommerce_default_country BR:RJ
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no

# O plugin de CPF/CNPJ só funciona no checkout clássico (o shortcode), não no checkout em blocos
CHECKOUT_PAGE_ID="$(wp option get woocommerce_checkout_page_id)"
CART_PAGE_ID="$(wp option get woocommerce_cart_page_id)"
wp post update "$CHECKOUT_PAGE_ID" --post_content='[woocommerce_checkout]'
wp post update "$CART_PAGE_ID" --post_content='[woocommerce_cart]'

# Pessoa física e jurídica: o checkout pede o CPF ou o CNPJ (o backend exige o documento)
wp eval '
$settings = (array) get_option("wcbcf_settings", array());
$settings["person_type"] = "1";
update_option("wcbcf_settings", $settings);
'

log "Pagamento na entrega (para conseguir finalizar um pedido de teste)"
wp wc payment_gateway update cod --enabled=true --user="$WOO_ADMIN_USER"

log "Catálogo: categorias, produtos com foto e descrição, páginas em português (scripts/catalog.php)"
# Miniaturas quadradas e maiores, definidas antes de importar as fotos
wp option update woocommerce_thumbnail_image_width 600
wp option update woocommerce_single_image_width 900
wp option update woocommerce_thumbnail_cropping 1:1
wp eval-file /scripts/catalog.php

log "Visual: tema da loja (pasta theme/)"
wp theme activate arctron-loja
wp eval 'wp_get_theme()->delete_pattern_cache();'   # padrões novos em theme/patterns
wp option update show_on_front posts   # a página inicial é o templates/front-page.html do tema
wp rewrite flush

log "Webhook 'Pedido criado' -> backend"
WEBHOOK_NAME="Arctron - Pedido criado"
# O wp wc webhook update não troca o endereço de entrega: se já existe, apaga e cria de novo,
# o que também garante que o endereço e o segredo estão iguais ao .env
for WEBHOOK_ID in $(wp wc webhook list --user="$WOO_ADMIN_USER" --fields=id,name --format=csv | grep "$WEBHOOK_NAME" | cut -d, -f1); do
  wp wc webhook delete "$WEBHOOK_ID" --force=true --user="$WOO_ADMIN_USER"
done
wp wc webhook create \
  --name="$WEBHOOK_NAME" \
  --topic=order.created \
  --delivery_url="$WOO_WEBHOOK_URL" \
  --secret="$WOO_WEBHOOK_SECRET" \
  --status=active \
  --user="$WOO_ADMIN_USER"

log "Pronto!"
echo "Loja:     $WOO_URL"
echo "Admin:    $WOO_URL/wp-admin  ($WOO_ADMIN_USER / $WOO_ADMIN_PASSWORD)"
echo "Webhook:  $WOO_WEBHOOK_URL"
