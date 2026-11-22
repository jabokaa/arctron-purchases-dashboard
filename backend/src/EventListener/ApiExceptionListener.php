<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Transforma qualquer exceção das rotas /api em uma resposta JSON no mesmo formato:
 *
 *   {
 *     "title": "Dados inválidos",
 *     "status": 422,
 *     "detail": "Um ou mais campos são inválidos.",
 *     "violations": [{ "field": "customer.cpf", "message": "O CPF ... não é válido." }]   ← só em erros de validação
 *   }
 *
 * - Erros de validação (422): lista os campos com mensagens em português.
 * - Exceções HTTP (404, 405...) e exceções com #[WithHttpStatus] (ex.: compra duplicada → 409):
 *   a mensagem é pensada para o cliente, então vai no "detail".
 * - Qualquer outro erro (500): mensagem genérica, sem expor detalhes internos.
 *   Em ambiente de desenvolvimento, o campo "debug" mostra a exceção original.
 *
 * Roda com prioridade -64: depois do log padrão do Symfony (prioridade 0), então o erro
 * continua sendo registrado, e antes da página de erro padrão (prioridade -128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final class ApiExceptionListener
{
    private const TITLES = [
        Response::HTTP_BAD_REQUEST => 'Requisição inválida',
        Response::HTTP_UNAUTHORIZED => 'Não autorizado',
        Response::HTTP_FORBIDDEN => 'Acesso negado',
        Response::HTTP_NOT_FOUND => 'Não encontrado',
        Response::HTTP_METHOD_NOT_ALLOWED => 'Método não permitido',
        Response::HTTP_CONFLICT => 'Conflito',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'Formato não suportado',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'Dados inválidos',
        Response::HTTP_INTERNAL_SERVER_ERROR => 'Erro interno',
    ];

    /** Nomes amigáveis para os tipos que o serializer informa nos erros de tipo */
    private const TYPE_NAMES = [
        'int' => 'número inteiro',
        'float' => 'número',
        'string' => 'texto',
        'bool' => 'verdadeiro ou falso',
        'array' => 'lista',
    ];

    public function __construct(
        /** Mesmo conversor do serializer: deixa os campos dos erros no formato do JSON (unitPrice → unit_price) */
        #[Autowire(service: 'serializer.name_converter.camel_case_to_snake_case')]
        private readonly NameConverterInterface $nameConverter,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return; // fora da API, mantém o comportamento padrão do Symfony
        }

        $exception = $event->getThrowable();
        $status = $this->resolveStatus($exception);

        $body = [
            'title' => self::TITLES[$status] ?? (Response::$statusTexts[$status] ?? 'Erro'),
            'status' => $status,
            'detail' => $this->resolveDetail($exception, $status, $event->getRequest()->getContent()),
        ];

        $violations = $exception->getPrevious() instanceof ValidationFailedException
            ? $this->formatViolations($exception->getPrevious())
            : [];

        if ([] !== $violations) {
            $body['detail'] = 'Um ou mais campos são inválidos.';
            $body['violations'] = $violations;
        }

        if ($status >= 500) {
            $this->logger->error('Erro inesperado na API: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            if ($this->debug) {
                $body['debug'] = ['class' => $exception::class, 'message' => $exception->getMessage()];
            }
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $event->setResponse(new JsonResponse($body, $status, $headers));
    }

    private function resolveStatus(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        // Exceções da aplicação marcadas com #[WithHttpStatus(...)], ex.: DuplicatePurchaseException → 409
        $attribute = (new \ReflectionClass($exception))->getAttributes(WithHttpStatus::class)[0] ?? null;

        return $attribute?->newInstance()->statusCode ?? Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private function resolveDetail(\Throwable $exception, int $status, string $requestBody): string
    {
        // Erros 500 nunca expõem a mensagem original (pode conter SQL, caminhos de arquivo etc.)
        if ($status >= 500) {
            return 'Ocorreu um erro inesperado. Tente novamente mais tarde.';
        }

        $message = $exception->getMessage();

        // Mensagens do próprio Symfony ao ler o corpo da requisição, traduzidas
        return match (true) {
            // Não vale para exceções da aplicação (ex.: InvalidImageException, em upload multipart sem corpo): elas já trazem a mensagem certa
            !$exception->getPrevious() instanceof \DomainException && '' === trim($requestBody) && $status < 500 && \in_array($status, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY], true) => 'O corpo da requisição é obrigatório.',
            str_starts_with($message, 'Request payload contains invalid') => 'O corpo da requisição não é um JSON válido.',
            str_starts_with($message, 'Unsupported format') => 'Formato não suportado. Envie o corpo em JSON com o cabeçalho "Content-Type: application/json".',
            Response::HTTP_FORBIDDEN === $status => 'Você não tem permissão para acessar este recurso.',
            Response::HTTP_METHOD_NOT_ALLOWED === $status => 'Método HTTP não permitido para esta rota.',
            str_starts_with($message, 'No route found') => 'Rota não encontrada.',
            // Parâmetro da URL que não cabe no tipo esperado, ex.: id maior que o maior inteiro
            str_starts_with($message, 'The value for the') => 'O valor informado na URL é inválido.',
            '' === $message => self::TITLES[$status] ?? 'Erro',
            default => $message,
        };
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function formatViolations(ValidationFailedException $exception): array
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $field = $this->nameConverter->normalize($violation->getPropertyPath());

            $violations[] = [
                'field' => $field,
                'message' => $this->translateMessage($violation, $field),
            ];
        }

        return $violations;
    }

    /**
     * As mensagens das regras do projeto já estão em português.
     * Só os erros de tipo vêm do Symfony em inglês, então são traduzidos aqui.
     */
    private function translateMessage(ConstraintViolationInterface $violation, string $field): string
    {
        $isTypeError = 'This value should be of type {{ type }}.' === $violation->getMessageTemplate();

        // Datas inválidas chegam como "esperado texto", o que confunde. Pela convenção do projeto,
        // campos de data terminam em "_at" (purchased_at, created_at...), então explicamos o formato.
        if ($isTypeError && str_ends_with($field, '_at')) {
            return 'Data inválida. Use o formato ISO 8601, ex.: 2026-11-21T14:30:00-03:00.';
        }

        return match ($violation->getMessageTemplate()) {
            'This value should be of type {{ type }}.' => \sprintf(
                'Tipo inválido: esperado %s.',
                $this->describeTypes((string) ($violation->getParameters()['{{ type }}'] ?? '')),
            ),
            'This value was of an unexpected type.' => 'Tipo de valor inesperado.',
            default => (string) $violation->getMessage(),
        };
    }

    /** "int|null" → "número inteiro"; "int|null|string" → "número inteiro ou texto" */
    private function describeTypes(string $types): string
    {
        $names = array_map(
            static fn (string $type): string => self::TYPE_NAMES[$type] ?? $type,
            array_filter(explode('|', $types), static fn (string $type): bool => 'null' !== $type),
        );

        return implode(' ou ', array_unique($names)) ?: 'outro tipo';
    }
}
