<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndividualCustomerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cliente pessoa física, identificado pelo CPF.
 * Os campos comuns (e-mail, datas) ficam na tabela customers (classe Customer).
 */
#[ORM\Entity(repositoryClass: IndividualCustomerRepository::class)]
#[ORM\Table(name: 'individual_customers', options: ['comment' => 'Dados específicos de clientes pessoa física'])]
#[ORM\UniqueConstraint(name: 'uniq_individual_customers_cpf', columns: ['cpf'])]
#[ORM\HasLifecycleCallbacks]
class IndividualCustomer extends Customer
{
    public const TYPE = 'INDIVIDUAL';

    #[ORM\Column(length: 255, options: ['comment' => 'Nome completo da pessoa'])]
    private string $fullName;

    #[ORM\Column(length: 11, options: ['comment' => 'CPF sem máscara (11 dígitos), único por cliente'])]
    private string $cpf;

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getName(): string
    {
        return $this->fullName;
    }

    public function setName(string $name): static
    {
        return $this->setFullName($name);
    }

    public function getDocument(): string
    {
        return $this->cpf;
    }

    public function getDocumentType(): string
    {
        return 'CPF';
    }

    public function setDocument(string $document): static
    {
        return $this->setCpf($document);
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getCpf(): string
    {
        return $this->cpf;
    }

    public function setCpf(string $cpf): static
    {
        $this->cpf = $cpf;

        return $this;
    }
}
