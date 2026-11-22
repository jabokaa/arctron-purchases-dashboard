<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\SalesChannel;
use App\Repository\ProductRepository;
use App\Repository\SalesChannelRepository;
use App\Service\ProductImageStorageInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeder de produtos de teste: artigos de futebol (chuteiras, bolas, luvas, camisas da Seleção Brasileira...).
 *
 * Cada produto é cadastrado com:
 * - código interno (SKU), nome e imagem;
 * - o mesmo código no canal de venda API, para que uma compra pelo POST /api/purchases com
 *   "external_id": "CHU-001" use o produto cadastrado (em vez de criar outro).
 * - a imagem, copiada de src/DataFixtures/images/products/<código interno>.jpg (guardada no repositório) para a
 *   pasta de uploads (public/uploads/products), como se tivesse sido enviada pela tela; no banco fica só o caminho
 *   local (ex.: /uploads/products/ab12....jpg).
 * Produto que já existe (mesmo código interno) não é alterado, exceto a imagem: se ela ainda não for um arquivo
 * local (ex.: produto criado antes do upload), é copiada agora. Pode rodar quantas vezes quiser.
 *
 * Rodar SEMPRE com --append (sem ele o Doctrine apaga TODAS as tabelas antes):
 *   php bin/console doctrine:fixtures:load --append --group=products
 *
 * Imagens: fotos com licença livre do Wikimedia Commons, salvas no repositório (miniaturas de 960 px).
 * As licenças CC BY e CC BY-SA pedem o crédito ao autor; ele está no comentário de cada item
 * (autor, licença e página da imagem). Não são fotos de produtos oficiais à venda.
 */
final class ProductFixtures extends Fixture implements FixtureGroupInterface
{
    private const IMAGES_DIR = __DIR__.'/images/products';

    /**
     * [código interno, nome]. A imagem de cada um é o arquivo images/products/<código interno>.jpg
     *
     * @var list<array{string, string}>
     */
    private const PRODUCTS = [
        // Autor: Methem (Mikko J. Putkonen) · CC BY 4.0 · https://commons.wikimedia.org/wiki/File:Gola_Speedster_soccer_shoes_20200501_001.jpg
        ['CHU-001', 'Chuteira de Campo Speedster'],

        // Autor: Andy Hay · CC BY 2.0 · https://commons.wikimedia.org/wiki/File:Football_boots_(3293104040).jpg
        ['CHU-002', 'Chuteira Clássica de Couro'],

        // Autor: Christopher Bruno · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Football_Pallo_valmiina-cropped.jpg
        ['BOL-001', 'Bola de Futebol de Campo'],

        // Autor: Кирилл Венедиктов · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Adidas_Telstar_18.jpg
        ['BOL-002', 'Bola Telstar 18 – Copa do Mundo 2018'],

        // Autor: Zac allan · Domínio público · https://commons.wikimedia.org/wiki/File:Adidas_Telstar_Mexico_1970_Official_ball.jpg
        ['BOL-003', 'Bola Retrô Telstar 1970 – Tri do Brasil'],

        // Autor: Santeri Viinamäki · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Football_goalkeeper_gloves_Select_34.jpg
        ['LUV-001', 'Luvas de Goleiro'],

        // Autor: Rjcastillo · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Camiseta_Brasil_1962_A74275920241123.jpg
        ['CAM-001', 'Camisa Retrô Seleção Brasileira 1962'],

        // Autor: Rjcastillo · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Camiseta_Brasil_1966_A74275720241123.jpg
        ['CAM-002', 'Camisa Retrô Seleção Brasileira 1966'],

        // Autor: BrokenSphere · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Nike_Brazil_national_football_team_home_jersey.JPG
        ['CAM-003', 'Camisa Seleção Brasileira – Uniforme Titular'],

        // Autor: Laslovarga · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:1992_Referee_whistles_for_football_Laslovarga_(14).jpg
        ['ARB-001', 'Apito de Árbitro'],

        // Domínio público · https://commons.wikimedia.org/wiki/File:Association_football_goal_net.jpg
        ['RED-001', 'Rede de Gol para Campo'],
    ];

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly SalesChannelRepository $salesChannelRepository,
        private readonly ProductImageStorageInterface $imageStorage,
    ) {
    }

    public static function getGroups(): array
    {
        return ['products'];
    }

    public function load(ObjectManager $manager): void
    {
        $apiChannel = $this->salesChannelRepository->findOneBy(['code' => SalesChannel::API])
            ?? throw new \LogicException('Canal de venda "API" não cadastrado. Rode as migrations antes do seeder.');

        foreach (self::PRODUCTS as [$internalCode, $name]) {
            $product = $this->productRepository->findOneBy(['internalCode' => $internalCode]);

            if (null === $product) {
                $product = (new Product())
                    ->setInternalCode($internalCode)
                    ->setName($name);

                $product->addExternalId(
                    (new ProductExternalId())->setSalesChannel($apiChannel)->setExternalId($internalCode),
                );

                $manager->persist($product);
            }

            if (!$this->imageStorage->isLocal($product->getImageUrl())) {
                $product->setImageUrl($this->storeImage($internalCode));
            }
        }

        $manager->flush();
    }

    /**
     * Copia a imagem do produto (guardada no repositório) para a pasta de uploads, como se tivesse sido enviada pela tela.
     *
     * @return string caminho público do arquivo gravado
     */
    private function storeImage(string $internalCode): string
    {
        $file = self::IMAGES_DIR.'/'.$internalCode.'.jpg';
        $contents = @file_get_contents($file);

        if (false === $contents) {
            throw new \LogicException(\sprintf('Imagem do produto %s não encontrada em %s.', $internalCode, $file));
        }

        return $this->imageStorage->storeContents($contents);
    }

    /**
     * Códigos internos dos produtos do seeder (usado nos testes).
     *
     * @return list<string>
     */
    public static function internalCodes(): array
    {
        return array_column(self::PRODUCTS, 0);
    }
}
