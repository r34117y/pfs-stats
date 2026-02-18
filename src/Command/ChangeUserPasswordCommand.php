<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:change-password',
    description: 'Changes password for an existing authentication user.',
)]
class ChangeUserPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'New plain password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');

        if ('' === $email) {
            $io->error('Email cannot be empty.');

            return Command::INVALID;
        }

        if (strlen($plainPassword) < 8) {
            $io->error('Password must have at least 8 characters.');

            return Command::INVALID;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('User with email "%s" was not found.', $email));

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();

        $io->success(sprintf('Password changed for user "%s".', $email));

        return Command::SUCCESS;
    }
}
