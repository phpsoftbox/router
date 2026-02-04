<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

use function array_filter;
use function array_merge;
use function array_pop;
use function array_values;
use function count;
use function dirname;
use function end;
use function explode;
use function get_class;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_object;
use function is_string;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Пример использования:
 *
 * Добавление middleware к конкретному маршруту.
 * ```
 * <?php
 * $routeCollector = new RouteCollector();
 *
 * $routeCollector->get('/users', function (ServerRequestInterface $request) {
 *      return new \MyApp\Http\Response(200, [], 'User list');
 * })->middlewares([new \PhpSoftBox\Auth\Middleware\AuthMiddleware(), new LoggingMiddleware()]);
 * ```
 *
 * Добавление middleware к группе маршрутов
 * ```
 * <?php
 * $routeCollector = new RouteCollector();
 *
 * $routeCollector->group(function (RouteCollector $router) {
 *      $router->get('/users', function (ServerRequestInterface $request) {
 *          return new \MyApp\Http\Response(200, [], 'User list');
 *      })->middlewares([new LoggingMiddleware()]); // Локальный middleware
 * })
 *      ->prefix('/api')
 *      ->middlewares([new \PhpSoftBox\Auth\Middleware\AuthMiddleware()]) // Глобальный middleware для группы
 *      ->apply();
 * ```
 *
 * Помимо использования основных методов добавления маршрутов, можно создавать resource (CRUD-маршрутов)
 * ```
 * <?php
 * $authMiddleware = [new \PhpSoftBox\Auth\Middleware\AuthMiddleware()];
 * $adminMiddleware = [new \PhpSoftBox\Auth\Middleware\AuthMiddleware(), new AdminMiddleware()];
 *
 * $routeCollector = new RouteCollector();
 *
 * $routeCollector->resource('/users', UserController::class)
 *      ->middlewares([new LoggingMiddleware()])
 *      ->routeMiddlewares([
 *          'store' => $authMiddleware,
 *          'update' => $authMiddleware,
 *          'destroy' => $adminMiddleware,
 *      ])
 *      ->namePrefix('users')
 *      ->appendRestoreMethod()
 *      ->apply();
 * ```
 *
 * Примеры с валидацией:
 * ```
 * <?php
 *
 * // Маршрут с валидацией параметра id (только цифры)
 * $routeCollector->get(
 *      '/users/{id}',
 *      [UserController::class, 'show']
 * )->validators(['id' => ParamType::INT]);
 *
 * // Маршрут с кастомным валидатором
 * $routeCollector->get(
 *      '/posts/{slug}',
 *      [PostController::class, 'show']
 * )->validators(['slug' => function ($value) {
 *          return preg_match('/^[a-z0-9-]+$/', $value) === 1;
 *      }]);
 * ```
 */
final class RouteCollector
{
    /**
     * @var Route[] Массив всех зарегистрированных маршрутов.
     */
    private array $routes = [];

    /**
     * @var array<string, Route> Ассоциативный массив именованных маршрутов, где ключ — имя маршрута, значение — путь.
     */
    private array $namedRoutes = [];

    private array $globalMiddlewares     = [];
    private ?RouteGroup $currentGroup    = null;
    private array $controllerMiddlewares = [];
    /**
     * @var list<string>
     */
    private array $routeFileStack = [];

    public function addMiddleware(MiddlewareInterface|string $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    public function group(callable $callback): RouteGroupBuilder
    {
        return new RouteGroupBuilder(function (array $options) use ($callback): void {
            $this->applyGroup(
                callback: $callback,
                prefix: (string) ($options['prefix'] ?? ''),
                middlewares: (array) ($options['middlewares'] ?? []),
                host: $options['host'] ?? null,
                namePrefix: $options['namePrefix'] ?? null,
                scopeBindings: (bool) ($options['scopeBindings'] ?? false),
            );
        });
    }

    private function applyGroup(
        callable $callback,
        string $prefix = '',
        array $middlewares = [],
        ?string $host = null,
        ?string $namePrefix = null,
        bool $scopeBindings = false,
    ): void {
        $previousGroup = $this->currentGroup;

        $combinedNamePrefix = $namePrefix;
        if ($previousGroup?->namePrefix !== null && $previousGroup->namePrefix !== '') {
            if ($combinedNamePrefix !== null && $combinedNamePrefix !== '') {
                $combinedNamePrefix = $previousGroup->namePrefix . '.' . $combinedNamePrefix;
            } else {
                $combinedNamePrefix = $previousGroup->namePrefix;
            }
        }
        if ($combinedNamePrefix !== null) {
            $combinedNamePrefix = trim($combinedNamePrefix, '.');
            if ($combinedNamePrefix === '') {
                $combinedNamePrefix = null;
            }
        }

        $combinedScopeBindings = $scopeBindings || ($previousGroup?->scopeBindings ?? false);

        $this->currentGroup = new RouteGroup(
            prefix: $previousGroup ? $previousGroup->prefix . $prefix : $prefix,
            middlewares: array_merge($previousGroup ? $previousGroup->middlewares : [], $middlewares),
            host: $host ?? $previousGroup?->host,
            namePrefix: $combinedNamePrefix,
            scopeBindings: $combinedScopeBindings,
        );

        $callback($this);

        $this->currentGroup = $previousGroup;
    }

    public function import(string $path): callable
    {
        $resolvedPath = $this->resolveImportPath($path);
        $register     = $this->requireRouteRegister($resolvedPath);

        return function (RouteCollector $routes) use ($resolvedPath, $register): void {
            $routes->executeRouteRegister($resolvedPath, $register);
        };
    }

    public function importGroup(string $path): RouteGroupBuilder
    {
        return $this->group($this->import($path));
    }

    public function loadFile(string $file): void
    {
        $register = $this->requireRouteRegister($file);
        $this->executeRouteRegister($file, $register);
    }

    public function scopeBindings(callable $callback): void
    {
        $this->group($callback)->scopeBindings()->apply();
    }

    /**
     * @param array<MiddlewareInterface|string> $middlewares
     * @param string[] $only
     * @param string[] $except
     */
    public function addControllerMiddleware(
        string $controller,
        array $middlewares,
        array $only = [],
        array $except = [],
    ): void {
        $this->controllerMiddlewares[$controller][] = [
            'middlewares' => $middlewares,
            'only'        => $only,
            'except'      => $except,
        ];
    }

    public function get(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('GET', $path, $handler);
    }

    public function post(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('POST', $path, $handler);
    }

    public function put(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('PUT', $path, $handler);
    }

    public function delete(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('DELETE', $path, $handler);
    }

    public function patch(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('PATCH', $path, $handler);
    }

    public function head(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('HEAD', $path, $handler);
    }

    public function options(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('OPTIONS', $path, $handler);
    }

    public function any(
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->map('ANY', $path, $handler);
    }

    public function map(
        string $method,
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        return $this->buildRoute($method, $path, $handler);
    }

    public function resource(
        string $path,
        string $controller,
    ): ResourceBuilder {
        return new ResourceBuilder(function (array $options) use ($path, $controller): void {
            $this->applyResource($path, $controller, $options);
        });
    }

    /**
     * @param array{
     *     except?:list<string>,
     *     middlewares?:list<MiddlewareInterface|string>,
     *     routeMiddlewares?:array<string, list<MiddlewareInterface|string>>,
     *     namePrefix?:?string,
     *     appendRestoreMethod?:bool,
     *     validators?:array<string,mixed>,
     *     routeParameter?:string
     * } $options
     */
    private function applyResource(string $path, string $controller, array $options): void
    {
        $except              = $this->normalizeResourceExcept((array) ($options['except'] ?? []));
        $middlewares         = (array) ($options['middlewares'] ?? []);
        $routeMiddlewares    = (array) ($options['routeMiddlewares'] ?? []);
        $namePrefix          = $options['namePrefix'] ?? null;
        $appendRestoreMethod = (bool) ($options['appendRestoreMethod'] ?? false);
        $validators          = (array) ($options['validators'] ?? []);
        $routeParameter      = (string) ($options['routeParameter'] ?? 'id');

        $defaultMethods = [
            'index'   => ['GET', $path, 'index'],
            'create'  => ['GET', $path . '/create', 'create'],
            'show'    => ['GET', "$path/{{$routeParameter}}", 'show'],
            'store'   => ['POST', $path, 'store'],
            'edit'    => ['GET', "$path/{{$routeParameter}}/edit", 'edit'],
            'update'  => ['PUT', "$path/{{$routeParameter}}", 'update'],
            'destroy' => ['DELETE', "$path/{{$routeParameter}}", 'destroy'],
        ];

        if ($appendRestoreMethod) {
            $defaultMethods['restore'] = ['POST', "$path/{{$routeParameter}}/restore", 'restore'];
        }

        foreach ($defaultMethods as $method => $details) {
            if (!in_array($method, $except, true)) {
                [$httpMethod, $routePath, $action] = $details;
                $routeName                         = $this->buildResourceRouteName($path, $method, $namePrefix);

                $builder = $this
                    ->map($httpMethod, $routePath, [$controller, $action])
                    ->middlewares($middlewares)
                    ->name($routeName)
                    ->validators($validators);

                if (isset($routeMiddlewares[$method])) {
                    $builder->middlewares((array) $routeMiddlewares[$method]);
                }
            }
        }
    }

    /**
     * @param list<string> $except
     * @return list<string>
     */
    private function normalizeResourceExcept(array $except): array
    {
        $normalized = [];
        foreach ($except as $method) {
            if (!is_string($method)) {
                continue;
            }
            $method = trim($method);
            if ($method === '') {
                continue;
            }
            $normalized[] = $method;
        }

        return $normalized;
    }

    private function buildResourceRouteName(string $path, string $method, ?string $namePrefix): string
    {
        if ($namePrefix !== null && $namePrefix !== '') {
            return $namePrefix . '.' . $method;
        }

        $segments       = $this->splitPath($path);
        $staticSegments = [];
        foreach ($segments as $segment) {
            if ($this->isParamSegment($segment)) {
                continue;
            }

            $staticSegments[] = $segment;
        }

        $base = implode('.', $staticSegments);
        if ($base === '') {
            $base = 'root';
        }

        return $base . '.' . $method;
    }

    /**
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, Route>
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    private function buildRoute(
        string $method,
        string $path,
        callable|array|string $handler,
    ): RouteBuilder {
        $routeIndex = $this->registerRoute(
            method: $method,
            path: $path,
            handler: $handler,
            middlewares: [],
            name: null,
            host: null,
            defaults: [],
            validators: [],
        );

        return new RouteBuilder(
            mutateRoute: function (callable $mutator) use ($routeIndex): void {
                $this->mutateRoute($routeIndex, $mutator);
            },
            qualifyRouteName: fn (?string $name): ?string => $this->applyNamePrefix($name),
        );
    }

    private function mutateRoute(int $index, callable $mutator): void
    {
        $currentRoute = $this->routes[$index] ?? null;
        if (!$currentRoute instanceof Route) {
            throw new RuntimeException('Route with index "' . $index . '" not found.');
        }

        $nextRoute = $mutator($currentRoute);
        if (!$nextRoute instanceof Route) {
            throw new RuntimeException('Route mutator must return an instance of ' . Route::class . '.');
        }

        $existingNamedRoute = $nextRoute->name !== null && $nextRoute->name !== ''
            ? ($this->namedRoutes[$nextRoute->name] ?? null)
            : null;
        if ($existingNamedRoute instanceof Route && $existingNamedRoute !== $currentRoute) {
            throw new RuntimeException('Route name already exists: ' . $nextRoute->name);
        }

        if ($currentRoute->name !== null && $currentRoute->name !== '') {
            unset($this->namedRoutes[$currentRoute->name]);
        }

        $this->routes[$index] = $nextRoute;

        if ($nextRoute->name !== null && $nextRoute->name !== '') {
            $this->namedRoutes[$nextRoute->name] = $nextRoute;
        }
    }

    private function registerRoute(
        string $method,
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null,
        ?string $host = null,
        array $defaults = [],
        array $validators = [],
        ?bool $scopeBindings = null,
    ): int {
        $path = $this->currentGroup ? $this->currentGroup->prefix . $path : $path;

        $middlewares = array_merge(
            $this->globalMiddlewares,
            $this->currentGroup ? $this->currentGroup->middlewares : [],
            $this->resolveControllerMiddlewares($handler),
            $middlewares,
        );

        $host = $host ?? $this->currentGroup?->host;

        if ($name === null || $name === '') {
            $name = $this->autoName($method, $path);
        }

        $name = $this->applyNamePrefix($name);

        $resolvedScopeBindings = $scopeBindings ?? ($this->currentGroup?->scopeBindings ?? false);

        $route = new Route(
            method: strtoupper($method),
            path: $path,
            handler: $handler,
            middlewares: $middlewares,
            name: $name,
            host: $host,
            defaults: $defaults,
            validators: $validators,
            scopeBindings: $resolvedScopeBindings,
        );

        if ($name !== null && $name !== '') {
            if (isset($this->namedRoutes[$name])) {
                throw new RuntimeException('Route name already exists: ' . $name);
            }
            $this->namedRoutes[$name] = $route;
        }

        $this->routes[] = $route;

        return count($this->routes) - 1;
    }

    private function requireRouteRegister(string $file): callable
    {
        $resolvedFile = $this->resolveRouteFilePath($file);

        $register = require $resolvedFile;
        if (!is_callable($register)) {
            throw new RuntimeException('Route file must return callable: ' . $resolvedFile);
        }

        return $register;
    }

    private function executeRouteRegister(string $file, callable $register): void
    {
        $resolvedFile = $this->resolveRouteFilePath($file);

        if (in_array($resolvedFile, $this->routeFileStack, true)) {
            throw new RuntimeException('Circular route import detected: ' . $resolvedFile);
        }

        $this->routeFileStack[] = $resolvedFile;

        try {
            $register($this);
        } finally {
            array_pop($this->routeFileStack);
        }
    }

    private function resolveImportPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Route import path must not be empty.');
        }

        if (!str_ends_with($path, '.php')) {
            $path .= '.php';
        }

        if ($path[0] === '/') {
            return $this->resolveRouteFilePath($path);
        }

        $currentFile = end($this->routeFileStack);
        $baseDir     = is_string($currentFile) && $currentFile !== ''
            ? dirname($currentFile)
            : ((string) (getcwd() ?: ''));

        if ($baseDir === '') {
            throw new RuntimeException('Cannot resolve relative route import path: ' . $path);
        }

        return $this->resolveRouteFilePath($baseDir . '/' . $path);
    }

    private function resolveRouteFilePath(string $file): string
    {
        $realPath = realpath($file);
        if ($realPath !== false && is_file($realPath)) {
            return $realPath;
        }

        if (is_file($file)) {
            return $file;
        }

        throw new RuntimeException('Route file not found: ' . $file);
    }

    private function autoName(string $method, string $path, ?string $namePrefix = null): string
    {
        $segments    = $this->splitPath($path);
        $lastSegment = $segments !== [] ? $segments[count($segments) - 1] : null;
        $lastIsParam = $lastSegment !== null && $this->isParamSegment($lastSegment);

        $baseSegments = [];
        $lastIndex    = $segments !== [] ? count($segments) - 1 : -1;
        foreach ($segments as $index => $segment) {
            if ($this->isParamSegment($segment)) {
                // Сегмент-параметр в конце оставляем "REST-подобным" (users.show),
                // но параметры в середине пути включаем в имя (accounts.by-id.refresh.store).
                if ($index < $lastIndex) {
                    $baseSegments[] = 'by-' . $this->normalizeParamSegmentName($segment);
                }
                continue;
            }
            $baseSegments[] = $segment;
        }

        $base = implode('.', $baseSegments);
        if ($namePrefix !== null && $namePrefix !== '') {
            if ($base === '' || str_contains($namePrefix, '.')) {
                $base = $namePrefix;
            } else {
                $parts                    = explode('.', $base);
                $parts[count($parts) - 1] = $namePrefix;
                $base                     = implode('.', $parts);
            }
        }
        if ($base === '') {
            $base = 'root';
        }

        $lastStatic = $this->lastSegment($base);
        if (in_array($lastStatic, ['index', 'show', 'store', 'update', 'destroy'], true)) {
            return $base;
        }

        $action = null;
        $method = strtoupper($method);

        if ($lastIsParam) {
            if ($method === 'GET') {
                $action = 'show';
            } elseif ($method === 'PUT' || $method === 'PATCH') {
                $action = 'update';
            } elseif ($method === 'DELETE') {
                $action = 'destroy';
            } elseif ($method === 'POST') {
                $action = 'store';
            }
        } elseif ($method === 'GET') {
            $action = 'index';
        } elseif ($method === 'POST') {
            $action = 'store';
        }

        if ($action === null) {
            return $base;
        }

        return $base . '.' . $action;
    }

    private function normalizeParamSegmentName(string $segment): string
    {
        $name = trim($segment, '{}');
        $name = rtrim($name, '?');

        $constraintPos = strpos($name, ':');
        if ($constraintPos !== false) {
            $name = substr($name, 0, $constraintPos);
        }

        $name = trim($name);
        if ($name === '') {
            return 'param';
        }

        $normalized = (string) preg_replace('/[^a-z0-9_]+/i', '-', $name);
        $normalized = trim($normalized, '-');
        if ($normalized === '') {
            return 'param';
        }

        return strtolower($normalized);
    }

    private function applyNamePrefix(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        $groupPrefix = $this->currentGroup?->namePrefix;
        if ($groupPrefix === null || $groupPrefix === '') {
            return $name;
        }

        if ($name === $groupPrefix || str_starts_with($name, $groupPrefix . '.')) {
            return $name;
        }

        return $groupPrefix . '.' . $name;
    }

    /**
     * @return list<string>
     */
    private function splitPath(string $path): array
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return [];
        }

        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
    }

    private function isParamSegment(string $segment): bool
    {
        return $segment !== '' && $segment[0] === '{' && str_ends_with($segment, '}');
    }

    private function lastSegment(string $name): string
    {
        $parts = explode('.', $name);

        return $parts !== [] ? $parts[count($parts) - 1] : $name;
    }

    /**
     * @return list<MiddlewareInterface|string>
     */
    private function resolveControllerMiddlewares(callable|array|string $handler): array
    {
        [$controller, $action] = $this->resolveControllerAction($handler);
        if ($controller === null || $action === null) {
            return [];
        }

        $configs = $this->controllerMiddlewares[$controller] ?? [];
        if ($configs === []) {
            return [];
        }

        $resolved = [];
        foreach ($configs as $config) {
            $only   = $config['only'] ?? [];
            $except = $config['except'] ?? [];

            if ($only !== [] && !in_array($action, $only, true)) {
                continue;
            }

            if ($except !== [] && in_array($action, $except, true)) {
                continue;
            }

            $middlewares = $config['middlewares'] ?? [];
            foreach ($middlewares as $middleware) {
                $resolved[] = $middleware;
            }
        }

        return $resolved;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveControllerAction(callable|array|string $handler): array
    {
        if (is_string($handler)) {
            return [$handler, '__invoke'];
        }

        if (is_array($handler) && count($handler) === 2) {
            [$controller, $action] = $handler;
            if (is_string($controller)) {
                return [$controller, is_string($action) ? $action : null];
            }

            if (is_object($controller)) {
                return [get_class($controller), is_string($action) ? $action : null];
            }
        }

        return [null, null];
    }
}
