<?php

namespace App\Security;

class PasswordChangeValidator
{
    /**
     * @return list<string>
     */
    public function validate(string $password, string $passwordConfirmation): array
    {
        $errors = [];

        if (mb_strlen($password) < 8) {
            $errors[] = 'Haslo musi miec co najmniej 8 znakow.';
        }

        if ($password !== $passwordConfirmation) {
            $errors[] = 'Haslo i potwierdzenie hasla musza byc takie same.';
        }

        return $errors;
    }
}
