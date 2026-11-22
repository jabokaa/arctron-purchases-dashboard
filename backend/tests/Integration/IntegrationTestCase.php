<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base dos testes de integração: banco de dados real, sem mocks.
 *
 * - Na primeira vez que um teste de integração roda, o banco de teste é criado do zero:
 *   apaga o banco anterior, cria um novo e roda TODAS as migrations (tabelas + seed do canal API).
 *   O banco é o "app_test" (o Doctrine acrescenta o sufixo "_test" no ambiente de teste,
 *   ver config/packages/doctrine.yaml), separado do banco "app" de desenvolvimento.
 * - Antes de CADA teste, os dados das tabelas são apagados, para um teste não interferir no outro.
 *   Os canais de venda API e WOOCOMMERCE são recriados caso algum teste os tenha removido.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    private static bool $databaseCreated = false;

    protected KernelBrowser $client;

    /** As rotas /api exigem login: por padrão o cliente dos testes já vem autenticado (o AuthApiTest desliga isso) */
    protected bool $authenticated = true;

    protected function setUp(): void
    {
        if (!self::$databaseCreated) {
            self::createDatabase();
            self::$databaseCreated = true;
        }

        $this->client = static::createClient();
        $this->clearTables();
        if ($this->authenticated) {
            $this->authenticateClient();
        }
    }

    /**
     * Cadastra um usuário, faz o login de verdade (POST /api/auth/login) e passa a enviar o token
     * em todas as requisições do cliente, como o front faz.
     */
    private function authenticateClient(): void
    {
        $container = static::getContainer();
        $user = (new User())->setName('Usuário de Teste')->setEmail('teste@cbf.com.br');
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha-de-teste'));

        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $em->clear();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'teste@cbf.com.br', 'password' => 'senha-de-teste'], \JSON_THROW_ON_ERROR));
        $token = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['token'];

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /**
     * Apaga e recria o banco de teste e roda as migrations, como seria feito num ambiente novo.
     */
    private static function createDatabase(): void
    {
        $application = new Application(static::bootKernel());
        $application->setAutoExit(false);

        self::runConsoleCommand($application, ['command' => 'doctrine:database:drop', '--force' => true, '--if-exists' => true]);
        self::runConsoleCommand($application, ['command' => 'doctrine:database:create']);
        self::runConsoleCommand($application, ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);

        static::ensureKernelShutdown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function runConsoleCommand(Application $application, array $input): void
    {
        $output = new BufferedOutput();

        if (0 !== $application->run(new ArrayInput($input), $output)) {
            throw new \RuntimeException(\sprintf("Falha ao rodar \"%s\":\n%s", $input['command'], $output->fetch()));
        }
    }

    /**
     * Deixa o banco vazio (sem usuários e só com os canais de venda API e WOOCOMMERCE) antes de cada teste.
     * RESTART IDENTITY reinicia os ids; CASCADE apaga junto as tabelas que dependem destas.
     */
    private function clearTables(): void
    {
        $this->connection()->executeStatement(
            'TRUNCATE api_tokens, users, integration_events, purchase_items, purchases, product_external_ids, products, individual_customers, company_customers, customers RESTART IDENTITY CASCADE',
        );
        $this->connection()->executeStatement(
            "INSERT INTO sales_channels (code, name) VALUES ('API', 'API'), ('WOOCOMMERCE', 'WooCommerce') ON CONFLICT (code) DO NOTHING",
        );
    }

    /**
     * Conexão direta com o banco, usada pelos testes para conferir o que foi gravado com SQL.
     */
    protected function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * Envia o payload como JSON, exatamente como um cliente HTTP faria.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function postPurchase(array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/purchases',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Quantidade de linhas de uma tabela.
     */
    protected function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM {$table}");
    }
}
