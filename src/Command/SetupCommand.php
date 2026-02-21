<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:setup',
    description: 'Initialisiert die Datenbank und erstellt den Standard-Admin-Benutzer',
)]
class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('TLS Monitor Setup');

        // Create default admin if no users exist
        if ($this->userRepository->count([]) === 0) {
            $admin = new User();
            $admin->setUsername('admin');
            $admin->setRole('admin');
            $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin'));
            $this->entityManager->persist($admin);
            $this->entityManager->flush();

            $io->success('Standard-Admin-Benutzer erstellt (admin / admin).');
            $io->warning('Bitte ändern Sie das Standard-Passwort!');
        } else {
            $io->note('Benutzer bereits vorhanden – überspringe Standard-Admin-Erstellung.');
        }

        return Command::SUCCESS;
    }
}
