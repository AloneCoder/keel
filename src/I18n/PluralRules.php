<?php

declare(strict_types=1);

namespace Keel\I18n;

/**
 * Which "|"-separated form of a message a count selects, per language (CLDR cardinal rules
 * for the common cases). Messages list the forms in CLDR order: one|other, or
 * one|few|many for Russian and friends, one|few|other for Czech, and so on.
 *
 * @internal
 */
final class PluralRules
{
    /**
     * @return int<0, max> the index of the form
     */
    public static function index(string $locale, int|float $count): int
    {
        $language = strtolower(explode('-', $locale)[0]);
        $n = abs($count);
        $i = (int) $n;
        $isInt = (float) $n === (float) $i;

        return match ($language) {
            'ja', 'zh', 'ko', 'vi', 'th', 'id', 'ms', 'tr', 'ka', 'kk' => 0,
            'fr', 'pt', 'hy' => $n < 2 ? 0 : 1,
            'ru', 'uk', 'be', 'sr', 'hr', 'bs' => !$isInt ? 1 : ($i % 10 === 1 && $i % 100 !== 11 ? 0 : ($i % 10 >= 2 && $i % 10 <= 4 && ($i % 100 < 12 || $i % 100 > 14) ? 1 : 2)),
            'pl' => !$isInt ? 2 : ($i === 1 ? 0 : ($i % 10 >= 2 && $i % 10 <= 4 && ($i % 100 < 12 || $i % 100 > 14) ? 1 : 2)),
            'cs', 'sk' => !$isInt ? 2 : ($i === 1 ? 0 : ($i >= 2 && $i <= 4 ? 1 : 2)),
            'lt' => !$isInt ? 2 : ($i % 10 === 1 && ($i % 100 < 11 || $i % 100 > 19) ? 0 : ($i % 10 >= 2 && ($i % 100 < 11 || $i % 100 > 19) ? 1 : 2)),
            'lv' => $i % 10 === 0 || ($i % 100 >= 11 && $i % 100 <= 19) ? 0 : ($i % 10 === 1 ? 1 : 2),
            'ro' => $i === 1 && $isInt ? 0 : ($i === 0 || ($i % 100 >= 2 && $i % 100 <= 19) ? 1 : 2),
            'ar' => $i === 0 ? 0 : ($i === 1 ? 1 : ($i === 2 ? 2 : ($i % 100 >= 3 && $i % 100 <= 10 ? 3 : ($i % 100 >= 11 ? 4 : 5)))),
            default => $i === 1 && $isInt ? 0 : 1,
        };
    }
}
