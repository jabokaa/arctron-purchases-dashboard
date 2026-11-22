<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produto do catálogo interno.
 * O internalCode é o código do produto no nosso sistema (opcional);
 * os códigos de cada canal de venda ficam em ProductExternalId.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products', options: ['comment' => 'Catálogo interno de produtos'])]
#[ORM\UniqueConstraint(name: 'uniq_products_internal_code', columns: ['internal_code'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true, options: ['comment' => 'Código interno do produto (SKU próprio), opcional e único'])]
    private ?string $internalCode = null;

    #[ORM\Column(length: 255, options: ['comment' => 'Nome do produto'])]
    private string $name;

    #[ORM\Column(length: 2048, nullable: true, options: ['comment' => 'Caminho público da imagem enviada (/uploads/products/...) ou endereço externo, opcional'])]
    private ?string $imageUrl = null;

    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['comment' => 'Data da última atualização do registro'])]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductExternalId> */
    #[ORM\OneToMany(targetEntity: ProductExternalId::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $externalIds;

    public function __construct()
    {
        $this->externalIds = new ArrayCollection();
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

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getInternalCode(): ?string
    {
        return $this->internalCode;
    }

    public function setInternalCode(?string $internalCode): static
    {
        $this->internalCode = $internalCode;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, ProductExternalId> */
    public function getExternalIds(): Collection
    {
        return $this->externalIds;
    }

    public function addExternalId(ProductExternalId $externalId): static
    {
        if (!$this->externalIds->contains($externalId)) {
            $this->externalIds->add($externalId);
            $externalId->setProduct($this);
        }

        return $this;
    }

    public function removeExternalId(ProductExternalId $externalId): static
    {
        $this->externalIds->removeElement($externalId);

        return $this;
    }
}
