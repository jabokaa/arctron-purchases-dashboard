<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Usuário que acessa o painel.
 *
 * O e-mail é o login (único, sempre em minúsculas). A senha é guardada só como hash
 * (algoritmo "auto" do Symfony, ver config/packages/security.yaml), nunca em texto puro.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users', options: ['comment' => 'Usuários que acessam o painel'])]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use Timestampable;

    public const ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 180, options: ['comment' => 'E-mail usado no login, em minúsculas'])]
    private string $email;

    #[ORM\Column(length: 255, options: ['comment' => 'Nome de exibição'])]
    private string $name;

    #[ORM\Column(length: 255, options: ['comment' => 'Hash da senha'])]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['comment' => 'Perfis de acesso além de ROLE_USER, ex.: ["ROLE_ADMIN"]'])]
    private array $roles = [];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** Recebe o HASH da senha (gerado pelo UserPasswordHasherInterface), não a senha em texto */
    public function setPassword(string $passwordHash): static
    {
        $this->password = $passwordHash;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_USER]));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** Não há dados sensíveis temporários (ex.: senha em texto) guardados na entidade */
    public function eraseCredentials(): void
    {
    }
}
