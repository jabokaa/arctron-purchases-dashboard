<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de documento aceito para identificar clientes (CPF, CNPJ, passaporte...).
 * Tabela de domínio: permite novos tipos sem alterar o schema.
 */
#[ORM\Entity(repositoryClass: DocumentTypeRepository::class)]
#[ORM\Table(
    name: 'document_types',
    options: ['comment' => 'Tipos de documento de identificação (CPF, CNPJ, PASSPORT...)'],
)]
#[ORM\UniqueConstraint(name: 'uniq_document_types_code', columns: ['code'])]
class DocumentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50, options: ['comment' => 'Código único do tipo, ex.: CPF, CNPJ, PASSPORT'])]
    private string $code;

    #[ORM\Column(length: 100, options: ['comment' => 'Nome de exibição do tipo de documento'])]
    private string $name;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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
}
