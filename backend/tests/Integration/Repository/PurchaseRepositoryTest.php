<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Dto\PurchaseFilterInput;
use App\Entity\CompanyCustomer;
use App\Entity\Customer;
use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Repository\PurchaseRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Testes do PurchaseRepository::search() (consulta de compras com filtros e paginação).
 *
 * O que é testado: se a consulta SQL devolve exatamente as compras esperadas para cada filtro,
 * na ordem certa (mais recentes primeiro), com a paginação e os itens corretos.
 *
 * Por que fica na pasta de integração: o trabalho do repositório é montar e executar SQL
 * (joins da herança de clientes, LIKE sem diferenciar maiúsculas, intervalos de data e valor,
 * paginação com itens). Um mock do Doctrine só provaria que métodos foram chamados, não que
 * a consulta devolve as compras certas. Por isso o teste usa o banco de teste real (app_test).
 *
 * Como é testado:
 * - Antes de cada teste o banco é esvaziado (IntegrationTestCase) e as 5 compras abaixo
 *   são gravadas direto pelo Doctrine (sem passar pela API):
 *
 *   | Código    | Cliente                                    | Data (UTC)          | Valor | Itens |
 *   |-----------|--------------------------------------------|---------------------|-------|-------|
 *   | PED-004   | João Silva (CPF 52998224725)               | 2026-12-02 09:00:00 | 50000 | 1     |
 *   | VENDA-003 | Loja Exemplo LTDA / "Loja Bacana" (CNPJ)   | 2026-11-16 00:00:00 | 20000 | 2     |
 *   | PED-002   | Maria Souza (CPF 11144477735)              | 2026-11-15 23:59:59 |  5000 | 3     |
 *   | PED-001   | João Silva (CPF 52998224725)               | 2026-11-01 13:00:00 |  1000 | 1     |
 *   | ABC_100%  | Nova Empresa SA (CNPJ alfanumérico)        | 2026-10-10 10:00:00 |   300 | 1     |
 *
 *   (já em ordem do mais recente para o mais antigo, que é a ordem da consulta)
 */
final class PurchaseRepositoryTest extends IntegrationTestCase
{
    private PurchaseRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(PurchaseRepository::class);
        $this->createPurchases();
    }

    // =========================================================================
    // Filtros: cada caso confere quais compras voltam e em que ordem
    // =========================================================================

    /**
     * Para cada caso do provider, aplica os filtros e espera exatamente as compras listadas, nessa ordem.
     *
     * @param array<string, mixed> $filters argumentos do PurchaseFilterInput
     * @param list<string>         $expected códigos de referência esperados
     */
    #[DataProvider('filterProvider')]
    public function testFiltersPurchases(array $filters, array $expected): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(...$filters));

        self::assertSame($expected, self::referenceCodes($result['items']));
        self::assertSame(\count($expected), $result['total']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function filterProvider(): iterable
    {
        // --- Sem filtro ---

        // Sem nenhum filtro: todas as compras, da mais recente para a mais antiga
        yield 'sem filtros' => [[], ['PED-004', 'VENDA-003', 'PED-002', 'PED-001', 'ABC_100%']];

        // Nenhuma compra bate com o filtro: lista vazia
        yield 'nenhum resultado' => [['customerName' => 'ninguém'], []];

        // --- Código de referência ---

        // Parte do código, em minúsculas: encontra os que contêm "PED"
        yield 'código parcial sem diferenciar maiúsculas' => [['referenceCode' => 'ped'], ['PED-004', 'PED-002', 'PED-001']];

        // Código completo
        yield 'código completo' => [['referenceCode' => 'VENDA-003'], ['VENDA-003']];

        // "%" digitado pelo usuário é texto, não curinga: só encontra o código que tem "100%"
        yield 'código com % literal' => [['referenceCode' => '100%'], ['ABC_100%']];

        // "_" digitado pelo usuário é texto, não curinga de "um caractere qualquer"
        yield 'código com _ literal' => [['referenceCode' => '_'], ['ABC_100%']];

        // --- Nome do cliente ---

        // Nome de pessoa física, em minúsculas
        yield 'nome de pessoa física' => [['customerName' => 'joão'], ['PED-004', 'PED-001']];

        // Sobrenome em maiúsculas
        yield 'sobrenome em maiúsculas' => [['customerName' => 'SOUZA'], ['PED-002']];

        // Parte da razão social de pessoa jurídica
        yield 'razão social' => [['customerName' => 'exemplo'], ['VENDA-003']];

        // Parte do nome fantasia de pessoa jurídica
        yield 'nome fantasia' => [['customerName' => 'bacana'], ['VENDA-003']];

        // --- E-mail ---

        // Parte do e-mail
        yield 'parte do e-mail' => [['customerEmail' => 'gmail'], ['PED-002']];

        // Domínio comum a vários clientes
        yield 'domínio do e-mail' => [['customerEmail' => '.com.br'], ['ABC_100%']];

        // --- Documento ---

        // CPF com máscara
        yield 'CPF com máscara' => [['customerDocument' => '529.982.247-25'], ['PED-004', 'PED-001']];

        // CPF sem máscara
        yield 'CPF sem máscara' => [['customerDocument' => '11144477735'], ['PED-002']];

        // CNPJ numérico com máscara
        yield 'CNPJ com máscara' => [['customerDocument' => '11.222.333/0001-81'], ['VENDA-003']];

        // CNPJ alfanumérico em minúsculas e com máscara
        yield 'CNPJ alfanumérico' => [['customerDocument' => '12.abc.345/01de-35'], ['ABC_100%']];

        // Documento é comparado inteiro: parte do CPF não encontra nada
        yield 'parte do documento' => [['customerDocument' => '52998'], []];

        // --- Data ---

        // Um único dia (de e até o mesmo dia): inclui até 23:59:59 e não inclui o dia seguinte
        yield 'um dia inteiro' => [['dateFrom' => '2026-11-15', 'dateTo' => '2026-11-15'], ['PED-002']];

        // Só a data inicial: a partir da meia-noite do dia, inclusive
        yield 'a partir de uma data' => [['dateFrom' => '2026-11-16'], ['PED-004', 'VENDA-003']];

        // Só a data final: até o fim do dia, inclusive
        yield 'até uma data' => [['dateTo' => '2026-11-01'], ['PED-001', 'ABC_100%']];

        // Data e hora: o horário final é incluído
        yield 'até um horário exato' => [['dateTo' => '2026-11-15T23:59:59Z'], ['PED-002', 'PED-001', 'ABC_100%']];

        // Data e hora com fuso: 2026-11-15T21:00:00-03:00 = 2026-11-16T00:00:00 UTC
        yield 'horário com fuso -03:00' => [['dateFrom' => '2026-11-15T21:00:00-03:00', 'dateTo' => '2026-11-15T21:00:00-03:00'], ['VENDA-003']];

        // --- Valor ---

        // Intervalo de valores, com os limites incluídos
        yield 'intervalo de valores' => [['amountMin' => 5000, 'amountMax' => 20000], ['VENDA-003', 'PED-002']];

        // Só o valor mínimo
        yield 'valor mínimo' => [['amountMin' => 20000], ['PED-004', 'VENDA-003']];

        // Só o valor máximo
        yield 'valor máximo' => [['amountMax' => 1000], ['PED-001', 'ABC_100%']];

        // --- Combinação ---

        // Vários filtros juntos: todos precisam bater
        yield 'nome e valor juntos' => [['customerName' => 'joão', 'amountMin' => 10000], ['PED-004']];

        // Filtros que não têm compra em comum
        yield 'filtros sem interseção' => [['customerName' => 'maria', 'customerDocument' => '52998224725'], []];
    }

    // =========================================================================
    // Paginação e itens
    // =========================================================================

    /**
     * Segunda página com 2 compras por página.
     * Espera a 3ª e a 4ª compras, e o total continua sendo o de todas as compras (5).
     */
    public function testPaginatesResults(): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(page: 2, perPage: 2));

        self::assertSame(['PED-002', 'PED-001'], self::referenceCodes($result['items']));
        self::assertSame(5, $result['total']);
    }

    /**
     * Página além da última.
     * Espera lista vazia, mas com o total de compras.
     */
    public function testPageBeyondTheLastReturnsEmpty(): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(page: 10, perPage: 2));

        self::assertSame([], $result['items']);
        self::assertSame(5, $result['total']);
    }

    /**
     * Compras com vários itens ocupam várias linhas no JOIN do SQL.
     * Espera que a página conte COMPRAS e não linhas: 2 por página = 2 compras,
     * mesmo com a VENDA-003 tendo 2 itens.
     */
    public function testPageSizeCountsPurchasesNotItemRows(): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(page: 1, perPage: 2));

        self::assertSame(['PED-004', 'VENDA-003'], self::referenceCodes($result['items']));
    }

    /**
     * Itens e produtos são carregados junto com a compra.
     * Espera todos os itens de cada compra (PED-002 tem 3), com o produto preenchido.
     */
    public function testLoadsAllItemsOfEachPurchase(): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(referenceCode: 'PED-002'));

        $items = $result['items'][0]->getItems();
        self::assertCount(3, $items);
        self::assertSame('Produto Teste', $items->first()->getProduct()->getName());
    }

    /**
     * O cliente volta com o tipo certo (pessoa física ou jurídica) e os dados da tabela de cada tipo.
     */
    public function testLoadsCustomerWithItsType(): void
    {
        $result = $this->repository->search(new PurchaseFilterInput(customerDocument: '11222333000181'));

        $customer = $result['items'][0]->getCustomer();
        self::assertInstanceOf(CompanyCustomer::class, $customer);
        self::assertSame('Loja Bacana', $customer->getTradeName());
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Grava as 5 compras da tabela do comentário da classe.
     */
    private function createPurchases(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $product = (new Product())->setName('Produto Teste');
        $em->persist($product);

        $joao = (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com');
        $maria = (new IndividualCustomer())->setFullName('Maria Souza')->setCpf('11144477735')->setEmail('maria@gmail.com');
        $loja = (new CompanyCustomer())->setLegalName('Loja Exemplo LTDA')->setTradeName('Loja Bacana')->setCnpj('11222333000181')->setEmail('contato@loja.com');
        $nova = (new CompanyCustomer())->setLegalName('Nova Empresa SA')->setCnpj('12ABC34501DE35')->setEmail('financeiro@nova.com.br');

        foreach ([$joao, $maria, $loja, $nova] as $customer) {
            $em->persist($customer);
        }

        $purchases = [
            ['PED-001', $joao, '2026-11-01 13:00:00', 1000, 1],
            ['PED-002', $maria, '2026-11-15 23:59:59', 5000, 3],
            ['VENDA-003', $loja, '2026-11-16 00:00:00', 20000, 2],
            ['PED-004', $joao, '2026-12-02 09:00:00', 50000, 1],
            ['ABC_100%', $nova, '2026-10-10 10:00:00', 300, 1],
        ];

        foreach ($purchases as [$referenceCode, $customer, $purchasedAt, $amount, $itemCount]) {
            $em->persist(self::purchase($referenceCode, $customer, $channel, $product, $purchasedAt, $amount, $itemCount));
        }

        $em->flush();

        // Esvazia a memória do Doctrine: a consulta precisa buscar tudo no banco, não reaproveitar objetos já carregados
        $em->clear();
    }

    private static function purchase(
        string $referenceCode,
        Customer $customer,
        SalesChannel $channel,
        Product $product,
        string $purchasedAt,
        int $amount,
        int $itemCount,
    ): Purchase {
        $purchase = (new Purchase())
            ->setReferenceCode($referenceCode)
            ->setCustomer($customer)
            ->setSalesChannel($channel)
            ->setAmount($amount)
            ->setDiscount(0)
            ->setPurchasedAt(new \DateTimeImmutable($purchasedAt, new \DateTimeZone('UTC')));

        // Divide o valor entre os itens só para manter os dados coerentes
        for ($i = 0; $i < $itemCount; ++$i) {
            $purchase->addItem(
                (new PurchaseItem())->setProduct($product)->setQuantity(1)->setUnitPrice(intdiv($amount, $itemCount))->setTotalPrice(intdiv($amount, $itemCount)),
            );
        }

        return $purchase;
    }

    /**
     * @param list<Purchase> $purchases
     *
     * @return list<string>
     */
    private static function referenceCodes(array $purchases): array
    {
        return array_map(static fn (Purchase $purchase): string => $purchase->getReferenceCode(), $purchases);
    }
}
