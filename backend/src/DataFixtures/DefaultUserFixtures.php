<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeder do usuário padrão do painel.
 *
 * Os dados vêm do .env (DEFAULT_USER_NAME, DEFAULT_USER_EMAIL, DEFAULT_USER_PASSWORD).
 * Se o usuário já existir, nada é alterado: pode rodar quantas vezes quiser.
 *
 * Rodar SEMPRE com --append (sem ele o Doctrine apaga TODAS as tabelas antes):
 *   php bin/console doctrine:fixtures:load --append --group=users
 */
final class DefaultUserFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%env(DEFAULT_USER_NAME)%')]
        private readonly string $name,
        #[Autowire('%env(DEFAULT_USER_EMAIL)%')]
        private readonly string $email,
        #[Autowire('%env(DEFAULT_USER_PASSWORD)%')]
        private readonly string $password,
    ) {
    }

    public static function getGroups(): array
    {
        return ['users'];
    }

    public function load(ObjectManager $manager): void
    {
        if (null !== $this->userRepository->findOneByEmail($this->email)) {
            return;
        }

        $user = (new User())
            ->setName($this->name)
            ->setEmail($this->email)
            ->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $this->password));

        $manager->persist($user);
        $manager->flush();
    }
}
