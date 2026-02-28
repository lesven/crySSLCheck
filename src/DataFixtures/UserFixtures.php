<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const ADMIN_USER_REFERENCE = 'user-admin';
    public const AUDITOR_USER_REFERENCE = 'user-auditor';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setUsername('admin');
        $admin->setEmail('admin@tls-monitor.local');
        $admin->setRole('admin');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);
        $this->addReference(self::ADMIN_USER_REFERENCE, $admin);

        $auditor = new User();
        $auditor->setUsername('auditor');
        $auditor->setEmail('auditor@tls-monitor.local');
        $auditor->setRole('auditor');
        $auditor->setPassword($this->passwordHasher->hashPassword($auditor, 'auditor123'));
        $manager->persist($auditor);
        $this->addReference(self::AUDITOR_USER_REFERENCE, $auditor);

        $manager->flush();
    }
}
