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
    name: 'app:user:create',
    description: 'Creates a new user for Symfony authentication.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password')
            ->addOption('year-of-birth', null, InputOption::VALUE_OPTIONAL, 'Year of birth')
            ->addOption('photo', null, InputOption::VALUE_OPTIONAL, 'Photo path')
            ->addOption('player-id', null, InputOption::VALUE_OPTIONAL, 'Player ID from external DB')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Role(s), can be repeated', ['ROLE_USER']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');

        if ($email === '') {
            $io->error('Email cannot be empty.');

            return Command::INVALID;
        }

        if (strlen($plainPassword) < 8) {
            $io->error('Password must have at least 8 characters.');

            return Command::INVALID;
        }

        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            $io->error(sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($this->normalizeRoles($input->getOption('role')));
        $user->setYearOfBirth($this->toNullableInt($input->getOption('year-of-birth')));
        $user->setPhoto($this->toNullableString($input->getOption('photo')));
        $user->setPlayerId($this->toNullableInt($input->getOption('player-id')));

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" created (id=%d).', $email, $user->getId()));

        return Command::SUCCESS;
    }

    /**
     * @param mixed $value
     */
    private function toNullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function toNullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim((string) $value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @param mixed $rolesOption
     *
     * @return list<string>
     */
    private function normalizeRoles(mixed $rolesOption): array
    {
        $roles = is_array($rolesOption) ? $rolesOption : ['ROLE_USER'];
        $normalized = [];

        foreach ($roles as $role) {
            $candidate = strtoupper(trim((string) $role));
            if ('' !== $candidate) {
                $normalized[] = $candidate;
            }
        }

        if ([] === $normalized) {
            $normalized[] = 'ROLE_USER';
        }

        return array_values(array_unique($normalized));
    }
}
