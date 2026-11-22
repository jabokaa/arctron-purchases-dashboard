<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WooCommerceWebhookServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhooks de integrações externas. Quem chama é a plataforma (WooCommerce), não o front.
 *
 * O controller fica "magro": repassa o corpo e os cabeçalhos ao service e devolve a resposta.
 * Os erros (401 assinatura inválida, 400 corpo inválido) são convertidos em JSON pelo ApiExceptionListener.
 */
#[Route('/api/webhooks', name: 'api_webhooks_', format: 'json')]
final class WebhookController extends AbstractController
{
    public function __construct(
        private readonly WooCommerceWebhookServiceInterface $wooCommerceWebhookService,
    ) {
    }

    /**
     * Recebe os pedidos criados no WooCommerce.
     *
     * - Ping (enviado pelo WooCommerce ao cadastrar o webhook, corpo "webhook_id=..."): 200, nada é gravado.
     * - Assinatura inválida: 401, nada é gravado.
     * - Pedido: grava em integration_events, publica no RabbitMQ e responde 202 Accepted na hora
     *   (o pedido vira compra depois, no worker).
     *
     * O corpo é lido "cru" (sem converter para objeto), porque a assinatura é calculada sobre ele exatamente como veio.
     */
    #[Route('/woocommerce', name: 'woocommerce', methods: ['POST'])]
    public function woocommerce(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (str_starts_with($body, 'webhook_id=')) {
            return $this->json(['status' => 'ping'], Response::HTTP_OK);
        }

        $event = $this->wooCommerceWebhookService->receive(
            body: $body,
            signature: $request->headers->get('X-WC-Webhook-Signature'),
            topic: $request->headers->get('X-WC-Webhook-Topic'),
            deliveryId: $request->headers->get('X-WC-Webhook-Delivery-ID'),
        );

        return $this->json(['event_id' => $event->getId(), 'status' => $event->getStatus()->value], Response::HTTP_ACCEPTED);
    }
}
