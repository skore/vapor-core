<?php

namespace Laravel\Vapor\Runtime;

use Illuminate\Support\Arr;

class Request
{
    /**
     * The request server variables.
     *
     * @var array
     */
    public $serverVariables;

    /**
     * The request body.
     *
     * @var string
     */
    public $body;

    /**
     * The request headers.
     *
     * @var array
     */
    public $headers;

    /**
     * Create a new request instance.
     *
     * @param  array  $serverVariables
     * @param  string  $body
     * @param  array  $headers
     * @return void
     */
    public function __construct(array $serverVariables, $body, $headers)
    {
        $this->body = $body;
        $this->serverVariables = $serverVariables;
        $this->headers = $headers;
    }

    /**
     * Create a new request from the given Lambda event.
     *
     * @param  array  $event
     * @param  array  $serverVariables
     * @param  string|null  $handler
     * @return static
     */
    public static function fromLambdaEvent(array $event, array $serverVariables = [], $handler = null)
    {
        [$uri, $queryString] = static::getUriAndQueryString($event);

        $headers = static::getHeaders($event);

        $requestBody = static::getRequestBody($event);

        $serverVariables = array_merge($serverVariables, [
            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
            'PATH_INFO' => $event['path'] ?? $event['requestContext']['http']['path'] ?? '/',
            'QUERY_STRING' => $queryString,
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => $headers['x-forwarded-port'] ?? 80,
            'REQUEST_METHOD' => $event['httpMethod'] ?? $event['requestContext']['http']['method'],
            'REQUEST_URI' => $uri,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => $headers['host'] ?? 'localhost',
            'SERVER_PORT' => $headers['x-forwarded-port'] ?? 80,
            'SERVER_PROTOCOL' => $event['requestContext']['protocol'] ?? $event['requestContext']['http']['protocol'] ?? 'HTTP/1.1',
            'SERVER_SOFTWARE' => 'vapor',
        ]);

        if ($handler) {
            $serverVariables['SCRIPT_FILENAME'] = $handler;
        }

        [$headers, $serverVariables] = static::ensureContentTypeIsSet(
            $event, $headers, $serverVariables
        );

        [$headers, $serverVariables] = static::ensureContentLengthIsSet(
            $event, $headers, $serverVariables, $requestBody
        );

        $headers = static::ensureSourceIpAddressIsSet(
            $event, $headers
        );

        foreach ($headers as $header => $value) {
            $serverVariables['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $value;
        }

        return new static($serverVariables, $requestBody, $headers);
    }

    /**
     * Get the URI and query string for the given event.
     *
     * @param  array  $event
     * @return array
     */
    protected static function getUriAndQueryString(array $event)
    {
        $uri = $event['requestContext']['http']['path'] ?? $event['path'] ?? '/';

        $queryString = self::getQueryString($event);

        return [
            empty($queryString) ? $uri : $uri.'?'.$queryString,
            $queryString,
        ];
    }

    /**
     * Get the query string from the event.
     *
     * @param  array  $event
     * @return string
     */
    protected static function getQueryString(array $event)
    {
        if (isset($event['version']) && $event['version'] === '2.0') {
            return self::buildQueryString(
                collect($event['queryStringParameters'] ?? [])
                ->mapWithKeys(function ($value, $key) {
                    $values = explode(',', $value);

                    return count($values) === 1
                        ? [$key => $values[0]]
                        : [(substr($key, -2) == '[]' ? substr($key, 0, -2) : $key) => $values];
                })->all()
            );
        }

        if (! isset($event['multiValueQueryStringParameters'])) {
            return self::buildQueryString(
                $event['queryStringParameters'] ?? []
            );
        }

        return self::buildQueryString(
            collect($event['multiValueQueryStringParameters'] ?? [])
                ->mapWithKeys(function ($values, $key) use ($event) {
                    $key = ! isset($event['requestContext']['elb']) ? $key : urldecode($key);

                    return count($values) === 1
                        ? [$key => $values[0]]
                        : [(substr($key, -2) == '[]' ? substr($key, 0, -2) : $key) => $values];
                })->map(function ($values) use ($event) {
                    if (! isset($event['requestContext']['elb'])) {
                        return $values;
                    }

                    return ! is_array($values) ? urldecode($values) : array_map(function ($value) {
                        return urldecode($value);
                    }, $values);
                })->all()
        );
    }

    /**
     * Get the request headers from the event.
     *
     * @param  array  $event
     * @return array
     */
    protected static function getHeaders(array $event)
    {
        if (! isset($event['multiValueHeaders'])) {
            return array_change_key_case(
                $event['headers'] ?? [], CASE_LOWER
            );
        }

        return array_change_key_case(
            collect($event['multiValueHeaders'] ?? [])
                ->mapWithKeys(function ($headers, $name) {
                    return [$name => Arr::last($headers)];
                })->all(), CASE_LOWER
        );
    }

    /**
     * Build query string from array of query parameters.
     *
     * @param array $query
     * @return string
     */
    protected static function buildQueryString(array $query)
    {
        $resultQuery = [];

        foreach ($query as $key => $value) {
            $value = (array) $value;

            array_walk_recursive($value, function ($value) use (&$resultQuery, $key) {
                $resultQuery[] = urlencode($key).'='.urlencode($value);
            });
        }

        return implode('&', $resultQuery);
    }

    /**
     * Get the request body from the event.
     *
     * @param  array  $event
     * @return string
     */
    protected static function getRequestBody(array $event)
    {
        $body = $event['body'] ?? '';

        return isset($event['isBase64Encoded']) && $event['isBase64Encoded']
            ? base64_decode($body)
            : $body;
    }

    /**
     * Ensure the request headers / server variables contain a content type.
     *
     * @param  array  $event
     * @param  array  $headers
     * @param  array  $serverVariables
     * @return array
     */
    protected static function ensureContentTypeIsSet(array $event, array $headers, array $serverVariables)
    {
        if ((! isset($headers['content-type']) && isset($event['httpMethod']) && (strtoupper($event['httpMethod']) === 'POST')) ||
            (! isset($headers['content-type']) && isset($event['requestContext']['http']['method']) && (strtoupper($event['requestContext']['http']['method']) === 'POST'))) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        if (isset($headers['content-type'])) {
            $serverVariables['CONTENT_TYPE'] = $headers['content-type'];
        }

        return [$headers, $serverVariables];
    }

    /**
     * Ensure the request headers / server variables contain a content length.
     *
     * @param  array  $event
     * @param  array  $headers
     * @param  array  $serverVariables
     * @param  string  $requestBody
     * @return array
     */
    protected static function ensureContentLengthIsSet(array $event, array $headers, array $serverVariables, $requestBody)
    {
        if ((! isset($headers['content-length']) && isset($event['httpMethod']) && ! in_array(strtoupper($event['httpMethod']), ['TRACE'])) ||
            (! isset($headers['content-length']) && isset($event['requestContext']['http']['method']) && ! in_array(strtoupper($event['requestContext']['http']['method']), ['TRACE']))) {
            $headers['content-length'] = strlen($requestBody);
        }

        if (isset($headers['content-length'])) {
            $serverVariables['CONTENT_LENGTH'] = $headers['content-length'];
        }

        return [$headers, $serverVariables];
    }

    /**
     * Ensure the request headers contain a source IP address.
     *
     * @param  array  $event
     * @param  array  $headers
     * @return array
     */
    protected static function ensureSourceIpAddressIsSet(array $event, array $headers)
    {
        if (isset($event['requestContext']['identity']['sourceIp'])) {
            $headers['x-vapor-source-ip'] = $event['requestContext']['identity']['sourceIp'];
        }

        if (isset($event['requestContext']['http']['sourceIp'])) {
            $headers['x-vapor-source-ip'] = $event['requestContext']['http']['sourceIp'];
        }

        return $headers;
    }
}
