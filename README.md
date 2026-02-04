# Routing

Лёгкий роутер PhpSoftBox позволяет регистрировать маршруты, группировать их, навешивать middleware, валидировать параметры и генерировать URL по имени маршрута.

Состав пакета:
- `PhpSoftBox\Router\RouteCollector` — регистрация маршрутов, групп, ресурсных маршрутов, глобальных middleware.
- `PhpSoftBox\Router\RouteResolver` — поиск подходящего маршрута по PSR-7 запросу.
- `PhpSoftBox\Router\Dispatcher` — исполнение обработчика маршрута с учётом middleware.
- `PhpSoftBox\Router\Router` — PSR-15 RequestHandler (resolve + dispatch).
- `PhpSoftBox\Router\UrlGenerator` — генерация URL по имени маршрута.
- `PhpSoftBox\Router\RequestContext` — контекст запроса для генерации абсолютных URL.
- `PhpSoftBox\Router\Handler\ContainerHandlerResolver` — резолвер обработчиков через DI-контейнер (PSR-11).
- `PhpSoftBox\Router\ParamTypesEnum` — встроенные валидаторы параметров маршрута.

Мы используем собственные реализации PSR-7/PSR-17 (`PhpSoftBox\Http\Message\*`).

Быстрый старт

```php
use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\Uri;
use PhpSoftBox\Router\{RouteCollector, RouteResolver, Dispatcher, Router, UrlGenerator};

$routes = new RouteCollector();
$routes->get('/hello', fn($r) => new Response(200, [], 'OK'))->name('hello');
$routes->get('/users/{id}/{extra?}', [UserController::class, 'show'])->name('user.show');

$router = new Router(new RouteResolver($routes), new Dispatcher(), $routes);

// Обработка входящего запроса
$request  = new ServerRequest('GET', new Uri('https://example.com/hello'));
$response = $router->handle($request); // 200 OK

// Генерация URL по имени
$urlGenerator = new UrlGenerator($routes, $request);
$url = $urlGenerator->generate('user.show', ['id' => 42]); // "/users/42"
```

Регистрация маршрутов

- `get(post|put|delete|patch|head|options|any)(string $path, callable|array|string $handler): RouteBuilder`
- `group(callable $callback): RouteGroupBuilder`
- `import(string $pathWithoutExtension): callable`
- `importGroup(string $pathWithoutExtension): RouteGroupBuilder`
- `resource(string $path, string $controller): ResourceBuilder`
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
$rc->get('/posts', [PostController::class, 'index'])->name('posts.index');
$rc->get('/posts/{slug}', [PostController::class, 'show'])->name('posts.show');
$rc->post('/posts', [PostController::class, 'store'])->name('posts.store');

// Invokable-класс
$rc->get('/ping', PingAction::class);

// Опциональные параметры: сегмент "/{slug?}" можно опустить
$rc->get('/blog/{slug?}', [BlogController::class, 'show']);

// Wildcard-параметры: захватывают несколько сегментов пути
$rc->get('/docs/{path*}', [DocsController::class, 'show'])->name('docs.show');
$rc->get('/docs/{path*?}', [DocsController::class, 'showOptional'])->name('docs.optional');

// Ограничение по хосту и метод ANY
$rc->any('/internal/ping', [SysController::class, 'ping'])->host('api.example.com');

// Несколько допустимых хостов
$rc->get('/admin', [AdminController::class, 'index'])->host([
    'admin.example.com',
    'admin-mirror.example.com',
]);

// Именованные маршруты
$rc->get('/u/{id}', [UserController::class, 'show'])->name('users.show');
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
$rc->group(function (RouteCollector $r) {
    $r->get('/users', fn($r) => new Response(200));
})
->prefix('/api')
->middlewares([new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface {
        return $handler->handle($request);
    }
}])
->apply();
```

Resource (CRUD) маршруты

```php
use PhpSoftBox\Auth\Middleware\AuthMiddleware;
use PhpSoftBox\Session\CsrfMiddleware;

$rc->resource('/users', UserController::class)
    ->except([])
    ->middlewares([new AuthMiddleware()])
    ->routeMiddlewares([
        'store' => [new CsrfMiddleware()],
        'update' => [new CsrfMiddleware()],
    ])
    ->namePrefix('users')
    ->appendRestoreMethod()
    ->routeParameter('user')
    ->apply();
// Сгенерирует: GET /users (users.index), GET /users/{user} (users.show), POST /users (users.store),
// GET /users/create (users.create), GET /users/{user}/edit (users.edit),
// PUT /users/{user} (users.update), DELETE /users/{user} (users.destroy),
// POST /users/{user}/restore (users.restore)
```

Автонейминг маршрутов

Если имя не задано явно, оно генерируется автоматически по пути и методу.

Примеры:

```text
GET /                 -> root.index
GET /health           -> health.index
GET /status/ping      -> status.ping.index
POST /status/ping     -> status.ping.store

GET /users                      -> users.index
POST /users                     -> users.store
GET /users/{id}                 -> users.show
PUT /users/{id}                 -> users.update
DELETE /users/{id}              -> users.destroy
POST /api/accounts/{id}/refresh -> api.accounts.by-id.refresh.store

GET /api/users        -> api.users.index
GET /api/crm/orders/{id} -> api.crm.orders.show
```

Конфликты
- Если два маршрута получают одинаковое имя, выбрасывается исключение.
- Например: `GET /users` и `GET /users/index` оба дадут `users.index`.

Как избежать конфликтов
- Используйте `group(...)->prefix('/api')->apply()` — префикс группы попадёт в имя (`api.users.index`).
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

Параметры маршрута

Поддержанные формы:

- `{id}` — обязательный параметр одного сегмента, например `/users/42`;
- `{slug?}` — опциональный параметр одного сегмента, например `/blog` или `/blog/post-1`;
- `{path*}` — обязательный wildcard-параметр, захватывает один или несколько сегментов;
- `{path*?}` — опциональный wildcard-параметр, захватывает ноль или больше сегментов.

```php
$rc->get('/docs/{path*}', [DocsController::class, 'show'])->name('docs.show');

// /docs/getting-started/install
// attributes:
// path = "getting-started/install"
```

Правила wildcard:

- wildcard-параметр должен быть последним сегментом маршрута;
- `{path*}` не матчится на `/docs`, потому что нужен хотя бы один сегмент;
- `{path*?}` матчится и на `/docs`, и на `/docs/a/b`;
- значение попадает в validators и request attributes без leading slash;
- route cache сохраняет wildcard-маршруты как обычные маршруты.

Валидация параметров

```php
use PhpSoftBox\Router\ParamTypesEnum as T;

$rc->get('/users/{id}', [UserController::class, 'show'])->validators(['id' => T::INT]);
$rc->get('/posts/{slug}', [PostController::class, 'show'])->validators([
    'slug' => fn(string $v) => preg_match('~^[a-z0-9-]+$~', $v) === 1,
]);
$rc->get('/docs/{path*}', [DocsController::class, 'show'])->validators([
    'path' => fn(string $v) => preg_match('~^[a-z0-9/_-]+$~', $v) === 1,
]);
```

Поведение
- Несоответствие валидатору бросает `InvalidRouteParameterException` (сообщение включает имя параметра).
- Несоответствие пути/метода/хоста ведёт к отсутствию маршрута (404) или `MethodNotAllowedException` (405).
- После match роутер кладет параметры в request attribute `_route_params` и
  дублирует каждый параметр отдельным attribute по имени параметра.
- Если `ContainerHandlerResolver` выполняет entity binding, `_route_params`
  обновляется найденными сущностями. Это позволяет внешним компонентам, включая
  `phpsoftbox/auth`, читать актуальные route params через bridge-provider без
  прямой зависимости от Router.

Генерация URL: UrlGenerator::generate()

```php
$urlGenerator->generate('users.show', ['id' => 10]);          // "/u/10"
$urlGenerator->generate('user.show', ['id' => 42]);           // "/users/42"
$urlGenerator->generate('user.show', ['id' => 42, 'x' => 1]); // лишние параметры игнорируются
$urlGenerator->generate('user.show', ['id' => 42, 'extra' => 'q']); // для "/users/{id}/{extra?}" => "/users/42/q"
$urlGenerator->generate('docs.show', ['path' => 'guide/install']); // для "/docs/{path*}" => "/docs/guide/install"
$urlGenerator->generate('user.show', ['id' => 42], true);     // "https://example.com/users/42"
$urlGenerator->generate('user.show', ['id' => 42], true, 'admin-mirror.example.com'); // явный host

// Можно передавать ORM-сущность (EntityInterface): будет подставлен primary key (id()).
$urlGenerator->generate('user.show', ['user' => $userEntity]); // "/users/42"
```

Настройка контекста запроса:

```php
use PhpSoftBox\Router\RequestContext;
use PhpSoftBox\Router\UrlGenerator;

$context = RequestContext::fromRequest($request);
$context->setHost('tenant.example.com');
$context->setScheme('https');

$urlGenerator = new UrlGenerator($routes, context: $context);
```

Правила
- Обязательные плейсхолдеры `{param}` должны быть предоставлены — иначе `RouteNotFoundException`.
- Опциональные сегменты `/{param?}` удаляются, если параметр не передан.
- Wildcard-плейсхолдеры `{path*}` и `{path*?}` подставляются без leading/trailing slash.
- В пути нормализуются повторяющиеся слеши, завершающий слеш отбрасывается (кроме корня).
- Третий аргумент `bool $shouldAbsolute = false` включает абсолютный URL.
- Четвертый аргумент `?string $host = null` позволяет явно выбрать host для абсолютного URL.
- Если у маршрута есть `host(...)` и текущий host из `RequestContext` входит в список допустимых, используется текущий host.
- Если у маршрута есть `host(...)`, но текущий host не задан или не входит в список допустимых, используется первый host маршрута.
- Если у маршрута нет ограничения по host, используется host из `RequestContext`.
- Если host не удалось определить, абсолютная генерация возвращает относительный URL.

Данные маршрута в request attributes
- `id`/`slug` и прочие параметры маршрута кладутся в `attributes`.
- Wildcard-параметры кладутся туда же, например `path = "guide/install"`.
- Дополнительно доступны `_route` (имя или путь маршрута) и `_route_params` (все параметры).

Авто‑резолв сущностей (EntityInterface)

Если параметр контроллера типизирован сущностью ORM (`EntityInterface`), контейнер автоматически подгружает сущность по параметру маршрута, имя которого совпадает с именем аргумента:

```php
use App\Entity\User\User;

// маршрут: GET /users/{user}
public function show(User $user): ResponseInterface
{
    // $user уже загружен из ORM
}
```

По умолчанию используется `EntityManagerInterface::find()`. Для расширенной загрузки используйте единый атрибут `#[ResolveEntity]`:

```php
use PhpSoftBox\Router\Attributes\ResolveEntity;

public function show(#[ResolveEntity(withDeleted: true, with: ['roles', 'profile'])] User $user): ResponseInterface
{
}
```

Для lookup по физической колонке вместо primary key укажите `column`. Например, маршрут
`GET /articles/{article}` может резолвить сущность по slug:

```php
public function show(#[ResolveEntity(column: 'slug')] Article $article): ResponseInterface
{
}
```

Параметр `column` совместим с `withDeleted` и `with`. Идентификатор колонки экранируется
активным DB-драйвером, а значение маршрута передаётся как bound parameter. Числовые строки
при lookup по колонке сохраняются строками.

`WithDeleted` остаётся для обратной совместимости, но новый код лучше писать через `ResolveEntity`.

Scoped bindings (проверка связей между сущностями)

Чтобы автоматически проверять связь между вложенными сущностями, используйте `scopeBindings()`:

```php
$routes->scopeBindings(function (RouteCollector $routes): void {
    $routes->get('/users/{user}/companies/{company}', [UserCompaniesController::class, 'show']);
});
```

При включённом `scopeBindings` сначала резолвятся все сущности, затем проверяется их связь по цепочке (parent → child). Если связь не найдена — выбрасывается `InvalidRouteParameterException` (404).

Поддерживаемые типы связей: `many_to_one`, `has_one`, `has_many`, `belongs_to_many`, `has_many_through`, `morph_many`, `morph_to`.

Метаданные связей Router получает только через публичный контракт
`EntityManagerInterface::metadataProvider()`. Поэтому scoped bindings совместимы с
entity-aware registry, tenant-менеджерами и другими proxy/wrapper-реализациями entity manager.
Внутренние методы конкретной реализации `EntityManager` не являются частью интеграционного
контракта Router.

По умолчанию проверка пытается использовать ORM‑метаданные связей. Для кастомной логики можно зарегистрировать свой resolver:

```php
use PhpSoftBox\Router\Binding\ScopedBindingsResolverInterface;

final class AppScopedBindingsResolver implements ScopedBindingsResolverInterface
{
    public function supports(object $parent, object $child, array $context = []): bool
    {
        // определить, поддерживается ли пара сущностей
    }

    public function isScoped(object $parent, object $child, array $context = []): bool
    {
        // вернуть true, если сущности действительно связаны
    }
}
```

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
$collector->get('/users/{id}', [UserController::class, 'show'])->validators(['id' => ParamTypesEnum::INT]);

$cache = new RouteCache($cacheStorage);
$cache->dump($collector, 'dev');

$routes = $cache->load('dev');
```
