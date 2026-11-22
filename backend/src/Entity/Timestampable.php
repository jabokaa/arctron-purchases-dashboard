<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Colunas created_at e updated_at, comuns a todas as tabelas.
 *
 * Preenchidas automaticamente pelo Doctrine: created_at ao inserir e updated_at ao inserir
 * e a cada atualização. A entidade que usa o trait precisa do atributo #[ORM\HasLifecycleCallbacks].
 * Se a entidade já definiu o createdAt no construtor, o valor é mantido ao inserir.
 */
trait Timestampable
{
    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['comment' => 'Data da última atualização do registro'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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
