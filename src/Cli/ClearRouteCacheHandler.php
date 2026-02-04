<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Router\Cache\RouteCache;
use Psr\SimpleCache\CacheInterface;

use function is_string;

final readonly class ClearRouteCacheHandler implements HandlerInterface
{
    public function __construct(
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

        $key = RouteCache::cacheKeyForEnvironment(is_string($env) ? $env : null);

        if ($this->cache->delete($key)) {
            $runner->io()->writeln('Кеш маршрутов очищен (' . $key . ').', 'success');

            return Response::SUCCESS;
        }

        $runner->io()->writeln('Не удалось очистить кеш маршрутов (' . $key . ').', 'error');

        return Response::FAILURE;
    }
}
