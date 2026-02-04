<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\Uri;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PhpSoftBox\Router\Tests\Fixtures\DemoController;
use PhpSoftBox\Router\Tests\Utils\HeaderAppendMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Router::class)]
#[CoversMethod(Router::class, 'handle')]
#[CoversClass(Dispatcher::class)]
#[CoversMethod(Dispatcher::class, 'dispatch')]
#[CoversClass(RouteCollector::class)]
#[CoversMethod(RouteCollector::class, 'addRoute')]
#[CoversClass(RouteResolver::class)]
#[CoversMethod(RouteResolver::class, 'resolve')]
final class Psr15RouterTest extends TestCase
{
    /**
     * Проверим, что Router работает как PSR-15 RequestHandler: обрабатывает запрос и возвращает ответ без middleware.
     *
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     * @see RouteCollector::addRoute()
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function handleSimpleClosureHandler(): void
    {
        $collector = new RouteCollector();

        // Зарегистрируем простой маршрут без middleware
        $collector->get('/ping', function (ServerRequestInterface $request): ResponseInterface {
            // Вернём простой ответ, чтобы проверить сам факт обработки запроса
            return new Response(200, ['X-Ping' => 'pong'], 'pong');
        });

        // ...

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        // ...

        $request = new ServerRequest('GET', new Uri('http://example.com/ping'));

        $response = $router->handle($request);

        // Проверим статус, заголовки и тело
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', $response->getHeaderLine('X-Ping'));
        $this->assertSame('pong', (string) $response->getBody());
    }

    /**
     * Проверим, что middleware для контроллера применяются к нужному action.
     *
     * @see RouteCollector::addControllerMiddleware()
     */
    #[Test]
    public function appliesControllerMiddlewares(): void
    {
        $collector = new RouteCollector();

        $collector->addControllerMiddleware(DemoController::class, [new HeaderAppendMiddleware('C')], only: ['show']);
        $collector->get('/demo', [DemoController::class, 'show']);

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        $response = $router->handle(new ServerRequest('GET', new Uri('http://example.com/demo')));

        $this->assertSame('H-C', $response->getHeaderLine('X-Action'));
    }

    /**
     * Проверим порядок выполнения PSR-15 middleware (глобальные, групповые, локальные) и передачу изменённого запроса в хендлер.
     *
     * @see MiddlewareInterface::process()
     * @see RequestHandlerInterface::handle()
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     * @see RouteCollector::addRoute()
     * @see RouteCollector::group()
     * @see RouteCollector::addMiddleware()
     */
    #[Test]
    public function middlewarePipelineOrderAndRequestMutation(): void
    {
        $collector = new RouteCollector();

        // Глобальный middleware (M1)
        $collector->addMiddleware(new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $trace   = $request->getAttribute('trace', '');
                $request = $request->withAttribute('trace', $trace . 'M1>');

                return $handler->handle($request);
            }
        });

        // Группа с префиксом и middleware (M2)
        $collector->group('/api', function (RouteCollector $r): void {
            $r->get('/users', function (ServerRequestInterface $request): ResponseInterface {
                // Хендлер добавляет 'H' и возвращает его в заголовок X-Trace
                $trace = $request->getAttribute('trace', '') . 'H';

                return new Response(200, ['X-Trace' => $trace], 'ok');
            }, [
                // Локальный middleware (M3)
                new class () implements MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        $trace   = $request->getAttribute('trace', '');
                        $request = $request->withAttribute('trace', $trace . 'M3>');

                        return $handler->handle($request);
                    }
                },
            ]);
        }, [
            new class () implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $trace   = $request->getAttribute('trace', '');
                    $request = $request->withAttribute('trace', $trace . 'M2>');

                    return $handler->handle($request);
                }
            },
        ]);

        // ...

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        // ...

        $response = $router->handle(new ServerRequest('GET', new Uri('http://example.com/api/users')));

        // Проверим, что порядок «до вызова хендлера» соблюдён (M1 > M2 > M3 > H)
        $this->assertSame('M1>M2>M3>H', $response->getHeaderLine('X-Trace'));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверим, что middleware может завершить обработку (short-circuit) и до хендлера выполнение не дойдёт.
     *
     * @see MiddlewareInterface::process()
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function middlewareShortCircuitStopsHandler(): void
    {
        $collector = new RouteCollector();

        // Добавим два middleware: первый завершается сам (403), второй бы добавил заголовок, но он не должен выполниться
        $collector->get('/secure', function (): ResponseInterface {
            // Этот хендлер не должен быть вызван
            return new Response(200, [], 'should-not-run');
        }, [
            new class () implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    // Завершаем цепочку немедленно
                    return new Response(403, ['X-Reason' => 'denied'], 'denied');
                }
            },
            new class () implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    // Не должен вызываться
                    $resp = $handler->handle($request);

                    return $resp->withHeader('X-Should-Not-See', '1');
                }
            },
        ]);

        // ...

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        // ...

        $response = $router->handle(new ServerRequest('GET', new Uri('http://example.com/secure')));

        // Проверим, что вернулся 403, а заголовка от второго middleware нет
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('denied', (string) $response->getBody());
        $this->assertSame('', $response->getHeaderLine('X-Should-Not-See'));
        $this->assertSame('denied', $response->getHeaderLine('X-Reason'));
    }

    /**
     * Проверим, что контроллер в виде [Class, method] вызыв��ется и возвращает корректный Response, совместимый с PSR-15 пайплайном.
     *
     * @see DemoController::show()
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function controllerArrayHandlerIsInvoked(): void
    {
        $collector = new RouteCollector();

        // Добавим маршрут на контроллер
        $collector->get('/demo', [DemoController::class, 'show']);

        // ...

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        // ...

        $response = $router->handle(new ServerRequest('GET', new Uri('http://example.com/demo')));

        // Проверим, что контроллер действительно отработал
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('demo', (string) $response->getBody());
        $this->assertSame('DemoController::show', $response->getHeaderLine('X-Handler'));
    }
}
