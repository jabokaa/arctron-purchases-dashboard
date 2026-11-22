<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cliente que realiza compras. Classe base (abstrata) dos dois tipos de cliente:
 * - IndividualCustomer: pessoa física (CPF)
 * - CompanyCustomer: pessoa jurídica (CNPJ)
 *
 * Herança JOINED (Class Table Inheritance): os campos comuns ficam na tabela customers
 * e os campos de cada tipo ficam em individual_customers / company_customers (relação 1:1 pelo id).
 * A coluna person_type (discriminador) diz ao Doctrine de qual tipo é cada registro.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers', options: ['comment' => 'Clientes que realizam compras (campos comuns a pessoa física e jurídica)'])]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(
    name: 'person_type',
    type: 'string',
    length: 20,
    options: ['comment' => 'Tipo do cliente: INDIVIDUAL (pessoa física) ou COMPANY (pessoa jurídica)'],
)]
#[ORM\DiscriminatorMap([
    IndividualCustomer::TYPE => IndividualCustomer::class,
    CompanyCustomer::TYPE => CompanyCustomer::class,
])]
#[ORM\HasLifecycleCallbacks]
abstract class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'E-mail de contato do cliente (opcional)'])]
    private ?string $email = null;

    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['comment' => 'Data da última atualização do registro'])]
    private \DateTimeImmutable $updatedAt;

    /** Tipo do cliente (valor do discriminador): INDIVIDUAL ou COMPANY */
    abstract public function getType(): string;

    /** Nome para exibição: nome da pessoa ou razão social da empresa */
    abstract public function getName(): string;

    /** Grava o nome no campo do tipo: fullName (pessoa física) ou legalName (pessoa jurídica) */
    abstract public function setName(string $name): static;

    /** Documento sem máscara: CPF ou CNPJ */
    abstract public function getDocument(): string;

    /** Tipo do documento: CPF (pessoa física) ou CNPJ (pessoa jurídica) */
    abstract public function getDocumentType(): string;

    /** Grava o documento no campo do tipo: cpf (pessoa física) ou cnpj (pessoa jurídica) */
    abstract public function setDocument(string $document): static;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
