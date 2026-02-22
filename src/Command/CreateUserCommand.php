<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Erstellt einen neuen Benutzer',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Benutzername')
            ->addArgument('password', InputArgument::REQUIRED, 'Passwort')
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse')
            ->addOption('role', 'r', InputOption::VALUE_OPTIONAL, 'Rolle (admin oder auditor)', 'auditor')
            ->setHelp(<<<'HELP'
Der <info>app:create-user</info>-Befehl erstellt einen neuen Benutzer:

    <info>php bin/console app:create-user alice secret123 alice@example.com --role=admin</info>
    <info>php bin/console app:create-user bob password123 bob@example.com</info>  (Standard-Rolle: auditor)

HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $email    = $input->getArgument('email');
        $role = $input->getOption('role');

        if (!in_array($role, ['admin', 'auditor'])) {
            $io->error("Ungültige Rolle '{$role}'. Erlaubt: admin, auditor");
            return Command::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("Ungültige E-Mail-Adresse '{$email}'.");
            return Command::FAILURE;
        }

        if ($this->userRepository->findByUsername($username)) {
            $io->error("Benutzer '{$username}' existiert bereits.");
            return Command::FAILURE;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setRole($role);
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success("Benutzer '{$username}' mit Rolle '{$role}' und E-Mail '{$email}' wurde erfolgreich erstellt.");

        return Command::SUCCESS;
    }
}
