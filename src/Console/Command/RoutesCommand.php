<?php

declare(strict_types=1);

namespace Keel\Console\Command;

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
                $route->handler[0] . '::' . $route->handler[1],
                implode(', ', array_map(static fn(string $m): string => substr($m, (int) strrpos($m, '\\') + 1), $route->middleware)),
                self::auth($route->option('auth')),
                self::rateLimit($route->option('rate_limit')),
            ];
        }

        new Table($output)->setHeaders(['Method', 'Path', 'Name', 'Handler', 'Middleware', 'Auth', 'Rate limit'])->setRows($rows)->render();

        return Command::SUCCESS;
    }

    private static function auth(mixed $option): string
    {
        if (!is_array($option)) {
            return '';
        }
        $roles = $option['roles'] ?? [];
        $names = [];
        foreach (is_array($roles) ? $roles : [] as $role) {
            if (is_string($role)) {
                $names[] = $role;
            }
        }

        return $names === [] ? 'yes' : implode('|', $names);
    }

    private static function rateLimit(mixed $option): string
    {
        if (!is_array($option) || !is_int($option['limit'] ?? null) || !is_int($option['window'] ?? null)) {
            return '';
        }

        return sprintf('%d/%ds %s', $option['limit'], $option['window'], is_string($option['by'] ?? null) ? $option['by'] : 'ip');
    }
}
