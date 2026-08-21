<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number, NOT a URL.
 *
 * The value ends up inside a tel: href and a vCard TEL line, so it is restricted
 * to an unambiguous character set. That makes it impossible to smuggle a
 * different URI scheme, a quote that breaks out of the href, or a CRLF that
 * injects a vCard line.
 */
class SafePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid phone number.');

            return;
        }

        $value = trim($value);

        if ($value === '') {
            $fail('Enter a phone number.');

            return;
        }

        if (strlen($value) > 32) {
            $fail('That phone number is too long.');

            return;
        }

        // Digits plus the punctuation real numbers use. A leading + is allowed once.
        if (! preg_match('/^\+?[0-9 ()\-.]+$/', $value)) {
            $fail('Phone numbers may only contain digits and + ( ) - . and spaces.');

            return;
        }

        if (substr_count($value, '+') > 1 || (str_contains($value, '+') && ! str_starts_with($value, '+'))) {
            $fail('A + may only appear once, at the start.');

            return;
        }

        if (strlen(preg_replace('/\D/', '', $value) ?? '') < 6) {
            $fail('That phone number is too short.');
        }
    }

    /** Digits (and a leading +) only - what belongs in a tel: href. */
    public static function toDialable(string $value): string
    {
        $plus   = str_starts_with(trim($value), '+') ? '+' : '';
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $plus.$digits;
    }
}
