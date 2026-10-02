<?php

namespace App\Support;

/** Django-equivalent password rules: min length 8, not common, not all-numeric, not similar to the email. */
class Passwords
{
    private const COMMON = ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwertyui', 'qwerty123', 'qwertyuiop', 'iloveyou', 'admin123', 'letmein1', 'welcome1', 'abc12345', 'monkey123', 'dragon123', 'football', 'baseball', 'sunshine', 'princess', 'trustno1', 'passw0rd', '11111111', '00000000', '87654321', 'changeme', 'administrator'];

    /** @return list<string> error messages */
    public static function errors(string $password, ?string $email = null): array
    {
        $errors = [];
        if (mb_strlen($password) < 8) {
            $errors[] = 'This password is too short. It must contain at least 8 characters.';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            $errors[] = 'This password is too common.';
        }
        if ($password !== '' && ctype_digit($password)) {
            $errors[] = 'This password is entirely numeric.';
        }
        if ($email) {
            $local = mb_strtolower(explode('@', $email)[0]);
            $pw = mb_strtolower($password);
            if ($local !== '' && mb_strlen($local) >= 4 && (str_contains($pw, $local) || $local === $pw)) {
                $errors[] = 'The password is too similar to the email address.';
            }
        }

        return $errors;
    }
}
