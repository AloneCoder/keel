<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:middleware', description: 'Generate a PSR-15 middleware')]
final class MakeMiddlewareCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Middleware name, e.g. RateLimit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Sample');
        $class = str_ends_with($name, 'Middleware') ? $name : $name . 'Middleware';
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Middleware;

            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\MiddlewareInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;

            /**
             * Add to HttpConfig for every request or to #[Middleware] on a controller or route.
             */
            final class {$class} implements MiddlewareInterface
            {
                public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
                {
                    return \$handler->handle(\$request);
                }
            }

            PHP;

        return $this->write($input, $output, 'Middleware', $class, $source);
    }
}
