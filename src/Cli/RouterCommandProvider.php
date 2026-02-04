<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Cli;

use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class RouterCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'router:list',
            description: 'Список зарегистрированных маршрутов',
            signature: [],
            handler: ListRoutesHandler::class,
        ));

        $registry->register(Command::define(
            name: 'router:cache',
            description: 'Сохранить кеш маршрутов',
            signature: [],
            handler: CacheRoutesHandler::class,
        ));

        $registry->register(Command::define(
            name: 'router:cache-clear',
            description: 'Очистить кеш маршрутов',
            signature: [],
            handler: ClearRouteCacheHandler::class,
        ));
    }
}
