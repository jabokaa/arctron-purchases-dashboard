<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Message\ProcessWooCommerceOrder;
use App\MessageHandler\ProcessWooCommerceOrderHandler;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Testes de integração do webhook POST /api/webhooks/woocommerce, do recebimento até a compra gravada.
 *
 * O que é testado: o fluxo completo, do jeito que acontece em produção:
 * 1. o WooCommerce envia o pedido assinado (HMAC) → 202 e evento gravado em integration_events;
 * 2. a mensagem é publicada na fila;
 * 3. o worker (ProcessWooCommerceOrderHandler) consome a mensagem e grava a compra no canal WOOCOMMERCE.
 * Nada é mockado: assinatura, banco, service de compras e handler são os reais.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase)
 *   e esvaziado antes de cada teste.
 * - A fila "async" é em memória no ambiente de teste (config/packages/messenger.yaml): o teste confere
 *   a mensagem publicada e faz o papel do worker, entregando cada mensagem ao handler real.
 *   O Symfony esvazia essa fila a cada nova requisição, então o "worker" roda logo depois de cada webhook.
 * - O segredo do webhook no teste é o do .env.test (WOOCOMMERCE_WEBHOOK_SECRET=test-webhook-secret).
 */
final class WooCommerceWebhookApiTest extends IntegrationTestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Mantém o mesmo container entre a requisição e a leitura da fila em memória
        $this->client->disableReboot();
    }

    /**
     * Pedido assinado corretamente.
     * Espera 202 com o id do evento, o payload gravado como "received" e uma mensagem na fila com esse id.
     */
    public function testAcceptsSignedOrderStoresEventAndPublishesMessage(): void
    {
        $response = $this->sendWebhook(self::order());

        self::assertSame(202, $response['status']);
        self::assertSame('received', $response['body']['status']);

        $event = $this->connection()->fetchAssociative('SELECT id, source, topic, delivery_id, resource_id, status, payload FROM integration_events');
        self::assertSame($response['body']['event_id'], $event['id']);
        self::assertSame(['woocommerce', 'order.created', 'entrega-1', '727', 'received'], [$event['source'], $event['topic'], $event['delivery_id'], $event['resource_id'], $event['status']]);
        // JSONB guarda o mesmo conteúdo, mas não a ordem das chaves: por isso assertEquals, e não assertSame
        self::assertEquals(self::order(), json_decode($event['payload'], true), 'O conteúdo do payload deve ser gravado sem alterações.');

        $messages = $this->queuedMessages();
        self::assertCount(1, $messages);
        self::assertSame($event['id'], $messages[0]->integrationEventId);

        // Até o worker rodar, nenhuma compra existe (o processamento é assíncrono)
        self::assertSame(0, $this->countRows('purchases'));
    }

    /**
     * Fluxo completo: webhook + worker.
     * Espera a compra gravada no canal WOOCOMMERCE com o código "WC-727", cliente, itens, totais e
     * observação do pedido, e o evento marcado como "processed" e ligado à compra.
     */
    public function testWorkerConvertsOrderIntoPurchase(): void
    {
        $this->sendWebhook(self::order());
        $this->runWorker();

        $purchase = $this->connection()->fetchAssociative(
            'SELECT p.id, p.reference_code, s.code AS channel, p.amount, p.discount, p.purchased_at, p.notes
             FROM purchases p JOIN sales_channels s ON s.id = p.sales_channel_id',
        );
        self::assertSame(
            ['WC-727', 'WOOCOMMERCE', 12000, 1000, '2026-11-21 17:30:00', 'Entregar no portão'],
            [$purchase['reference_code'], $purchase['channel'], $purchase['amount'], $purchase['discount'], $purchase['purchased_at'], $purchase['notes']],
        );

        self::assertSame(
            ['full_name' => 'João Silva', 'cpf' => '52998224725'],
            $this->connection()->fetchAssociative('SELECT full_name, cpf FROM individual_customers'),
        );

        // Itens: o id do produto (ou da variação) no WooCommerce vira o external_id no canal WOOCOMMERCE
        self::assertSame([
            ['external_id' => '101', 'channel' => 'WOOCOMMERCE', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 1000, 'total_price' => 9000],
            ['external_id' => '22', 'channel' => 'WOOCOMMERCE', 'quantity' => 1, 'unit_price' => 3000, 'discount' => 0, 'total_price' => 3000],
        ], $this->connection()->fetchAllAssociative(
            'SELECT e.external_id, s.code AS channel, i.quantity, i.unit_price, i.discount, i.total_price
             FROM purchase_items i
             JOIN product_external_ids e ON e.product_id = i.product_id
             JOIN sales_channels s ON s.id = e.sales_channel_id
             ORDER BY i.id',
        ));

        self::assertSame(
            ['status' => 'processed', 'purchase_id' => $purchase['id'], 'error' => null],
            $this->connection()->fetchAssociative('SELECT status, purchase_id, error FROM integration_events'),
        );
    }

    /**
     * Pedido de pessoa jurídica (CNPJ no meta_data, como o plugin Brazilian Market também envia).
     * Espera a compra com o cliente gravado em company_customers.
     */
    public function testWorkerConvertsCompanyOrder(): void
    {
        $order = self::order();
        $order['billing'] = ['first_name' => 'Maria', 'last_name' => 'Souza', 'company' => 'Loja Exemplo LTDA', 'email' => 'c@loja.com'];
        $order['meta_data'] = [['key' => '_billing_persontype', 'value' => '2'], ['key' => '_billing_cnpj', 'value' => '11.222.333/0001-81']];

        $this->sendWebhook($order);
        $this->runWorker();

        self::assertSame(
            ['legal_name' => 'Loja Exemplo LTDA', 'cnpj' => '11222333000181'],
            $this->connection()->fetchAssociative('SELECT legal_name, cnpj FROM company_customers'),
        );
        self::assertSame('processed', $this->connection()->fetchOne('SELECT status FROM integration_events'));
    }

    /**
     * O WooCommerce reenvia o mesmo pedido (nova entrega do mesmo webhook).
     * Espera uma compra só: a segunda entrega fica como "ignored" (pedido já importado).
     */
    public function testSameOrderDeliveredTwiceCreatesOnePurchase(): void
    {
        $this->sendWebhook(self::order(), deliveryId: 'entrega-1');
        $this->runWorker();

        $this->sendWebhook(self::order(), deliveryId: 'entrega-2');
        $this->runWorker();

        self::assertSame(1, $this->countRows('purchases'));
        self::assertSame(
            [
                ['delivery_id' => 'entrega-1', 'status' => 'processed'],
                ['delivery_id' => 'entrega-2', 'status' => 'ignored'],
            ],
            $this->connection()->fetchAllAssociative('SELECT delivery_id, status FROM integration_events ORDER BY id'),
        );
    }

    /**
     * Pedido que não passa nas validações do endpoint de compras (CPF com dígito errado).
     * Espera nenhuma compra e o evento "failed", com o campo e o motivo, para alguém corrigir.
     */
    public function testInvalidOrderIsStoredAsFailed(): void
    {
        $order = self::order();
        $order['billing']['cpf'] = '529.982.247-24';

        $this->sendWebhook($order);
        $this->runWorker();

        self::assertSame(0, $this->countRows('purchases'));
        self::assertSame(
            ['status' => 'failed', 'error' => 'Pedido inválido. customer.cpf: O CPF "529.982.247-24" não é válido.'],
            $this->connection()->fetchAssociative('SELECT status, error FROM integration_events'),
        );
    }

    /**
     * Assinatura inválida (corpo alterado depois de assinado).
     * Espera 401 e que nada seja gravado nem publicado.
     */
    public function testRejectsInvalidSignature(): void
    {
        $body = json_encode(self::order(), \JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        $response = $this->post($body.' ', $signature, 'order.created', 'entrega-1');

        self::assertSame(401, $response['status']);
        self::assertSame('Assinatura do webhook inválida.', $response['body']['detail']);
        self::assertSame(0, $this->countRows('integration_events'));
        self::assertSame([], $this->queuedMessages());
    }

    /**
     * Requisição sem o cabeçalho de assinatura.
     * Espera 401 e que nada seja gravado nem publicado.
     */
    public function testRejectsMissingSignature(): void
    {
        $response = $this->post(json_encode(self::order(), \JSON_THROW_ON_ERROR), null, 'order.created', null);

        self::assertSame(401, $response['status']);
        self::assertSame(0, $this->countRows('integration_events'));
        self::assertSame([], $this->queuedMessages());
    }

    /**
     * Webhook de outro tópico (pedido atualizado).
     * Espera 202, o evento gravado como "ignored" e nenhuma mensagem na fila.
     */
    public function testOtherTopicsAreStoredAsIgnored(): void
    {
        $response = $this->sendWebhook(self::order(), topic: 'order.updated');

        self::assertSame(202, $response['status']);
        self::assertSame('ignored', $response['body']['status']);
        self::assertSame('ignored', $this->connection()->fetchOne('SELECT status FROM integration_events'));
        self::assertSame([], $this->queuedMessages());
    }

    /**
     * Ping do WooCommerce ao cadastrar o webhook.
     * Espera 200 e que nada seja gravado nem publicado.
     */
    public function testAnswersPing(): void
    {
        $this->client->request('POST', '/api/webhooks/woocommerce', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'webhook_id=12');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('integration_events'));
        self::assertSame([], $this->queuedMessages());
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Envia o pedido como o WooCommerce faria: JSON assinado com o segredo e os cabeçalhos do webhook.
     *
     * @param array<string, mixed> $order
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function sendWebhook(array $order, string $topic = 'order.created', string $deliveryId = 'entrega-1'): array
    {
        $body = json_encode($order, \JSON_THROW_ON_ERROR);

        return $this->post($body, base64_encode(hash_hmac('sha256', $body, self::SECRET, true)), $topic, $deliveryId);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $body, ?string $signature, ?string $topic, ?string $deliveryId): array
    {
        $headers = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $deliveryId,
        ]);

        $this->client->request('POST', '/api/webhooks/woocommerce', server: $headers, content: $body);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Mensagens publicadas na fila (em memória no ambiente de teste).
     *
     * @return list<ProcessWooCommerceOrder>
     */
    private function queuedMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return array_map(static fn ($envelope) => $envelope->getMessage(), array_values($transport->getSent()));
    }

    /**
     * Faz o papel do worker: entrega cada mensagem da fila ao handler real, na ordem em que foram publicadas.
     */
    private function runWorker(): void
    {
        $handler = static::getContainer()->get(ProcessWooCommerceOrderHandler::class);

        foreach ($this->queuedMessages() as $message) {
            $handler($message);
        }
    }

    /**
     * Pedido de exemplo no formato da REST API v3 do WooCommerce (pessoa física, 2 itens, desconto).
     *
     * @return array<string, mixed>
     */
    private static function order(): array
    {
        return [
            'id' => 727,
            'number' => '727',
            'status' => 'processing',
            'currency' => 'BRL',
            'date_created' => '2026-11-21T14:30:00',
            'date_created_gmt' => '2026-11-21T17:30:00',
            'customer_note' => 'Entregar no portão',
            'billing' => [
                'first_name' => 'João',
                'last_name' => 'Silva',
                'email' => 'joao@email.com',
                'persontype' => '1',
                'cpf' => '529.982.247-25',
            ],
            'meta_data' => [],
            'line_items' => [
                ['id' => 315, 'name' => 'Camiseta Azul - M', 'product_id' => 93, 'variation_id' => 101, 'quantity' => 2, 'subtotal' => '100.00', 'total' => '90.00'],
                ['id' => 316, 'name' => 'Boné', 'product_id' => 22, 'variation_id' => 0, 'quantity' => 1, 'subtotal' => '30.00', 'total' => '30.00'],
            ],
        ];
    }
}
