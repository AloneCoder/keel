<?php

declare(strict_types=1);

return [
    'hello' => 'Hello, {name}!',
    'cart' => [
        'items' => '{count} item|{count} items',
        'empty' => 'Your cart is empty',
    ],
    'only_en' => 'English only',
    'icu' => '{count, plural, =0 {no apples} one {# apple} other {# apples}}',
    'nested.dotted' => 'flat key wins',
    'bad' => ['not' => ['a' => ['leaf' => []]]],
];
