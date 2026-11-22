<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cliente que realiza compras.
 * Os documentos (CPF, CNPJ...) ficam em CustomerDocument, pois um cliente pode ter mais de um.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers', options: ['comment' => 'Clientes que realizam compras'])]
#[ORM\HasLifecycleCallbacks]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255, options: ['comment' => 'Nome do cliente'])]
    private string $name;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'E-mail de contato do cliente (opcional)'])]
    private ?string $email = null;

    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['comment' => 'Data da última atualização do registro'])]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CustomerDocument> */
    #[ORM\OneToMany(targetEntity: CustomerDocument::class, mappedBy: 'customer', cascade: ['persist'], orphanRemoval: true)]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
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

    /** @return Collection<int, CustomerDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(CustomerDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setCustomer($this);
        }

        return $this;
    }

    public function removeDocument(CustomerDocument $document): static
    {
        $this->documents->removeElement($document);

        return $this;
    }
}
