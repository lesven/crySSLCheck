<?php

namespace App\ValueObject;

class Password
{
    private readonly string $value;

    /**
     * @throws \InvalidArgumentException if the password does not meet the requirements
     */
    public function __construct(string $value)
    {
        $errors = self::validate($value);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Validates a plain-text password against the password policy.
     *
     * @return string[] List of error messages; empty if the password is valid.
     */
    public static function validate(string $value): array
    {
        $errors = [];

        if (strlen($value) < 12) {
            $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $errors[] = 'Das Passwort muss mindestens einen Großbuchstaben enthalten.';
        }

        if (!preg_match('/[a-z]/', $value)) {
            $errors[] = 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.';
        }

        if (!preg_match('/[0-9]/', $value)) {
            $errors[] = 'Das Passwort muss mindestens eine Ziffer enthalten.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $errors[] = 'Das Passwort muss mindestens ein Sonderzeichen enthalten.';
        }

        return $errors;
    }
}
