<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\JsonRpc;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Container;
use Hyperf\Di\MethodDefinitionCollector;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\ExceptionHandler\Formatter\DefaultFormatter;
use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\JsonRpc\CoreMiddleware;
use Hyperf\JsonRpc\DataFormatter;
use Hyperf\JsonRpc\Exception\Handler\HttpExceptionHandler;
use Hyperf\JsonRpc\JsonRpcTransporter;
use Hyperf\JsonRpc\PathGenerator;
use Hyperf\JsonRpc\ResponseBuilder;
use Hyperf\Logger\Logger;
use Hyperf\Rpc\Protocol;
use Hyperf\Rpc\ProtocolManager;
use Hyperf\RpcServer\Router\DispatcherFactory;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Context;
use Hyperf\Utils\Packer\JsonPacker;
use Hyperf\Utils\Serializer\SimpleNormalizer;
use HyperfTest\JsonRpc\Stub\CalculatorService;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class CoreMiddlewareTest extends TestCase
{
    public function testProcess()
    {
        $container = $this->createContainer();
        $router = $container->make(DispatcherFactory::class, [])->getRouter('jsonrpc');
        $router->addRoute('/CalculatorService/add', [
            CalculatorService::class, 'add',
        ]);
        $protocol = new Protocol($container, $container->get(ProtocolManager::class), 'jsonrpc');
        $middleware = new CoreMiddleware($container, $protocol, 'jsonrpc');
        $handler = \Mockery::mock(RequestHandlerInterface::class);
        $request = (new Request('POST', new Uri('/CalculatorService/add')))
            ->withParsedBody([1, 2]);
        Context::set(ResponseInterface::class, new Response());

        $request = $middleware->dispatch($request);
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
        $ret = json_decode((string) $response->getBody(), true);
        // var_export($ret);
        $this->assertArrayHasKey('result', $ret);
        $this->assertEquals(3, $ret['result']);
    }

    public function testException()
    {
        $container = $this->createContainer();
        $router = $container->make(DispatcherFactory::class, [])->getRouter('jsonrpc');
        $router->addRoute('/CalculatorService/divide', [
            CalculatorService::class, 'divide',
        ]);
        $protocol = new Protocol($container, $container->get(ProtocolManager::class), 'jsonrpc');
        $middleware = new CoreMiddleware($container, $protocol, 'jsonrpc');
        $handler = \Mockery::mock(RequestHandlerInterface::class);
        $request = (new Request('POST', new Uri('/CalculatorService/divide')))
            ->withParsedBody([3, 0]);
        Context::set(ResponseInterface::class, new Response());

        $request = $middleware->dispatch($request);

        try {
            $response = $middleware->process($request, $handler);
        } catch (\Throwable $exception) {
            $response = Context::get(ResponseInterface::class);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $ret = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $ret);
        $this->assertArraySubset([
            'code' => ResponseBuilder::SERVER_ERROR,
            'message' => 'Expected non-zero value of divider',
        ], $ret['error']);
    }

    public function testDefaultExceptionHandler()
    {
        $container = $this->createContainer();
        $router = $container->make(DispatcherFactory::class, [])->getRouter('jsonrpc');
        $router->addRoute('/CalculatorService/divide', [
            CalculatorService::class, 'divide',
        ]);
        $protocol = new Protocol($container, $container->get(ProtocolManager::class), 'jsonrpc');
        $middleware = new CoreMiddleware($container, $protocol, 'jsonrpc');
        $handler = \Mockery::mock(RequestHandlerInterface::class);
        $request = (new Request('POST', new Uri('/CalculatorService/divide')))
            ->withParsedBody([3, 0]);
        Context::set(ResponseInterface::class, new Response());

        $request = $middleware->dispatch($request);

        try {
            $response = $middleware->process($request, $handler);
        } catch (\Throwable $exception) {
            $logger = \Mockery::mock(StdoutLoggerInterface::class);
            $logger->shouldReceive('warning')->andReturn(null);
            $formatter = new DefaultFormatter();
            $handler = new HttpExceptionHandler($logger, $formatter);
            $response = $handler->handle($exception, Context::get(ResponseInterface::class));
        }

        $this->assertEquals(200, $response->getStatusCode());
        $ret = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $ret);
        $this->assertArraySubset([
            'code' => ResponseBuilder::SERVER_ERROR,
            'message' => 'Expected non-zero value of divider',
        ], $ret['error']);
    }

    public function createContainer()
    {
        $eventDispatcher = \Mockery::mock(EventDispatcherInterface::class);
        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(ConfigInterface::class)
            ->andReturn($config = new Config([
                'protocols' => [
                    'jsonrpc' => [
                        'packer' => JsonPacker::class,
                        'transporter' => JsonRpcTransporter::class,
                        'path-generator' => PathGenerator::class,
                        'data-formatter' => DataFormatter::class,
                    ],
                ],
            ]));
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->with(ProtocolManager::class)
            ->andReturn(new ProtocolManager($config));
        $container->shouldReceive('get')->with(NormalizerInterface::class)
            ->andReturn(new SimpleNormalizer());
        $container->shouldReceive('get')->with(MethodDefinitionCollectorInterface::class)
            ->andReturn(new MethodDefinitionCollector());
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)
            ->andReturn(new Logger('App', [new StreamHandler('php://stderr')]));
        $container->shouldReceive('get')->with(EventDispatcherInterface::class)
            ->andReturn($eventDispatcher);
        $container->shouldReceive('get')->with(PathGenerator::class)
            ->andReturn(new PathGenerator());
        $container->shouldReceive('get')->with(DataFormatter::class)
            ->andReturn(new DataFormatter());
        $container->shouldReceive('get')->with(JsonPacker::class)
            ->andReturn(new JsonPacker());
        $container->shouldReceive('get')->with(CalculatorService::class)
            ->andReturn(new CalculatorService());
        $container->shouldReceive('make')->with(DispatcherFactory::class, \Mockery::any())
            ->andReturn(new DispatcherFactory($eventDispatcher, new PathGenerator()));
        $container->shouldReceive('make')->with(ResponseBuilder::class, \Mockery::any())
            ->andReturnUsing(function ($class, $args) {
                return new ResponseBuilder(...array_values($args));
            });

        ApplicationContext::setContainer($container);
        return $container;
    }
}
