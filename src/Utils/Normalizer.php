<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Utils;

class Normalizer
{
    public static function headers(string|array $headers): array
    {
        if ($headers === '' || $headers === []) {
            return [];
        }

        $items = $headers;
        if (! is_array($items)) {
            $items = array_filter(explode("\n", str_replace("\r\n", "\n", (string) $items)));
        }

        $normalized = [];

        foreach ($items as $name => $value) {
            if (is_int($name)) {
                $parts = explode(':', (string) $value, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $name = $parts[0];
                $value = $parts[1];
            }

            $headerName = ucwords(strtolower(trim((string) $name)), '-');
            $headerValue = trim((string) $value);

            if ($headerName === '' || $headerValue === '') {
                continue;
            }

            $normalized[$headerName] = $headerValue;
        }

        return $normalized;
    }

    public static function emailList(string|array $value): array
    {
        $parts = is_array($value) ? $value : [$value];
        $emails = [];

        foreach ($parts as $part) {
            $candidates = explode(',', (string) $part);
            foreach ($candidates as $candidate) {
                $email = self::extractEmail($candidate);
                if ($email === '') {
                    continue;
                }

                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public static function extractEmail(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            $raw = $matches[1];
        }

        $email = sanitize_email($raw);
        return is_email($email) ? strtolower($email) : '';
    }

    public static function subject(string $subject): string
    {
        $subject = sanitize_text_field($subject);
        if ($subject === '') {
            $subject = '(no subject)';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($subject, 0, 255);
        }

        return substr($subject, 0, 255);
    }
}
