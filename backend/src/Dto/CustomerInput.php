<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Cnpj;
use App\Validator\Cpf;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Dados do cliente recebidos no endpoint de compras (campo "customer" do JSON).
 *
 * O tipo do cliente é definido pelo documento enviado:
 * - cpf  → pessoa física   ({"name", "email", "cpf"})
 * - cnpj → pessoa jurídica ({"name", "trade_name", "email", "cnpj"}); "name" é a razão social
 * Deve vir exatamente um dos dois.
 *
 * As propriedades aceitam null para que campos ausentes cheguem até a validação
 * e voltem como erro 422 com mensagem clara, em vez de erro de desserialização.
 */
final class CustomerInput
{
    public function __construct(
        /** Nome da pessoa (CPF) ou razão social da empresa (CNPJ) */
        #[Assert\NotBlank(message: 'O nome do cliente é obrigatório.')]
        #[Assert\Length(max: 255, maxMessage: 'O nome do cliente deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $name = null,

        #[Assert\NotBlank(message: 'O e-mail do cliente é obrigatório.')]
        #[Assert\Email(message: 'O e-mail {{ value }} não é válido.')]
        #[Assert\Length(max: 255, maxMessage: 'O e-mail deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $email = null,

        /** CPF com ou sem máscara (123.456.789-09 ou 12345678909) — só para pessoa física */
        #[Cpf]
        public readonly ?string $cpf = null,

        /** CNPJ com ou sem máscara, numérico ou alfanumérico — só para pessoa jurídica */
        #[Cnpj]
        public readonly ?string $cnpj = null,

        /** Nome fantasia (opcional) — só para pessoa jurídica */
        #[Assert\Length(max: 255, maxMessage: 'O nome fantasia deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $tradeName = null,
    ) {}

    public function isCompany(): bool
    {
        return null !== $this->cnpj && '' !== $this->cnpj;
    }

    /**
     * Regra entre campos: exatamente um documento (CPF ou CNPJ),
     * e nome fantasia só faz sentido para pessoa jurídica.
     */
    #[Assert\Callback]
    public function validateDocument(ExecutionContextInterface $context): void
    {
        $hasCpf = null !== $this->cpf && '' !== $this->cpf;
        $hasCnpj = $this->isCompany();

        if (!$hasCpf && !$hasCnpj) {
            $context->buildViolation('Informe o CPF (pessoa física) ou o CNPJ (pessoa jurídica) do cliente.')
                ->atPath('cpf')
                ->addViolation();
        }

        if ($hasCpf && $hasCnpj) {
            $context->buildViolation('Informe apenas um documento: CPF (pessoa física) ou CNPJ (pessoa jurídica).')
                ->atPath('cnpj')
                ->addViolation();
        }

        if ($hasCpf && null !== $this->tradeName && '' !== $this->tradeName) {
            $context->buildViolation('Nome fantasia só pode ser informado para pessoa jurídica (CNPJ).')
                ->atPath('tradeName')
                ->addViolation();
        }
    }
}
