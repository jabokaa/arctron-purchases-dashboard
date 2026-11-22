<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LoginInput;
use App\Dto\LoginOutput;
use App\Dto\UserOutput;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Exception\InvalidCredentialsException;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Login e logout com token de acesso.
 *
 * Fluxo:
 *  1. login(): confere a senha, gera um token aleatório (64 caracteres hex), grava só o hash
 *     dele no banco com a data de validade e devolve o token ao cliente.
 *  2. Nas próximas requisições, o ApiTokenHandler valida o token do cabeçalho Authorization.
 *  3. logout(): apaga o token, que deixa de valer na hora.
 */
final class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        /** Validade do token em segundos (AUTH_TOKEN_TTL no .env) */
        #[Autowire('%env(int:AUTH_TOKEN_TTL)%')]
        private readonly int $tokenTtl,
    ) {
    }

    public function login(LoginInput $input): LoginOutput
    {
        $user = $this->userRepository->findOneByEmail((string) $input->email);
        $password = (string) $input->password;

        if (null === $user) {
            // Calcula um hash mesmo sem usuário: a resposta demora o mesmo tempo que uma senha errada,
            // então não dá para descobrir pelo tempo de resposta quais e-mails têm conta.
            $this->passwordHasher->hashPassword(new User(), $password);

            throw new InvalidCredentialsException();
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            throw new InvalidCredentialsException();
        }

        // Aproveita o login para limpar tokens vencidos (de qualquer usuário)
        $this->apiTokenRepository->deleteExpired();

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d seconds', $this->tokenTtl));

        $this->apiTokenRepository->save(new ApiToken($user, ApiToken::hash($plainToken), $expiresAt));

        return new LoginOutput($plainToken, $expiresAt, UserOutput::fromEntity($user));
    }

    public function logout(string $plainToken): void
    {
        $token = $this->apiTokenRepository->findOneByPlainToken($plainToken);

        if (null !== $token) {
            $this->apiTokenRepository->remove($token);
        }
    }
}
