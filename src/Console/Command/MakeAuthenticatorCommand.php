<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates either a PrincipalResolver (the application side of the built-in bearer and API-key
 * authenticators) or a complete Authenticator for a scheme of your own.
 */
#[AsCommand(name: 'keel:make:authenticator', description: 'Generate a PrincipalResolver or a custom Authenticator')]
final class MakeAuthenticatorCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Class name, e.g. ApiTokens or Session')
            ->addOption('scheme', 's', InputOption::VALUE_NONE, 'Generate a full Authenticator for a custom scheme instead of a PrincipalResolver');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Sample');
        [$namespace] = $this->rootNamespace();

        if ($input->getOption('scheme') === true) {
            $class = str_ends_with($name, 'Authenticator') ? $name : $name . 'Authenticator';
            $scheme = strtolower(substr($class, 0, -13));

            return $this->write($input, $output, 'Auth', $class, self::authenticator($namespace, $class, $scheme));
        }

        $class = str_ends_with($name, 'Resolver') || str_ends_with($name, 'Tokens') ? $name : $name . 'Resolver';

        return $this->write($input, $output, 'Auth', $class, self::resolver($namespace, $class));
    }

    private static function resolver(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Auth;

            use Keel\\Auth\\Identity;
            use Keel\\Auth\\Principal;
            use Keel\\Auth\\PrincipalResolver;
            use Keel\\Container\\Attribute\\Alias;

            /**
             * Looks up the principal behind a bearer token or API key. Used by BearerTokenAuthenticator
             * and ApiKeyAuthenticator listed in config/auth.php (AuthConfig).
             */
            #[Alias(PrincipalResolver::class)]
            final class {$class} implements PrincipalResolver
            {
                public function resolve(string \$credential): ?Principal
                {
                    // Look the credential up (hash it first when it is stored hashed) and return null when
                    // it is unknown, revoked or expired.
                    return null;
                }
            }

            PHP;
    }

    private static function authenticator(string $namespace, string $class, string $scheme): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Auth;

            use Keel\\Auth\\Authenticator;
            use Keel\\Auth\\Principal;
            use Keel\\Auth\\SecurityScheme;
            use Keel\\Http\\Exception\\UnauthorizedException;
            use Psr\\Http\\Message\\ServerRequestInterface;

            /**
             * Add it to AuthConfig::\$authenticators in config/auth.php. Return null when the request
             * carries no credentials of this scheme; throw UnauthorizedException when they are invalid.
             */
            final class {$class} implements Authenticator
            {
                public function authenticate(ServerRequestInterface \$request): ?Principal
                {
                    \$credential = \$request->getHeaderLine('X-{$class}');
                    if (\$credential === '') {
                        return null;
                    }

                    throw new UnauthorizedException('Invalid credentials', ['WWW-Authenticate' => \$this->challenge()]);
                }

                public function challenge(): string
                {
                    return '{$scheme}';
                }

                public function securityScheme(): SecurityScheme
                {
                    return SecurityScheme::apiKey('{$scheme}', 'X-{$class}');
                }
            }

            PHP;
    }
}
