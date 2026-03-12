<?php

namespace App\Tests\Security;

use App\Security\PasswordChangeValidator;
use PHPUnit\Framework\TestCase;

class PasswordChangeValidatorTest extends TestCase
{
    public function testReturnsErrorForTooShortPassword(): void
    {
        $validator = new PasswordChangeValidator();

        self::assertSame(
            ['Haslo musi miec co najmniej 8 znakow.'],
            $validator->validate('short', 'short'),
        );
    }

    public function testReturnsErrorWhenConfirmationDoesNotMatch(): void
    {
        $validator = new PasswordChangeValidator();

        self::assertSame(
            ['Haslo i potwierdzenie hasla musza byc takie same.'],
            $validator->validate('password123', 'password124'),
        );
    }

    public function testAcceptsValidPasswordChange(): void
    {
        $validator = new PasswordChangeValidator();

        self::assertSame([], $validator->validate('password123', 'password123'));
    }
}
