<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Entity\IntegrationEvent;
use App\Exception\InvalidWebhookPayloadException;
use App\Exception\InvalidWebhookSignatureException;
use App\Service\WooCommerceWebhookServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint POST /api/webhooks/woocommerce (WebhookController::woocommerce).
 *
 * O que é testado: se o controller repassa ao service o corpo exatamente como veio e os cabeçalhos
 * do WooCommerce, responde 202 com o id do evento, responde ao ping sem chamar o service, e converte
 * os erros do service em 401 (assinatura) e 400 (corpo inválido).
 *
 * Como é testado:
 * - O WooCommerceWebhookService é trocado por um MOCK: nada é gravado nem publicado na fila,
 *   e o teste foca só no controller.
 */
final class WebhookControllerTest extends WebTestCase
{
    private const URL = '/api/webhooks/woocommerce';

    private KernelBrowser $client;

    private WooCommerceWebhookServiceInterface&MockObject $webhookService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Troca o service real pelo mock dentro do container do Symfony
        $this->webhookService = $this->createMock(WooCommerceWebhookServiceInterface::class);
        static::getContainer()->set(WooCommerceWebhookServiceInterface::class, $this->webhookService);
    }

    /**
     * Pedido recebido.
     * Espera que o service receba o corpo cru (byte a byte, porque a assinatura é calculada sobre ele)
     * e os 3 cabeçalhos do WooCommerce, e a resposta 202 com o id e o status do evento.
     */
    public function testAcceptsOrderAndReturnsEvent(): void
    {
        $body = '{"id": 727,  "status":"processing"}'; // espaços de propósito: não pode ser reformatado

        $this->webhookService->expects($this->once())
            ->method('receive')
            ->with($body, 'assinatura-base64', 'order.created', 'entrega-1')
            ->willReturn(self::event(id: 15));

        $this->client->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'assinatura-base64',
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'entrega-1',
        ], content: $body);

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertSame(['event_id' => 15, 'status' => 'received'], $this->decodeResponse());
    }

    /**
     * Ping que o WooCommerce envia ao cadastrar o webhook (corpo "webhook_id=...", sem assinatura).
     * Espera 200 e que o service NUNCA seja chamado (nada é gravado).
     */
    public function testAnswersPingWithoutCallingService(): void
    {
        $this->webhookService->expects($this->never())->method('receive');

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'webhook_id=12');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['status' => 'ping'], $this->decodeResponse());
    }

    /**
     * Assinatura inválida: o service lança InvalidWebhookSignatureException.
     * Espera 401 com a mensagem da exceção.
     */
    public function testReturnsUnauthorizedForInvalidSignature(): void
    {
        $this->webhookService->expects($this->once())->method('receive')->willThrowException(new InvalidWebhookSignatureException());

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: '{"id":1}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['title' => 'Não autorizado', 'status' => 401, 'detail' => 'Assinatura do webhook inválida.'],
            $this->decodeResponse(),
        );
    }

    /**
     * Corpo que não é JSON (com assinatura válida): o service lança InvalidWebhookPayloadException.
     * Espera 400 com a mensagem da exceção.
     */
    public function testReturnsBadRequestForInvalidPayload(): void
    {
        $this->webhookService->expects($this->once())->method('receive')->willThrowException(new InvalidWebhookPayloadException());

        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: 'não é json');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('O corpo do webhook não é um JSON válido.', $this->decodeResponse()['detail']);
    }

    /**
     * Método diferente de POST.
     * Espera 405 e que o service NUNCA seja chamado.
     */
    public function testRejectsGetMethod(): void
    {
        $this->webhookService->expects($this->never())->method('receive');

        $this->client->request('GET', self::URL);

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    private static function event(int $id): IntegrationEvent
    {
        $event = new IntegrationEvent(IntegrationEvent::SOURCE_WOOCOMMERCE, 'order.created', ['id' => 727]);
        (new \ReflectionProperty(IntegrationEvent::class, 'id'))->setValue($event, $id);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
