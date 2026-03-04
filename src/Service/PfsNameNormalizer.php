<?php

namespace App\Service;

final readonly class PfsNameNormalizer
{
    public function normalizeForMatch(string $value): string
    {
        $value = strtolower(strtr($value, [
            'Ą' => 'ą',
            'Ć' => 'ć',
            'Ę' => 'ę',
            'Ł' => 'ł',
            'Ń' => 'ń',
            'Ó' => 'ó',
            'Ś' => 'ś',
            'Ź' => 'ź',
            'Ż' => 'ż',
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
        ]));

        return (string) preg_replace('/[^a-z0-9]+/u', '', $value);
    }

    public function toAlphabeticalName(string $nameShow): string
    {
        $parts = preg_split('/\s+/u', trim($nameShow)) ?: [];
        if (count($parts) < 2) {
            return $nameShow;
        }

        $lastName = array_pop($parts);

        return trim($lastName . ' ' . implode(' ', $parts));
    }
}
