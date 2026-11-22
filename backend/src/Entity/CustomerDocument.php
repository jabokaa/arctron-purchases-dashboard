<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Documento de identificação de um cliente.
 * Um mesmo número de um mesmo tipo só pode pertencer a um cliente,
 * o que permite localizar o cliente pelo documento.
 */
#[ORM\Entity(repositoryClass: CustomerDocumentRepository::class)]
#[ORM\Table(
    name: 'customer_documents',
    options: ['comment' => 'Documentos de identificação de cada cliente; um número de um tipo pertence a um único cliente'],
)]
#[ORM\UniqueConstraint(name: 'uniq_customer_documents_type_number', columns: ['document_type_id', 'number'])]
#[ORM\Index(name: 'idx_customer_documents_customer', columns: ['customer_id'])]
#[ORM\HasLifecycleCallbacks]
class CustomerDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Cliente dono do documento; ao remover o cliente, seus documentos são removidos juntos */
    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: DocumentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DocumentType $documentType;

    #[ORM\Column(length: 50, options: ['comment' => 'Número do documento sem máscara (apenas caracteres significativos)'])]
    private string $number;

    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
