<?php

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\Password;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Password::class)]
class PasswordTest extends TestCase
{
    // ── validate (static) ────────────────────────────────────────────────────

    public function testValidateReturnsNoErrorsForValidPassword(): void
    {
        $errors = Password::validate('SecurePass1!');
        $this->assertEmpty($errors);
    }

    public function testValidateReturnsErrorWhenTooShort(): void
    {
        $errors = Password::validate('Short1!');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('12 Zeichen', $errorMessages);
    }

    public function testValidateReturnsErrorWhenNoUppercase(): void
    {
        $errors = Password::validate('nouppercase1!xxx');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Großbuchstaben', $errorMessages);
    }

    public function testValidateReturnsErrorWhenNoLowercase(): void
    {
        $errors = Password::validate('NOLOWERCASE1!XXX');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Kleinbuchstaben', $errorMessages);
    }

    public function testValidateReturnsErrorWhenNoDigit(): void
    {
        $errors = Password::validate('NoDigitPassword!');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Ziffer', $errorMessages);
    }

    public function testValidateReturnsErrorWhenNoSpecialChar(): void
    {
        $errors = Password::validate('NoSpecialChar12');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Sonderzeichen', $errorMessages);
    }

    public function testValidateAcceptsPasswordAtExactMinLength(): void
    {
        // exactly 12 chars: uppercase, lowercase, digit, special
        $errors = Password::validate('SecureP@ss1!');
        $this->assertEmpty($errors);
    }

    public function testValidateRejectsPasswordWith11Chars(): void
    {
        $errors = Password::validate('SecureP@ss1');
        $lengthErrors = array_filter($errors, fn($e) => str_contains($e, '12 Zeichen'));
        $this->assertNotEmpty($lengthErrors);
    }

    public function testValidateCanReturnMultipleErrors(): void
    {
        // short, no uppercase, no digit, no special char
        $errors = Password::validate('abc');
        $this->assertGreaterThan(1, count($errors));
    }

    // ── constructor ──────────────────────────────────────────────────────────

    public function testConstructorAcceptsValidPassword(): void
    {
        $password = new Password('SecurePass1!');
        $this->assertSame('SecurePass1!', $password->getValue());
    }

    public function testConstructorThrowsForInvalidPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Password('weak');
    }

    public function testConstructorThrowsForEmptyPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Password('');
    }

    public function testConstructorThrowsForPasswordMissingSpecialChar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Password('NoSpecialChar12');
    }

    // ── getValue ─────────────────────────────────────────────────────────────

    public function testGetValueReturnsOriginalPlainTextPassword(): void
    {
        $plain = 'MyStr0ng!Pass';
        $password = new Password($plain);
        $this->assertSame($plain, $password->getValue());
    }

    // ── data-provider tests ──────────────────────────────────────────────────

    #[DataProvider('validPasswordProvider')]
    public function testValidateAcceptsValidPasswords(string $plain): void
    {
        $errors = Password::validate($plain);
        $this->assertEmpty($errors);
    }

    public static function validPasswordProvider(): array
    {
        return [
            'basic valid'           => ['SecurePass1!'],
            'exact min length'      => ['SecureP@ss1!'],
            'long password'         => ['ThisIsAVeryLongP@ssw0rd!'],
            'multiple specials'     => ['P@$$w0rd_Secure!'],
            'numbers and symbols'   => ['Abc123!@#456DEF'],
        ];
    }

    #[DataProvider('invalidPasswordProvider')]
    public function testValidateRejectsInvalidPasswords(string $plain): void
    {
        $errors = Password::validate($plain);
        $this->assertNotEmpty($errors);
    }

    public static function invalidPasswordProvider(): array
    {
        return [
            'too short'             => ['Sh0rt!'],
            'no uppercase'          => ['nouppercase1!xxx'],
            'no lowercase'          => ['NOLOWERCASE1!'],
            'no digit'              => ['NoDigitPassword!'],
            'no special char'       => ['NoSpecialChar12'],
            'empty string'          => [''],
            'all lowercase'         => ['alllowercase!!1'],
        ];
    }
}
