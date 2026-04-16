<?php

namespace App\Support;

class UpEmail
{
    public static function allowedDomains(): array
    {
        return array_values(array_unique(array_map(
            fn(string $domain) => ltrim(strtolower($domain), '@'),
            config('auth.up_email_domains', ['up.edu.ph', 'uplb.edu.ph'])
        )));
    }

    public static function isAllowed(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)));
        $domain = $parts[1] ?? null;

        return $domain !== null && in_array($domain, self::allowedDomains(), true);
    }

    public static function validationMessage(): string
    {
        $domains = implode(', ', array_map(fn(string $domain) => '@' . $domain, self::allowedDomains()));

        return "Please use a valid UP Mail address ({$domains}).";
    }
}
