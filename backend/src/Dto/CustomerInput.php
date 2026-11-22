<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Cpf;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dados do cliente recebidos no endpoint de compras (campo "customer" do JSON).
 *
 * As propriedades aceitam null para que campos ausentes cheguem até a validação
 * e voltem como erro 422 com mensagem clara, em vez de erro de desserialização.
 */
final class CustomerInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'O nome do cliente é obrigatório.')]
        #[Assert\Length(max: 255, maxMessage: 'O nome do cliente deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $name = null,

        #[Assert\NotBlank(message: 'O e-mail do cliente é obrigatório.')]
        #[Assert\Email(message: 'O e-mail {{ value }} não é válido.')]
        #[Assert\Length(max: 255, maxMessage: 'O e-mail deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $email = null,

        /** CPF com ou sem máscara (123.456.789-09 ou 12345678909) */
        #[Assert\NotBlank(message: 'O CPF do cliente é obrigatório.')]
        #[Cpf]
        public readonly ?string $cpf = null,
    ) {}
}
