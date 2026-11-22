<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;

/**
 * Dados do usuário devolvidos pela API (nunca inclui o hash da senha).
 */
final class UserOutput
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly array $roles,
    ) {}

    public static function fromEntity(User $user): self
    {
        return new self(
            id: (int) $user->getId(),
            name: $user->getName(),
            email: $user->getEmail(),
            roles: $user->getRoles(),
        );
    }
}
