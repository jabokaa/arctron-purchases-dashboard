<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\SalesChannel;
use App\Repository\ProductRepository;
use App\Repository\SalesChannelRepository;
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
 * Produto que já existe (mesmo código interno) não é alterado: pode rodar quantas vezes quiser.
 *
 * Rodar SEMPRE com --append (sem ele o Doctrine apaga TODAS as tabelas antes):
 *   php bin/console doctrine:fixtures:load --append --group=products
 *
 * Imagens: fotos com licença livre do Wikimedia Commons (servidas pelo próprio Wikimedia).
 * As licenças CC BY e CC BY-SA pedem o crédito ao autor; ele está no comentário de cada item
 * (autor, licença e página da imagem). Não são fotos de produtos oficiais à venda.
 */
final class ProductFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * [código interno, nome, imagem]
     *
     * @var list<array{string, string, string}>
     */
    private const PRODUCTS = [
        // Autor: Methem (Mikko J. Putkonen) · CC BY 4.0 · https://commons.wikimedia.org/wiki/File:Gola_Speedster_soccer_shoes_20200501_001.jpg
        ['CHU-001', 'Chuteira de Campo Speedster', 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6e/Gola_Speedster_soccer_shoes_20200501_001.jpg/960px-Gola_Speedster_soccer_shoes_20200501_001.jpg'],

        // Autor: Andy Hay · CC BY 2.0 · https://commons.wikimedia.org/wiki/File:Football_boots_(3293104040).jpg
        ['CHU-002', 'Chuteira Clássica de Couro', 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e1/Football_boots_%283293104040%29.jpg/960px-Football_boots_%283293104040%29.jpg'],

        // Autor: Christopher Bruno · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Football_Pallo_valmiina-cropped.jpg
        ['BOL-001', 'Bola de Futebol de Campo', 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1d/Football_Pallo_valmiina-cropped.jpg/960px-Football_Pallo_valmiina-cropped.jpg'],

        // Autor: Кирилл Венедиктов · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Adidas_Telstar_18.jpg
        ['BOL-002', 'Bola Telstar 18 – Copa do Mundo 2018', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/da/Adidas_Telstar_18.jpg/960px-Adidas_Telstar_18.jpg'],

        // Autor: Zac allan · Domínio público · https://commons.wikimedia.org/wiki/File:Adidas_Telstar_Mexico_1970_Official_ball.jpg
        ['BOL-003', 'Bola Retrô Telstar 1970 – Tri do Brasil', 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3c/Adidas_Telstar_Mexico_1970_Official_ball.jpg/960px-Adidas_Telstar_Mexico_1970_Official_ball.jpg'],

        // Autor: Santeri Viinamäki · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Football_goalkeeper_gloves_Select_34.jpg
        ['LUV-001', 'Luvas de Goleiro', 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/29/Football_goalkeeper_gloves_Select_34.jpg/960px-Football_goalkeeper_gloves_Select_34.jpg'],

        // Autor: Rjcastillo · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Camiseta_Brasil_1962_A74275920241123.jpg
        ['CAM-001', 'Camisa Retrô Seleção Brasileira 1962', 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/50/Camiseta_Brasil_1962_A74275920241123.jpg/960px-Camiseta_Brasil_1962_A74275920241123.jpg'],

        // Autor: Rjcastillo · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:Camiseta_Brasil_1966_A74275720241123.jpg
        ['CAM-002', 'Camisa Retrô Seleção Brasileira 1966', 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e2/Camiseta_Brasil_1966_A74275720241123.jpg/960px-Camiseta_Brasil_1966_A74275720241123.jpg'],

        // Autor: BrokenSphere · CC BY-SA 3.0 · https://commons.wikimedia.org/wiki/File:Nike_Brazil_national_football_team_home_jersey.JPG
        ['CAM-003', 'Camisa Seleção Brasileira – Uniforme Titular', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d7/Nike_Brazil_national_football_team_home_jersey.JPG/960px-Nike_Brazil_national_football_team_home_jersey.JPG'],

        // Autor: Laslovarga · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:1992_Referee_whistles_for_football_Laslovarga_(14).jpg
        ['ARB-001', 'Apito de Árbitro', 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/2e/1992_Referee_whistles_for_football_Laslovarga_%2814%29.jpg/960px-1992_Referee_whistles_for_football_Laslovarga_%2814%29.jpg'],

        // Domínio público · https://commons.wikimedia.org/wiki/File:Association_football_goal_net.jpg
        ['RED-001', 'Rede de Gol para Campo', 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/fd/Association_football_goal_net.jpg/960px-Association_football_goal_net.jpg'],
    ];

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly SalesChannelRepository $salesChannelRepository,
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

        foreach (self::PRODUCTS as [$internalCode, $name, $imageUrl]) {
            if (null !== $this->productRepository->findOneBy(['internalCode' => $internalCode])) {
                continue; // já cadastrado: não altera
            }

            $product = (new Product())
                ->setInternalCode($internalCode)
                ->setName($name)
                ->setImageUrl($imageUrl);

            $product->addExternalId(
                (new ProductExternalId())->setSalesChannel($apiChannel)->setExternalId($internalCode),
            );

            $manager->persist($product);
        }

        $manager->flush();
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
