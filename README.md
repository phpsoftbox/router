# Routing

Лёгкий роутер PhpSoftBox позволяет регистрировать маршруты, группировать их, навешивать middleware, валидировать параметры и генерировать URL по имени маршрута.

Состав пакета:
- `PhpSoftBox\Router\RouteCollector` — регистрация маршрутов, групп, ресурсных маршрутов, глобальных middleware.
- `PhpSoftBox\Router\RouteResolver` — поиск подходящего маршрута по PSR-7 запросу.
- `PhpSoftBox\Router\Dispatcher` — исполнение обработчика маршрута с учётом middleware.
- `PhpSoftBox\Router\Router` — фасад (PSR-15 RequestHandler), объединяющий resolver+dispatcher и предоставляющий `urlFor()`.
- `PhpSoftBox\Router\Handler\ContainerHandlerResolver` — резолвер обработчиков через DI-контейнер (PSR-11).
- `PhpSoftBox\Router\ParamTypesEnum` — встроенные валидаторы параметров маршрута.

Мы используем собственные реализации PSR-7/PSR-17 (`PhpSoftBox\Http\Message\*`).

Быстрый старт

```php
use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\Uri;
use PhpSoftBox\Router\{RouteCollector, RouteResolver, Dispatcher, Router};

$routes = new RouteCollector();
$routes->get('/hello', fn($r) => new Response(200, [], 'OK'), name: 'hello');
$routes->get('/users/{id}/{extra?}', [UserController::class, 'show'], name: 'user.show');

$router = new Router(new RouteResolver($routes), new Dispatcher(), $routes);

// Обработка входящего запроса
$request  = new ServerRequest('GET', new Uri('https://example.com/hello'));
$response = $router->handle($request); // 200 OK

// Генерация URL по имени
$url = $router->urlFor('user.show', ['id' => 42]); // "/users/42"
```

Регистрация маршрутов

- `get(post|put|delete|any)(string $path, callable|array|string $handler, array $middlewares = [], ?string $name = null, ?string $host = null)`
- `resource(string $path, string $controller, array $except = [], array $middlewares = [], array $routeMiddlewares = [], ?string $namePrefix = null)`
- `addControllerMiddleware(string $controller, array $middlewares, array $only = [], array $except = [])`

В middleware можно передавать как экземпляры, так и строки (alias, группа или class‑string).

Middleware для контроллеров

```php
$routes->addControllerMiddleware(UserController::class, ['auth']);
$routes->addControllerMiddleware(UserController::class, ['admin'], only: ['store', 'update']);
```

Рекомендуется вешать middleware на маршруты или группы; контроллеры/экшены — для точечных случаев.

Примеры

```php
$rc = new RouteCollector();

// Обычные маршруты
$rc->get('/posts', [PostController::class, 'index']);
$rc->get('/posts/{slug}', [PostController::class, 'show']);
$rc->post('/posts', [PostController::class, 'store']);

// Invokable-класс
$rc->get('/ping', PingAction::class);

// Опциональные параметры: сегмент "/{slug?}" можно опустить
$rc->get('/blog/{slug?}', [BlogController::class, 'show']);

// Ограничение по хосту и метод ANY
$rc->any('/internal/ping', [SysController::class, 'ping'], host: 'api.example.com');

// Именованные маршруты
$rc->get('/u/{id}', [UserController::class, 'show'], name: 'users.show');
```

Группы и middleware

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use PhpSoftBox\Http\Message\Response;

$rc = new RouteCollector();

// Глобальный middleware
$rc->addMiddleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface {
        return $handler->handle($request);
    }
});

// Группа с префиксом и своим набором middleware
$rc->group('/api', function (RouteCollector $r) {
    $r->get('/users', fn($r) => new Response(200));
}, [new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface {
        return $handler->handle($request);
    }
}]);
```

Resource (CRUD) маршруты

```php
use PhpSoftBox\Auth\Middleware\AuthMiddleware;
use PhpSoftBox\Session\CsrfMiddleware;

$rc->resource(
    '/users', UserController::class,
    except: [],
    middlewares: [new AuthMiddleware()],
    routeMiddlewares: [
        'store' => [new CsrfMiddleware()],
        'update' => [new CsrfMiddleware()],
    ],
    namePrefix: 'users',
    appendRestoreMethod: true,
);
// Сгенерирует: GET /users (users.index), GET /users/{id} (users.show), POST /users (users.store),
// PUT /users/{id} (users.update), DELETE /users/{id} (users.destroy),
// POST /users/{id}/restore (users.restore)
```

Автонейминг маршрутов

Если имя не задано явно, оно генерируется автоматически по пути и методу.

Примеры:

```text
GET /                 -> root.index
GET /health           -> health.index
GET /status/ping      -> status.ping.index
POST /status/ping     -> status.ping.store

GET /users            -> users.index
POST /users           -> users.store
GET /users/{id}       -> users.show
PUT /users/{id}       -> users.update
DELETE /users/{id}    -> users.destroy

GET /api/users        -> api.users.index
GET /api/crm/orders/{id} -> api.crm.orders.show
```

Конфликты
- Если два маршрута получают одинаковое имя, выбрасывается исключение.
- Например: `GET /users` и `GET /users/index` оба дадут `users.index`.

Как избежать конфликтов
- Используйте `group('/api', ...)` — префикс группы попадёт в имя (`api.users.index`).
- Задавайте `name` явно для нестандартных маршрутов (`health`, `metrics`).
- Для `resource(...)` задавайте `namePrefix`, чтобы контролировать неймспейс.

Можно передавать строки вместо экземпляров middleware, если диспетчер умеет их резолвить:

```php
use PhpSoftBox\Application\Middleware\KernelRouteMiddlewareResolver;
use PhpSoftBox\Router\Dispatcher;

$dispatcher = new Dispatcher(
    handlerResolver: null,
    middlewareResolver: new KernelRouteMiddlewareResolver($kernel->middlewareManager(), $container),
);
```

Валидация параметров

```php
use PhpSoftBox\Router\ParamTypesEnum as T;

$rc->get('/users/{id}', [UserController::class, 'show'], validators: ['id' => T::INT]);
$rc->get('/posts/{slug}', [PostController::class, 'show'], validators: [
    'slug' => fn(string $v) => preg_match('~^[a-z0-9-]+$~', $v) === 1,
]);
```

Поведение
- Несоответствие валидатору бросает `InvalidRouteParameterException` (сообщение включает имя параметра).
- Несоответствие пути/метода/хоста ведёт к отсутствию маршрута (404) или `MethodNotAllowedException` (405).

Генерация URL: Router::urlFor()

```php
$router->urlFor('users.show', ['id' => 10]);          // "/u/10"
$router->urlFor('user.show', ['id' => 42]);           // "/users/42"
$router->urlFor('user.show', ['id' => 42, 'x' => 1]); // лишние параметры игнорируются
$router->urlFor('user.show', ['id' => 42, 'extra' => 'q']); // для "/users/{id}/{extra?}" => "/users/42/q"
```

Правила
- Обязательные плейсхолдеры `{param}` должны быть предоставлены — иначе `RouteNotFoundException`.
- Опциональные сегменты `/{param?}` удаляются, если параметр не передан.
- В пути нормализуются повторяющиеся слеши, завершающий слеш отбрасывается (кроме корня).

Данные маршрута в request attributes
- `id`/`slug` и прочие параметры маршрута кладутся в `attributes`.
- Дополнительно доступны `_route` (имя или путь маршрута) и `_route_params` (все параметры).

Интеграция
- `Router` реализует PSR-15 `RequestHandlerInterface` и работает с нашими PSR-7/17 реализациями (`PhpSoftBox\Http\Message\*`).
- Для DI можно прокидывать `RouteCollector` в `RouteResolver`, затем собрать `Router`:

```php
$routes   = new RouteCollector();
$resolver = new RouteResolver($routes);
$router   = new Router($resolver, new Dispatcher(), $routes);
```

Пример DI-резолвера обработчиков (PSR-11)

```php
use PhpSoftBox\Router\Handler\ContainerHandlerResolver;
use PhpSoftBox\Router\Dispatcher;

$dispatcher = new Dispatcher(new ContainerHandlerResolver($container));
$router = new Router(new RouteResolver($routes), $dispatcher, $routes);
```

Если контейнер поддерживает `call()` (например PHP-DI), он будет использован для инъекций в методы.

CLI

```bash
router:list
router:cache
router:cache-clear
```

## Кеш маршрутов

Кеш сохраняется через `CacheInterface`. В кеш попадают только обработчики
в виде `Class::method` или invokable‑класс, а middleware должны быть строками
(alias, группа или class‑string). Замыкания и кастомные валидаторы не поддерживаются.

```php
use PhpSoftBox\Router\Cache\RouteCache;
use PhpSoftBox\Router\RouteCollector;

$collector = new RouteCollector();
$collector->get('/users/{id}', [UserController::class, 'show'], validators: ['id' => ParamTypesEnum::INT]);

$cache = new RouteCache($cacheStorage);
$cache->dump($collector, 'dev');

$routes = $cache->load('dev');
```
