<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\Routing\Attribute\Redirect;
use Keel\Routing\Redirector;
use Keel\Routing\Route;
use Keel\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:routes', description: 'List every route')]
final class RoutesCommand extends Command
{
    public function __construct(private readonly Router $router)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->router->routes() as $route) {
            $rows[] = [
                implode('|', $route->methods),
                $route->path,
                $route->name ?? '',
                self::handler($route),
                implode(', ', array_map(self::middleware(...), $route->middleware)),
                self::auth($route->option('auth'), $route->option('can')),
                self::rateLimit($route->option('rate_limit')),
            ];
        }

        new Table($output)->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware', 'Auth', 'Rate limit'])->setRows($rows)->render();

        return Command::SUCCESS;
    }

    /**
     * @param class-string|array{0: class-string, 1: array<string, mixed>} $entry
     */
    private static function middleware(string|array $entry): string
    {
        $class = is_string($entry) ? $entry : $entry[0];
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        if (is_string($entry) || $entry[1] === []) {
            return $short;
        }
        $arguments = [];
        foreach ($entry[1] as $name => $value) {
            $arguments[] = $name . ': ' . (is_scalar($value) || $value === null ? var_export($value, true) : gettype($value));
        }

        return $short . '(' . implode(', ', $arguments) . ')';
    }

    private static function handler(Route $route): string
    {
        $redirect = $route->option(Redirect::KEY);
        if ($route->handler[0] === Redirector::class && is_array($redirect) && is_string($redirect['to'] ?? null)) {
            return sprintf('redirect %s -> %s', is_int($redirect['status'] ?? null) ? (string) $redirect['status'] : '', $redirect['to']);
        }

        return $route->handler[0] . '::' . $route->handler[1];
    }

    private static function auth(mixed $option, mixed $can): string
    {
        $parts = [];
        if (is_array($option)) {
            $roles = $option['roles'] ?? [];
            $names = [];
            foreach (is_array($roles) ? $roles : [] as $role) {
                if (is_string($role)) {
                    $names[] = $role;
                }
            }
            $parts[] = $names === [] ? 'yes' : implode('|', $names);
        }
        if (is_array($can) && is_string($can['ability'] ?? null)) {
            $parts[] = 'can:' . $can['ability'];
        }

        return implode(' ', $parts);
    }

    private static function rateLimit(mixed $option): string
    {
        if (!is_array($option) || !is_int($option['limit'] ?? null) || !is_int($option['window'] ?? null)) {
            return '';
        }

        return sprintf('%d/%ds %s', $option['limit'], $option['window'], is_string($option['by'] ?? null) ? $option['by'] : 'ip');
    }
}
