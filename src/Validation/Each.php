<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * Runs rules on every item of an array: #[Each(new NotBlank(), new Length(max: 30))].
 * Reports the first failing item with its key ("[2]: must not be blank"); pair with Count
 * for the number of items. A non-array value is left to the type cast.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Each implements Rule
{
    /** @var list<Rule> */
    public array $rules;

    public function __construct(Rule ...$rules)
    {
        if ($rules === []) {
            throw new \InvalidArgumentException('Each needs at least one rule');
        }
        $this->rules = array_values($rules);
    }

    public function check(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            foreach ($this->rules as $rule) {
                if ($item === null && !$rule instanceof NotBlank) {
                    continue;
                }
                $message = $rule->check($item);
                if ($message !== null) {
                    return sprintf('[%s]: %s', $key, $message);
                }
            }
        }

        return null;
    }
}
