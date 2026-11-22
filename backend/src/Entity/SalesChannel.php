<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SalesChannelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Canal de venda por onde as compras chegam (ex.: API própria, Shopify, WooCommerce).
 * O "code" é o identificador fixo usado no código.
 */
#[ORM\Entity(repositoryClass: SalesChannelRepository::class)]
#[ORM\Table(
    name: 'sales_channels',
    options: ['comment' => 'Canais de venda de onde as compras se originam (API, Shopify, WooCommerce...)'],
)]
#[ORM\UniqueConstraint(name: 'uniq_sales_channels_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class SalesChannel
{
    use Timestampable;

    /** Canal das compras recebidas pelo endpoint POST /api/purchases (cadastrado na migration de seed) */
    public const API = 'API';

    /** Canal dos pedidos recebidos pelo webhook POST /api/webhooks/woocommerce (cadastrado na migration de seed) */
    public const WOOCOMMERCE = 'WOOCOMMERCE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50, options: ['comment' => 'Código único do canal, ex.: API, SHOPIFY, WOOCOMMERCE'])]
    private string $code;

    #[ORM\Column(length: 100, options: ['comment' => 'Nome de exibição do canal'])]
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
