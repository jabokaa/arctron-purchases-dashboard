<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Situação de um evento recebido de uma integração (tabela integration_events).
 *
 *   received ──worker──► processed   (compra criada)
 *            ├────────► ignored     (nada a fazer: tópico que não é pedido criado, ou pedido já importado)
 *            └────────► failed      (pedido inválido ou erro no processamento; o motivo fica em "error")
 */
enum IntegrationEventStatus: string
{
    /** Gravado pelo webhook, aguardando o worker */
    case Received = 'received';

    /** Convertido em compra com sucesso */
    case Processed = 'processed';

    /** Não gerou compra, e não é erro */
    case Ignored = 'ignored';

    /** Não gerou compra por erro */
    case Failed = 'failed';
}
