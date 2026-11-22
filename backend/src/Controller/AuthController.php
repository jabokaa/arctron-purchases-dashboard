<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\LoginInput;
use App\Dto\UserOutput;
use App\Entity\User;
use App\Service\AuthServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Endpoints de autenticação do painel.
 *
 * - POST /api/auth/login  → público; devolve o token de acesso
 * - GET  /api/auth/me     → exige token; devolve o usuário logado
 * - POST /api/auth/logout → exige token; invalida o token usado
 *
 * Quem exige o token é o access_control de config/packages/security.yaml.
 */
#[Route('/api/auth', name: 'api_auth_', format: 'json')]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
    ) {
    }

    /**
     * E-mail ou senha errados → 401 (InvalidCredentialsException). Campos faltando → 422.
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        #[MapRequestPayload(acceptFormat: 'json')] LoginInput $input,
    ): JsonResponse {
        return $this->json($this->authService->login($input));
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(UserOutput::fromEntity($user));
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        // O access_control garante que o cabeçalho existe e o token é válido para chegar aqui
        $token = substr((string) $request->headers->get('Authorization'), \strlen('Bearer '));
        $this->authService->logout($token);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
