<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Token de acesso emitido no login (POST /api/auth/login).
 *
 * O front envia o token em cada requisição no cabeçalho "Authorization: Bearer <token>".
 * Só o hash SHA-256 do token fica no banco: se a tabela vazar, os tokens não podem ser usados.
 * O logout apaga o registro, e o token deixa de valer na hora.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens', options: ['comment' => 'Tokens de acesso emitidos no login'])]
#[ORM\UniqueConstraint(name: 'uniq_api_tokens_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_api_tokens_expires_at', columns: ['expires_at'])]
#[ORM\HasLifecycleCallbacks]
class ApiToken
{
    use Timestampable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Se o usuário for apagado, os tokens dele são apagados junto */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, options: ['fixed' => true, 'comment' => 'Hash SHA-256 (hex) do token; o token em si não é guardado'])]
    private string $tokenHash;

    #[ORM\Column(options: ['comment' => 'Quando o token deixa de valer'])]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }
}
