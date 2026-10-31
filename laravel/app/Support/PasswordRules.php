<?php

namespace App\Support;

use Closure;

/** Password policy: at least 8 characters, not all digits, not common, not close to the username. */
final class PasswordRules
{
    private const COMMON = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwerty123', 'qwertyuiop',
        'iloveyou', 'admin123', 'admin@123', 'welcome1', 'welcome123', 'abc12345', 'letmein1', 'india123',
        'passw0rd', 'p@ssw0rd', '11111111', '00000000', 'asdfghjkl', 'baseball', 'football', 'sunshine',
    ];

    public static function rules(?string $username = null): array
    {
        return ['required', 'string', 'min:8', 'max:128', 'confirmed', function (string $attr, mixed $value, Closure $fail) use ($username) {
            $value = (string) $value;
            if (ctype_digit($value)) {
                $fail('This password is entirely numeric.');
            } elseif (in_array(mb_strtolower($value), self::COMMON, true)) {
                $fail('This password is too common.');
            } elseif ($username && mb_strlen($username) >= 3 && str_contains(mb_strtolower($value), mb_strtolower($username))) {
                $fail('The password is too similar to the username.');
            }
        }];
    }
}
