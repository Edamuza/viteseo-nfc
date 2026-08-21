<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a partner-supplied destination URL.
 *
 * Deliberately does NOT fetch the URL. A redirect target does not need to be
 * reachable at configuration time, and fetching it would turn this endpoint
 * into an SSRF primitive.
 */
class SafeExternalUrl implements ValidationRule
{
    /** Only these schemes may ever be emitted in a Location header. */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Please enter a destination URL.');

            return;
        }

        $value = trim($value);

        if (strlen($value) > 2048) {
            $fail('That URL is too long (maximum 2048 characters).');

            return;
        }

        // Control characters would allow header injection via the Location header.
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $fail('That URL contains invalid characters.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $fail('Please enter a full URL including http:// or https://');

            return;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $fail('Only http:// and https:// links are allowed.');

            return;
        }

        if ($parts['host'] === '' || ! preg_match('/^[a-zA-Z0-9.\-]+$/', $parts['host'])) {
            $fail('That URL does not have a valid domain name.');

            return;
        }

        // Require a dot so "http://localhost" and bare hostnames are rejected.
        if (! str_contains($parts['host'], '.')) {
            $fail('Please enter a full public domain name.');
        }
    }
}
