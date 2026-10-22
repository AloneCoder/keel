<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:policy', description: 'Generate a policy deciding abilities for the Gate')]
final class MakePolicyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Resource name, e.g. Post or post');
        $this->addArgument('abilities', InputArgument::IS_ARRAY, 'Abilities to decide, e.g. post.update post.delete (default: <resource>.view/update/delete)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Sample');
        if ($name === '' || !self::isClassName($name)) {
            return self::invalid($output, 'Resource name must be a class name, e.g. Post or post');
        }
        $resource = str_ends_with($name, 'Policy') ? substr($name, 0, -6) : $name;
        if ($resource === '') {
            return self::invalid($output, 'Name the resource, e.g. Post: "Policy" alone leaves no abilities to derive');
        }
        $class = $resource . 'Policy';
        $prefix = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));

        $abilities = $input->getArgument('abilities');
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, is_string(...))) : [];
        if ($abilities === []) {
            $abilities = [$prefix . '.view', $prefix . '.update', $prefix . '.delete'];
        }
        foreach ($abilities as $ability) {
            if (preg_match('/^[A-Za-z0-9_.:-]+$/', $ability) !== 1) {
                return self::invalid($output, sprintf('Ability "%s" must be letters, digits, ".", "_", ":" or "-"', $ability));
            }
        }
        $list = implode(', ', array_map(self::literal(...), $abilities));
        $arms = implode("\n", array_map(static fn(string $a): string => '            ' . self::literal($a) . ' => $principal->hasRole(\'admin\'),', $abilities));
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Auth;

            use Keel\\Auth\\Policy;
            use Keel\\Auth\\Principal;
            use Keel\\Container\\Attribute\\Tag;

            /**
             * Decides {$list} for the Gate. Discovery registers it through the tag; \$subject is
             * whatever the caller passes (an entity, an id, or the route parameters from #[Can]).
             */
            #[Tag(Policy::TAG)]
            final class {$class} implements Policy
            {
                public function abilities(): array
                {
                    return [{$list}];
                }

                public function allows(Principal \$principal, string \$ability, mixed \$subject): bool
                {
                    return match (\$ability) {
            {$arms}
                        default => false,
                    };
                }
            }

            PHP;

        return $this->write($input, $output, 'Auth', $class, $source);
    }
}
