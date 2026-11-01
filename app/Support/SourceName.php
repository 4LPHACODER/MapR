<?php

namespace App\Support;

final class SourceName
{
    /** @var list<string> */
    private const ERROR_TOKENS = ['#NAME?', '#N/A', '#REF!', '#VALUE!', '#NULL!', '#DIV/0!', '#NUM!'];

    /**
     * @return array{value: ?string, needs_review: bool}
     */
    public static function part(mixed $raw): array
    {
        $text = trim((string) ($raw ?? ''));

        if ($text === '') {
            return ['value' => null, 'needs_review' => false];
        }

        if (in_array(strtoupper($text), self::ERROR_TOKENS, true)) {
            return ['value' => null, 'needs_review' => true];
        }

        $needsReview = str_contains($text, "\u{FFFD}");
        $cleaned = trim(str_replace("\u{FFFD}", '', $text));

        if ($cleaned === '') {
            return ['value' => null, 'needs_review' => true];
        }

        return ['value' => $cleaned, 'needs_review' => $needsReview];
    }

    public static function display(mixed $first, mixed $middle, mixed $last): string
    {
        $parts = [self::part($first), self::part($middle), self::part($last)];
        $visible = array_values(array_filter(array_map(fn ($part) => $part['value'], $parts)));
        $review = collect($parts)->contains(fn ($part) => $part['needs_review']);

        if ($visible === []) {
            return $review ? 'Source value requires review' : '';
        }

        $name = implode(' ', $visible);

        return $review ? $name.' (source value requires review)' : $name;
    }

    public static function plain(mixed $first, mixed $middle, mixed $last): string
    {
        $parts = [self::part($first), self::part($middle), self::part($last)];
        $visible = array_values(array_filter(array_map(fn ($part) => $part['value'], $parts)));

        return $visible === [] ? '' : implode(' ', $visible);
    }

    public static function needsReview(mixed $first, mixed $middle, mixed $last): bool
    {
        $parts = [self::part($first), self::part($middle), self::part($last)];

        return collect($parts)->contains(fn ($part) => $part['needs_review']);
    }
}
