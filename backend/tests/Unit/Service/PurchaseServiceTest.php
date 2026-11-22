<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\CustomerInput;
use App\Dto\ProductInput;
use App\Dto\PurchaseInput;
use App\Entity\CompanyCustomer;
use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\Purchase;
use App\Entity\SalesChannel;
use App\Exception\DuplicatePurchaseException;
use App\Repository\CompanyCustomerRepository;
use App\Repository\IndividualCustomerRepository;
use App\Repository\ProductRepository;
use App\Repository\PurchaseRepository;
use App\Repository\SalesChannelRepository;
use App\Service\PurchaseService;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do PurchaseService (regra de negócio do recebimento de compras).
 *
 * O que é testado: cada caminho do service, para cobrir 100% do código:
 * - compra duplicada (antes da transação e pelo UNIQUE do banco);
 * - outros erros de UNIQUE repassados sem alteração;
 * - canal de venda API ausente;
 * - cliente pessoa física e jurídica, novo e já cadastrado;
 * - produto novo, produto já cadastrado e produto repetido na mesma compra;
 * - cálculo dos totais dos itens e da compra;
 * - conversão da data da compra para UTC.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O EntityManager e os repositórios são dublês de teste:
 *   - STUB (createStub): só devolve valores. É o padrão, montado no setUp().
 *   - MOCK (createMock + expects): também confere COMO foi chamado. Cada teste troca por mock
 *     só a dependência que precisa verificar (ex.: "buscou pelo CPF sem máscara uma vez").
 * - O wrapInTransaction do EntityManager apenas executa a função recebida, como a transação real faria.
 * - Os objetos passados para persist() ficam guardados em $persisted para o teste conferir o que seria salvo.
 */
final class PurchaseServiceTest extends TestCase
{
    private PurchaseRepository&Stub $purchaseRepository;

    private ProductRepository&Stub $productRepository;

    private SalesChannelRepository&Stub $salesChannelRepository;

    private IndividualCustomerRepository&Stub $individualCustomerRepository;

    private CompanyCustomerRepository&Stub $companyCustomerRepository;

    private SalesChannel $apiChannel;

    /** @var list<object> objetos passados para $em->persist(), na ordem */
    private array $persisted = [];

    /**
     * Comportamento padrão das dependências (stubs), que cada teste pode trocar por um mock:
     * nenhuma compra com o mesmo código, canal API cadastrado, cliente e produto ainda não cadastrados.
     */
    protected function setUp(): void
    {
        $this->apiChannel = (new SalesChannel())->setCode(SalesChannel::API)->setName('API');

        $this->purchaseRepository = $this->createStub(PurchaseRepository::class);
        $this->purchaseRepository->method('findOneBy')->willReturn(null);

        $this->salesChannelRepository = $this->createStub(SalesChannelRepository::class);
        $this->salesChannelRepository->method('findOneBy')->willReturn($this->apiChannel);

        $this->productRepository = $this->createStub(ProductRepository::class);
        $this->productRepository->method('findOneBySalesChannelAndExternalId')->willReturn(null);

        $this->individualCustomerRepository = $this->createStub(IndividualCustomerRepository::class);
        $this->individualCustomerRepository->method('findOneBy')->willReturn(null);

        $this->companyCustomerRepository = $this->createStub(CompanyCustomerRepository::class);
        $this->companyCustomerRepository->method('findOneBy')->willReturn(null);
    }

    // =========================================================================
    // Compra duplicada e erros do banco
    // =========================================================================

    /**
     * Já existe uma compra com o mesmo reference_code.
     * Espera DuplicatePurchaseException ANTES de abrir a transação (nada é salvo nem consultado depois).
     */
    public function testThrowsDuplicateWhenReferenceCodeAlreadyExists(): void
    {
        $purchaseRepository = $this->createMock(PurchaseRepository::class);
        $purchaseRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['referenceCode' => 'PED-1'])
            ->willReturn(new Purchase());

        $this->purchaseRepository = $purchaseRepository;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('wrapInTransaction');

        $this->expectException(DuplicatePurchaseException::class);
        $this->expectExceptionMessage('Já existe uma compra com o código de referência "PED-1".');

        $this->service($em)->create(self::input());
    }

    /**
     * Duas requisições iguais ao mesmo tempo: a checagem inicial passa, mas o UNIQUE
     * do reference_code no banco recusa a segunda.
     * Espera que o erro do banco vire DuplicatePurchaseException, guardando o erro original.
     */
    public function testConvertsReferenceCodeUniqueViolationIntoDuplicateException(): void
    {
        $dbError = self::uniqueViolation('duplicate key value violates unique constraint "uniq_purchases_reference_code"');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($dbError);

        try {
            $this->service($em)->create(self::input());
            self::fail('Era esperada uma DuplicatePurchaseException.');
        } catch (DuplicatePurchaseException $e) {
            self::assertSame('PED-1', $e->referenceCode);
            self::assertSame($dbError, $e->getPrevious());
        }
    }

    /**
     * Violação de outro UNIQUE (ex.: o mesmo produto novo criado ao mesmo tempo por outra requisição).
     * Espera que o erro do banco seja repassado sem alteração (não é compra duplicada).
     */
    public function testRethrowsOtherUniqueViolations(): void
    {
        $dbError = self::uniqueViolation('duplicate key value violates unique constraint "uniq_product_external_ids_channel_external"');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($dbError);

        try {
            $this->service($em)->create(self::input());
            self::fail('Era esperada a UniqueConstraintViolationException original.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertSame($dbError, $e);
        }
    }

    /**
     * O canal de venda API não está cadastrado (migration de seed não rodou).
     * Espera LogicException com a orientação de rodar as migrations.
     */
    public function testThrowsWhenApiSalesChannelIsMissing(): void
    {
        $this->salesChannelRepository = $this->createMock(SalesChannelRepository::class);
        $this->salesChannelRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['code' => 'API'])
            ->willReturn(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Canal de venda "API" não cadastrado. Rode as migrations.');

        $this->service()->create(self::input());
    }

    // =========================================================================
    // Compra: dados e totais
    // =========================================================================

    /**
     * Compra válida com dois itens.
     * Espera que a compra receba os dados do payload, o canal API, e que os totais sejam:
     * - item: quantidade × preço unitário − desconto;
     * - compra: soma dos totais dos itens (amount) e soma dos descontos (discount).
     * Espera também que a compra seja passada ao persist().
     */
    public function testCreatesPurchaseWithItemsAndTotals(): void
    {
        $input = self::input(products: [
            new ProductInput(externalId: 'CAM-001', name: 'Camiseta', quantity: 2, unitPrice: 5000, discount: 500),
            new ProductInput(externalId: 'CAL-001', name: 'Calça', quantity: 1, unitPrice: 16010),
        ]);

        $purchase = $this->service()->create($input);

        self::assertSame('PED-1', $purchase->getReferenceCode());
        self::assertSame('Produto enviado por SEDEX', $purchase->getNotes());
        self::assertSame($this->apiChannel, $purchase->getSalesChannel());

        $items = $purchase->getItems()->toArray();
        self::assertCount(2, $items);

        // Item 1: 2 × 5000 − 500 = 9500
        self::assertSame(2, $items[0]->getQuantity());
        self::assertSame(5000, $items[0]->getUnitPrice());
        self::assertSame(500, $items[0]->getDiscount());
        self::assertSame(9500, $items[0]->getTotalPrice());
        self::assertSame($purchase, $items[0]->getPurchase());

        // Item 2: 1 × 16010 − 0 = 16010 (desconto não informado vale 0)
        self::assertSame(0, $items[1]->getDiscount());
        self::assertSame(16010, $items[1]->getTotalPrice());

        // Compra: 9500 + 16010 = 25510; desconto 500 + 0 = 500
        self::assertSame(25510, $purchase->getAmount());
        self::assertSame(500, $purchase->getDiscount());

        self::assertContains($purchase, $this->persisted);
    }

    /**
     * Data da compra enviada com fuso -03:00.
     * Espera que seja gravada em UTC, representando o mesmo instante (14:30 -03:00 = 17:30 UTC).
     */
    public function testConvertsPurchaseDateToUtc(): void
    {
        $purchase = $this->service()->create(self::input(purchasedAt: '2026-11-21T14:30:00-03:00'));

        self::assertSame('2026-11-21T17:30:00+00:00', $purchase->getPurchasedAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $purchase->getPurchasedAt()->getTimezone()->getName());
    }

    // =========================================================================
    // Cliente
    // =========================================================================

    /**
     * Pessoa física ainda não cadastrada.
     * Espera a busca pelo CPF sem máscara e a criação de um IndividualCustomer com nome, e-mail e CPF,
     * passado ao persist().
     */
    public function testCreatesNewIndividualCustomer(): void
    {
        $this->individualCustomerRepository = $this->createMock(IndividualCustomerRepository::class);
        $this->individualCustomerRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['cpf' => '52998224725'])
            ->willReturn(null);

        $this->companyCustomerRepository = $this->createMock(CompanyCustomerRepository::class);
        $this->companyCustomerRepository->expects($this->never())->method('findOneBy');

        $purchase = $this->service()->create(self::input(customer: self::individualCustomer()));

        $customer = $purchase->getCustomer();
        self::assertInstanceOf(IndividualCustomer::class, $customer);
        self::assertSame('João Silva', $customer->getFullName());
        self::assertSame('joao@email.com', $customer->getEmail());
        self::assertSame('52998224725', $customer->getCpf());
        self::assertContains($customer, $this->persisted);
    }

    /**
     * Pessoa física já cadastrada com o mesmo CPF.
     * Espera que o cliente existente seja reaproveitado (sem persist novo) e tenha nome e e-mail atualizados.
     */
    public function testReusesAndUpdatesExistingIndividualCustomer(): void
    {
        $existing = (new IndividualCustomer())->setCpf('52998224725')->setFullName('Nome Antigo')->setEmail('antigo@email.com');
        $this->individualCustomerRepository = $this->createStub(IndividualCustomerRepository::class);
        $this->individualCustomerRepository->method('findOneBy')->willReturn($existing);

        $purchase = $this->service()->create(self::input(customer: self::individualCustomer()));

        self::assertSame($existing, $purchase->getCustomer());
        self::assertSame('João Silva', $existing->getFullName());
        self::assertSame('joao@email.com', $existing->getEmail());
        self::assertNotContains($existing, $this->persisted);
    }

    /**
     * Pessoa jurídica ainda não cadastrada, com CNPJ alfanumérico em minúsculas e com máscara.
     * Espera a busca pelo CNPJ normalizado (sem máscara, maiúsculo) e a criação de um CompanyCustomer
     * com razão social, nome fantasia, e-mail e CNPJ, passado ao persist().
     */
    public function testCreatesNewCompanyCustomer(): void
    {
        $this->companyCustomerRepository = $this->createMock(CompanyCustomerRepository::class);
        $this->companyCustomerRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['cnpj' => '12ABC34501DE35'])
            ->willReturn(null);

        $this->individualCustomerRepository = $this->createMock(IndividualCustomerRepository::class);
        $this->individualCustomerRepository->expects($this->never())->method('findOneBy');

        $purchase = $this->service()->create(self::input(customer: self::companyCustomer(cnpj: '12.abc.345/01de-35')));

        $customer = $purchase->getCustomer();
        self::assertInstanceOf(CompanyCustomer::class, $customer);
        self::assertSame('Loja Exemplo LTDA', $customer->getLegalName());
        self::assertSame('Loja Exemplo', $customer->getTradeName());
        self::assertSame('contato@loja.com', $customer->getEmail());
        self::assertSame('12ABC34501DE35', $customer->getCnpj());
        self::assertContains($customer, $this->persisted);
    }

    /**
     * Pessoa jurídica já cadastrada com o mesmo CNPJ.
     * Espera que a empresa existente seja reaproveitada (sem persist novo) e tenha razão social,
     * nome fantasia e e-mail atualizados.
     */
    public function testReusesAndUpdatesExistingCompanyCustomer(): void
    {
        $existing = (new CompanyCustomer())->setCnpj('11222333000181')->setLegalName('Antiga LTDA')->setTradeName('Antiga')->setEmail('antigo@loja.com');
        $this->companyCustomerRepository = $this->createMock(CompanyCustomerRepository::class);
        $this->companyCustomerRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['cnpj' => '11222333000181'])
            ->willReturn($existing);

        $purchase = $this->service()->create(self::input(customer: self::companyCustomer(cnpj: '11.222.333/0001-81')));

        self::assertSame($existing, $purchase->getCustomer());
        self::assertSame('Loja Exemplo LTDA', $existing->getLegalName());
        self::assertSame('Loja Exemplo', $existing->getTradeName());
        self::assertSame('contato@loja.com', $existing->getEmail());
        self::assertNotContains($existing, $this->persisted);
    }

    // =========================================================================
    // Produtos
    // =========================================================================

    /**
     * Produto já cadastrado no canal API com o external_id recebido.
     * Espera que o produto existente seja usado no item, sem criar produto novo.
     */
    public function testReusesExistingProduct(): void
    {
        $existing = (new Product())->setName('Camiseta (cadastro)');
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->productRepository->expects($this->once())
            ->method('findOneBySalesChannelAndExternalId')
            ->with('API', 'CAM-001')
            ->willReturn($existing);

        $purchase = $this->service()->create(self::input());

        self::assertSame($existing, $purchase->getItems()->first()->getProduct());
        self::assertSame('Camiseta (cadastro)', $existing->getName(), 'O nome do produto cadastrado não deve ser alterado.');
        self::assertNotContains($existing, $this->persisted);
    }

    /**
     * Produto ainda não cadastrado.
     * Espera a criação do produto com o nome recebido e do vínculo (ProductExternalId) com o canal API
     * e o external_id, passado ao persist().
     */
    public function testCreatesNewProductLinkedToSalesChannel(): void
    {
        // O stub padrão do setUp já devolve "produto não encontrado"
        $purchase = $this->service()->create(self::input());

        $product = $purchase->getItems()->first()->getProduct();
        self::assertSame('Camiseta', $product->getName());
        self::assertContains($product, $this->persisted);

        $externalIds = $product->getExternalIds()->toArray();
        self::assertCount(1, $externalIds);
        self::assertInstanceOf(ProductExternalId::class, $externalIds[0]);
        self::assertSame('CAM-001', $externalIds[0]->getExternalId());
        self::assertSame($this->apiChannel, $externalIds[0]->getSalesChannel());
        self::assertSame($product, $externalIds[0]->getProduct());
    }

    /**
     * O mesmo produto novo aparece duas vezes na compra.
     * Espera que o produto seja buscado e criado UMA vez só, e que os dois itens usem o mesmo produto.
     * (Sem isso, o mesmo external_id seria criado duas vezes e o UNIQUE do banco recusaria a compra.)
     */
    public function testRepeatedProductInSamePurchaseIsResolvedOnlyOnce(): void
    {
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->productRepository->expects($this->once())
            ->method('findOneBySalesChannelAndExternalId')
            ->willReturn(null);

        $purchase = $this->service()->create(self::input(products: [
            new ProductInput(externalId: '777', name: 'Tênis', quantity: 1, unitPrice: 3000),
            new ProductInput(externalId: '777', name: 'Tênis', quantity: 2, unitPrice: 3000),
        ]));

        $items = $purchase->getItems()->toArray();
        self::assertCount(2, $items, 'Cada entrada da lista continua sendo um item.');
        self::assertSame($items[0]->getProduct(), $items[1]->getProduct());

        $persistedProducts = array_filter($this->persisted, static fn (object $entity): bool => $entity instanceof Product);
        self::assertCount(1, $persistedProducts);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cria o service com as dependências atuais (stubs do setUp ou mocks trocados pelo teste).
     */
    private function service(?EntityManagerInterface $em = null): PurchaseService
    {
        return new PurchaseService(
            $em ?? $this->entityManager(),
            $this->purchaseRepository,
            $this->productRepository,
            $this->salesChannelRepository,
        );
    }

    /**
     * EntityManager falso: executa a transação, guarda o que seria salvo e devolve
     * o repositório do tipo de cliente pedido em getRepository().
     */
    private function entityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $em->method('getRepository')->willReturnMap([
            [IndividualCustomer::class, $this->individualCustomerRepository],
            [CompanyCustomer::class, $this->companyCustomerRepository],
        ]);

        // A transação só executa a função, como o Doctrine faria antes do flush/commit
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($em));

        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return $em;
    }

    /**
     * Monta um PurchaseInput válido; cada teste troca só o que precisa.
     *
     * @param list<ProductInput>|null $products
     */
    private static function input(
        ?CustomerInput $customer = null,
        ?array $products = null,
        string $purchasedAt = '2026-11-21T14:30:00-03:00',
    ): PurchaseInput {
        return new PurchaseInput(
            referenceCode: 'PED-1',
            purchasedAt: new \DateTimeImmutable($purchasedAt),
            notes: 'Produto enviado por SEDEX',
            customer: $customer ?? self::individualCustomer(),
            products: $products ?? [
                new ProductInput(externalId: 'CAM-001', name: 'Camiseta', quantity: 2, unitPrice: 5000, discount: 500),
            ],
        );
    }

    private static function individualCustomer(): CustomerInput
    {
        return new CustomerInput(name: 'João Silva', email: 'joao@email.com', cpf: '529.982.247-25');
    }

    private static function companyCustomer(string $cnpj): CustomerInput
    {
        return new CustomerInput(name: 'Loja Exemplo LTDA', email: 'contato@loja.com', cnpj: $cnpj, tradeName: 'Loja Exemplo');
    }

    /**
     * Cria o erro que o Doctrine lança quando um UNIQUE do banco é violado.
     */
    private static function uniqueViolation(string $message): UniqueConstraintViolationException
    {
        $driverException = new class($message) extends AbstractException {
        };

        return new UniqueConstraintViolationException($driverException, null);
    }
}
