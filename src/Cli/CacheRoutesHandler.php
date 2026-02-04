<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Router\Cache\RouteCache;
use PhpSoftBox\Router\Exception\RouteCacheException;
use PhpSoftBox\Router\RouteCollector;
use Psr\SimpleCache\CacheInterface;

use function is_string;

final readonly class CacheRoutesHandler implements HandlerInterface
{
    public function __construct(
        private RouteCollector $routes,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        if ($this->cache === null) {
            $runner->io()->writeln('Кеш не сконфигурирован (CacheInterface недоступен).', 'error');

            return Response::FAILURE;
        }

        $env = $runner->request()->option('environment');
        if ($env === '') {
            $env = null;
        }

        $cache = new RouteCache($this->cache);

        try {
            $cache->dump($this->routes, is_string($env) ? $env : null);
        } catch (RouteCacheException $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $key = RouteCache::cacheKeyForEnvironment(is_string($env) ? $env : null);
        $runner->io()->writeln('Кеш маршрутов сохранён (' . $key . ').', 'success');

        return Response::SUCCESS;
    }
}
