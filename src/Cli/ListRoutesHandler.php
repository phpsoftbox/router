<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Router\RouteCollector;

use function get_class;
use function get_debug_type;
use function is_array;
use function is_object;
use function is_string;
use function max;
use function str_pad;
use function strlen;

final class ListRoutesHandler implements HandlerInterface
{
    public function __construct(
        private readonly RouteCollector $routes,
    ) {
    }

    /**
     * Выводит список зарегистрированных маршрутов.
     */
    public function run(RunnerInterface $runner): int|Response
    {
        $rows = [];
        foreach ($this->routes->getRoutes() as $route) {
            $handler = $this->formatHandler($route->handler);
            $rows[]  = [
                $route->method,
                $route->path,
                $route->name ?? '-',
                $handler,
            ];
        }

        if ($rows === []) {
            $runner->io()->writeln('Маршруты не зарегистрированы.', 'comment');

            return Response::SUCCESS;
        }

        $widths = [
            strlen('METHOD'),
            strlen('URL'),
            strlen('NAME'),
        ];

        foreach ($rows as $row) {
            $widths[0] = max($widths[0], strlen((string) $row[0]));
            $widths[1] = max($widths[1], strlen((string) $row[1]));
            $widths[2] = max($widths[2], strlen((string) $row[2]));
        }

        $runner->io()->writeln(
            str_pad('METHOD', $widths[0]) . '  '
            . str_pad('URL', $widths[1]) . '  '
            . str_pad('NAME', $widths[2]) . '  > HANDLER',
        );

        foreach ($rows as $row) {
            $runner->io()->writeln(
                str_pad((string) $row[0], $widths[0]) . '  '
                . str_pad((string) $row[1], $widths[1]) . '  '
                . str_pad((string) $row[2], $widths[2]) . '  > ' . $row[3],
            );
        }

        return Response::SUCCESS;
    }

    private function formatHandler(mixed $handler): string
    {
        if (is_array($handler)) {
            $class  = $handler[0] ?? null;
            $method = $handler[1] ?? null;
            if (is_object($class)) {
                $class = get_class($class);
            }
            if (is_string($class) && is_string($method) && $method !== '') {
                return $class . '::' . $method;
            }

            return 'array';
        }

        if (is_object($handler)) {
            $class = get_class($handler);

            return $class . '::__invoke';
        }

        if (is_string($handler)) {
            return $handler;
        }

        return get_debug_type($handler);
    }
}
