<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomerInput;
use App\Dto\ProductInput;
use App\Dto\PurchaseInput;
use App\Entity\CompanyCustomer;
use App\Entity\Customer;
use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Exception\DuplicatePurchaseException;
use App\Repository\ProductRepository;
use App\Repository\PurchaseRepository;
use App\Repository\SalesChannelRepository;
use App\Util\DocumentNumber;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regra de negócio do recebimento de compras.
 *
 * Quando este service é chamado, o PurchaseInput já foi validado pelo #[MapRequestPayload]
 * (campos obrigatórios, CPF/CNPJ válido).
 */
final class PurchaseService implements PurchaseServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseRepository $purchases,
        private readonly ProductRepository $products,
        private readonly SalesChannelRepository $salesChannels,
    ) {
    }

    /**
     * @param string $salesChannelCode canal da compra: API (padrão) ou WOOCOMMERCE. O canal também define
     *                                 em qual catálogo de external_id os produtos são procurados.
     *
     * @throws DuplicatePurchaseException quando o código de referência já foi usado
     */
    public function create(PurchaseInput $input, string $salesChannelCode = SalesChannel::API): Purchase
    {
        // 1. Evita compra duplicada pelo código de referência
        if (null !== $this->purchases->findOneBy(['referenceCode' => $input->referenceCode])) {
            throw new DuplicatePurchaseException($input->referenceCode);
        }

        try {
            // 2. Tudo dentro de uma transação: se algo falhar, nada é salvo pela metade
            return $this->em->wrapInTransaction(function () use ($input, $salesChannelCode): Purchase {
                $salesChannel = $this->getSalesChannel($salesChannelCode);
                $customer = $this->findOrCreateCustomer($input->customer);

                $purchase = (new Purchase())
                    ->setReferenceCode($input->referenceCode)
                    ->setCustomer($customer)
                    ->setSalesChannel($salesChannel)
                    // A coluna não guarda fuso horário: grava sempre em UTC para não perder a hora real
                    ->setPurchasedAt($input->purchasedAt->setTimezone(new \DateTimeZone('UTC')))
                    ->setNotes($input->notes);

                $items = $this->buildItems($input->products, $salesChannel);

                foreach ($items as $item) {
                    $purchase->addItem($item);
                }

                // Totais da compra = soma dos itens (o payload não envia esses valores)
                $purchase
                    ->setAmount(array_sum(array_map(static fn (PurchaseItem $item): int => $item->getTotalPrice(), $items)))
                    ->setDiscount(array_sum(array_map(static fn (PurchaseItem $item): int => $item->getDiscount(), $items)));

                // cascade: ['persist'] em Purchase::$items salva os itens junto
                $this->em->persist($purchase);

                return $purchase;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Duas requisições com o mesmo código chegaram ao mesmo tempo e passaram pela checagem do passo 1:
            // o UNIQUE do banco barra a segunda.
            if (str_contains($e->getMessage(), 'uniq_purchases_reference_code')) {
                throw new DuplicatePurchaseException($input->referenceCode, $e);
            }

            // Outro UNIQUE (ex.: o mesmo produto novo criado ao mesmo tempo por outra requisição)
            throw $e;
        }
    }

    /**
     * Monta os itens da compra, buscando ou criando cada produto.
     *
     * @param ProductInput[] $products
     *
     * @return PurchaseItem[]
     */
    private function buildItems(array $products, SalesChannel $salesChannel): array
    {
        // Produtos já resolvidos nesta compra, por external_id.
        // Evita criar o mesmo produto duas vezes se ele aparecer repetido na lista.
        $resolvedProducts = [];
        $items = [];

        foreach ($products as $productInput) {
            if (!isset($resolvedProducts[$productInput->externalId])) {
                $resolvedProducts[$productInput->externalId] = $this->findOrCreateProduct($productInput, $salesChannel);
            }

            $items[] = (new PurchaseItem())
                ->setProduct($resolvedProducts[$productInput->externalId])
                ->setQuantity($productInput->quantity)
                ->setUnitPrice($productInput->unitPrice)
                ->setDiscount($productInput->discount)
                ->setTotalPrice($productInput->quantity * $productInput->unitPrice - $productInput->discount);
        }

        return $items;
    }

    /**
     * Procura o produto pelo external_id no canal de venda.
     * Se não existir, cria o produto (com o nome recebido) e o vínculo com o canal.
     */
    private function findOrCreateProduct(ProductInput $input, SalesChannel $salesChannel): Product
    {
        $product = $this->products->findOneBySalesChannelAndExternalId($salesChannel->getCode(), $input->externalId);

        if (null !== $product) {
            return $product;
        }

        $product = (new Product())->setName($input->name);

        // cascade: ['persist'] em Product::$externalIds salva o vínculo junto
        $product->addExternalId(
            (new ProductExternalId())
                ->setSalesChannel($salesChannel)
                ->setExternalId($input->externalId),
        );

        $this->em->persist($product);

        return $product;
    }

    /**
     * Procura o cliente pelo documento: CNPJ (pessoa jurídica) ou CPF (pessoa física).
     * Se existir, atualiza os dados; se não, cria.
     */
    private function findOrCreateCustomer(CustomerInput $input): Customer
    {
        // As diferenças entre os tipos ficam resolvidas aqui: classe, campo de busca e normalização do documento
        [$class, $documentField, $document] = $input->isCompany()
            ? [CompanyCustomer::class, 'cnpj', DocumentNumber::normalizeCnpj($input->cnpj)]
            : [IndividualCustomer::class, 'cpf', DocumentNumber::onlyDigits($input->cpf)];

        /** @var Customer|null $customer */
        $customer = $this->em->getRepository($class)->findOneBy([$documentField => $document]);

        if (null === $customer) {
            $customer = (new $class())->setDocument($document);
            $this->em->persist($customer);
        }

        // setName grava em fullName (pessoa física) ou legalName (pessoa jurídica)
        $customer
            ->setName($input->name)
            ->setEmail($input->email);

        // Único campo que só existe em um dos tipos
        if ($customer instanceof CompanyCustomer) {
            $customer->setTradeName($input->tradeName);
        }

        return $customer;
    }

    private function getSalesChannel(string $code): SalesChannel
    {
        return $this->salesChannels->findOneBy(['code' => $code])
            ?? throw new \LogicException(\sprintf('Canal de venda "%s" não cadastrado. Rode as migrations.', $code));
    }
}
