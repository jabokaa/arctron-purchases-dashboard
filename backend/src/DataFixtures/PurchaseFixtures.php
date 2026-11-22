<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Dto\CustomerInput;
use App\Dto\ProductInput;
use App\Dto\PurchaseInput;
use App\Exception\DuplicatePurchaseException;
use App\Service\PurchaseServiceInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeder de compras de teste, para o dashboard e os rankings aparecerem com dados.
 *
 * Cria 40 compras dos últimos ~4 meses (datas contadas a partir de hoje), de 8 clientes (pessoas físicas e
 * empresas) e com os produtos do ProductFixtures, algumas com desconto. Os dados são fixos (nada aleatório) e as
 * compras passam pelo mesmo PurchaseService do POST /api/purchases, então seguem as mesmas regras e cálculos.
 *
 * Cada compra tem um código de referência fixo (SEED-001...). Quem já existe é pulado, então pode rodar
 * quantas vezes quiser sem duplicar. Os produtos do ProductFixtures são cadastrados antes, se faltarem.
 *
 * Rodar SEMPRE com --append (sem ele o Doctrine apaga TODAS as tabelas antes):
 *   php bin/console doctrine:fixtures:load --append --group=purchases
 */
final class PurchaseFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const COUNT = 40;

    /**
     * @var list<array{name: string, email: string, cpf?: string, cnpj?: string, trade_name?: string}>
     */
    private const CUSTOMERS = [
        ['name' => 'João Silva', 'email' => 'joao.silva@email.com', 'cpf' => '529.982.247-25'],
        ['name' => 'Maria Souza', 'email' => 'maria.souza@email.com', 'cpf' => '111.444.777-35'],
        ['name' => 'Carlos Pereira', 'email' => 'carlos.pereira@email.com', 'cpf' => '390.533.447-05'],
        ['name' => 'Ana Lima', 'email' => 'ana.lima@email.com', 'cpf' => '123.456.789-09'],
        ['name' => 'Pedro Almeida', 'email' => 'pedro.almeida@email.com', 'cpf' => '935.411.347-80'],
        ['name' => 'Fernanda Costa', 'email' => 'fernanda.costa@email.com', 'cpf' => '746.971.314-01'],
        ['name' => 'Esporte Total Comércio Ltda', 'trade_name' => 'Esporte Total', 'email' => 'compras@esportetotal.com.br', 'cnpj' => '11.222.333/0001-81'],
        ['name' => 'Clube do Bairro Associação Esportiva', 'trade_name' => 'Clube do Bairro', 'email' => 'financeiro@clubedobairro.org.br', 'cnpj' => '11.444.777/0001-61'],
    ];

    /**
     * [código no canal API, nome, preço unitário em centavos]
     *
     * @var list<array{string, string, int}>
     */
    private const PRODUCTS = [
        ['CHU-001', 'Chuteira de Campo Speedster', 29990],
        ['CHU-002', 'Chuteira Clássica de Couro', 24990],
        ['BOL-001', 'Bola de Futebol de Campo', 8990],
        ['BOL-002', 'Bola Telstar 18 – Copa do Mundo 2018', 12990],
        ['BOL-003', 'Bola Retrô Telstar 1970 – Tri do Brasil', 17990],
        ['LUV-001', 'Luvas de Goleiro', 15990],
        ['CAM-001', 'Camisa Retrô Seleção Brasileira 1962', 39990],
        ['CAM-002', 'Camisa Retrô Seleção Brasileira 1966', 39990],
        ['CAM-003', 'Camisa Seleção Brasileira – Uniforme Titular', 34990],
        ['ARB-001', 'Apito de Árbitro', 2990],
        ['RED-001', 'Rede de Gol para Campo', 19990],
    ];

    /** Clientes mais frequentes aparecem mais vezes (índice em CUSTOMERS), para o ranking ter variação */
    private const CUSTOMER_PATTERN = [0, 6, 1, 0, 2, 6, 0, 3, 1, 6, 4, 0, 2, 7, 6, 5, 0, 1, 6, 2];

    private const DISCOUNTS = [0, 0, 0, 500, 0, 1000, 0, 0, 1500, 0];

    public function __construct(
        private readonly PurchaseServiceInterface $purchaseService,
    ) {
    }

    public static function getGroups(): array
    {
        return ['purchases'];
    }

    public function getDependencies(): array
    {
        return [ProductFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $today = new \DateTimeImmutable('today 12:00:00', new \DateTimeZone('UTC'));

        for ($i = 0; $i < self::COUNT; ++$i) {
            // Compras espalhadas pelos últimos ~120 dias, da mais antiga para a mais recente
            $daysAgo = (int) round((self::COUNT - 1 - $i) * 3 + (($i * 7) % 3));

            try {
                $this->purchaseService->create(new PurchaseInput(
                    referenceCode: \sprintf('SEED-%03d', $i + 1),
                    purchasedAt: $today->modify(\sprintf('-%d days', $daysAgo)),
                    notes: 0 === $i % 5 ? 'Compra de exemplo criada pelo seeder' : null,
                    customer: $this->customer($i),
                    products: $this->items($i),
                ));
            } catch (DuplicatePurchaseException) {
                // já cadastrada em uma execução anterior
            }
        }
    }

    private function customer(int $index): CustomerInput
    {
        $data = self::CUSTOMERS[self::CUSTOMER_PATTERN[$index % \count(self::CUSTOMER_PATTERN)]];

        return new CustomerInput(
            name: $data['name'],
            email: $data['email'],
            cpf: $data['cpf'] ?? null,
            cnpj: $data['cnpj'] ?? null,
            tradeName: $data['trade_name'] ?? null,
        );
    }

    /**
     * @return list<ProductInput>
     */
    private function items(int $index): array
    {
        $items = [];
        $productCount = 1 + $index % 3;

        for ($n = 0; $n < $productCount; ++$n) {
            [$code, $name, $price] = self::PRODUCTS[($index * 3 + $n * 5) % \count(self::PRODUCTS)];

            $items[] = new ProductInput(
                externalId: $code,
                name: $name,
                quantity: 1 + ($index + $n) % 4,
                unitPrice: $price,
                discount: 0 === $n ? self::DISCOUNTS[$index % \count(self::DISCOUNTS)] : 0,
            );
        }

        return $items;
    }
}
