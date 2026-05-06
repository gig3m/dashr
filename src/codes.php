<?php
declare(strict_types=1);

/**
 * Strip non-digits and require exactly 6 digits.
 * Returns the 6-digit string, or null if invalid.
 */
function normalize_code(string $input): ?string
{
    $digits = preg_replace('/\D+/', '', $input);
    return strlen($digits) === 6 ? $digits : null;
}

/**
 * Format a stored 6-digit code for display: 'XXX-XXX'.
 */
function format_code(string $code): string
{
    return substr($code, 0, 3) . '-' . substr($code, 3, 3);
}

/**
 * Generate a new 6-digit code. $exists($code) returns true if the code is taken.
 * Throws RuntimeException after 10 unsuccessful attempts.
 */
function generate_code(callable $exists): string
{
    for ($i = 0; $i < 10; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$exists($code)) {
            return $code;
        }
    }
    throw new RuntimeException('could not generate unique code after 10 attempts');
}
