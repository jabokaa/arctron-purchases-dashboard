<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CompanyCustomerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cliente pessoa jurídica, identificado pelo CNPJ.
 * Os campos comuns (e-mail, datas) ficam na tabela customers (classe Customer).
 */
#[ORM\Entity(repositoryClass: CompanyCustomerRepository::class)]
#[ORM\Table(name: 'company_customers', options: ['comment' => 'Dados específicos de clientes pessoa jurídica'])]
#[ORM\UniqueConstraint(name: 'uniq_company_customers_cnpj', columns: ['cnpj'])]
#[ORM\HasLifecycleCallbacks]
class CompanyCustomer extends Customer
{
    public const TYPE = 'COMPANY';

    #[ORM\Column(length: 255, options: ['comment' => 'Razão social da empresa'])]
    private string $legalName;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Nome fantasia da empresa (opcional)'])]
    private ?string $tradeName = null;

    #[ORM\Column(length: 14, options: ['comment' => 'CNPJ sem máscara (14 caracteres, aceita o formato alfanumérico), único por cliente'])]
    private string $cnpj;

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getName(): string
    {
        return $this->legalName;
    }

    public function setName(string $name): static
    {
        return $this->setLegalName($name);
    }

    public function getDocument(): string
    {
        return $this->cnpj;
    }

    public function setDocument(string $document): static
    {
        return $this->setCnpj($document);
    }

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function setLegalName(string $legalName): static
    {
        $this->legalName = $legalName;

        return $this;
    }

    public function getTradeName(): ?string
    {
        return $this->tradeName;
    }

    public function setTradeName(?string $tradeName): static
    {
        $this->tradeName = $tradeName;

        return $this;
    }

    public function getCnpj(): string
    {
        return $this->cnpj;
    }

    public function setCnpj(string $cnpj): static
    {
        $this->cnpj = $cnpj;

        return $this;
    }
}
