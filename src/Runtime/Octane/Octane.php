<?php

namespace Laravel\Vapor\Runtime\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\MarshalsPsr7RequestsAndResponses;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Throwable;

class Octane implements Client
{
    use MarshalsPsr7RequestsAndResponses;

    /**
     * The octane app.
     *
     * @var \Laravel\Octane\Worker
     */
    protected static $app;

    /**
     * The octane worker.
     *
     * @var \Laravel\Octane\OctaneResponse
     */
    protected static $response;

    /**
     * The octane worker.
     *
     * @var \Laravel\Octane\Worker
     */
    protected static $worker;

    /**
     * Boots an octane worker instance.
     *
     * @param  string  $basePath
     * @return void
     */
    public static function boot($basePath)
    {
        static::$worker = tap(new Worker(
            new ApplicationFactory($basePath), new self)
        )->boot();
    }

    /**
     * @param  \Laravel\Octane\RequestContext  $request
     * @return \Laravel\Octane\OctaneResponse
     */
    public static function handle($request)
    {
        [$request, $context] = (new self)->marshalRequest($request);

        static::$worker->handle($request, $context);

        return tap(static::$response, static function () {
            static::$response = null;
        });
    }

    /**
     * Terminates an octane worker instance, if any.
     *
     * @return void
     */
    public static function terminate()
    {
        if (static::$worker) {
            static::$worker->terminate();

            static::$worker = null;
        }
    }

    /**
     * Gets the octane worker, if any.
     *
     * @return \Laravel\Octane\Worker|null
     */
    public static function worker()
    {
        return static::$worker;
    }

    /**
     * Marshal the given request context into an Illuminate request.
     *
     * @param  \Laravel\Octane\RequestContext  $context
     * @return array
     */
    public function marshalRequest(RequestContext $context): array
    {
        return [
            static::toHttpFoundationRequest($context->psr7Request),
            $context,
        ];
    }

    /**
     * Send the response to the server.
     *
     * @param  \Laravel\Octane\RequestContext  $context
     * @param  \Laravel\Octane\OctaneResponse  $response
     * @return void
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        static::$response = $response;
    }

    /**
     * Send an error message to the server.
     *
     * @param  \Throwable  $e
     * @param  \Illuminate\Foundation\Application  $app
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Octane\RequestContext  $context
     * @return void
     */
    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        fwrite(STDERR, $e->getMessage());
    }
}
