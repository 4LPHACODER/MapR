<?php

namespace App\Support;

final class DamageClassColor
{
    public static function hex(?string $className): string
    {
        $name = strtolower(trim((string) $className));

        if ($name === '') {
            return '#64748b';
        }

        if (str_contains($name, 'total')) {
            return '#dc2626';
        }

        if (str_contains($name, 'partial')) {
            return '#eab308';
        }

        if (str_contains($name, 'not affected') || str_contains($name, 'unaffected') || $name === 'none') {
            return '#2563eb';
        }

        return '#64748b';
    }
}
