<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Respostas 401 da API no mesmo formato JSON dos outros erros (ver ApiExceptionListener).
 *
 * - start(): rota protegida acessada sem token.
 * - onAuthenticationFailure(): token enviado, mas inexistente ou vencido.
 */
final class ApiAuthenticationErrorHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized('É necessário estar autenticado. Envie o token no cabeçalho "Authorization: Bearer <token>".');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized('Token inválido ou expirado. Faça login novamente.');
    }

    private static function unauthorized(string $detail): JsonResponse
    {
        return new JsonResponse(
            ['title' => 'Não autorizado', 'status' => Response::HTTP_UNAUTHORIZED, 'detail' => $detail],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
