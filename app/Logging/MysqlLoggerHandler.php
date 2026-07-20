<?php

namespace App\Logging;

use App\Models\Log as LogModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const REDACTED = '[REDACTED]';

    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try {
            $request = $this->currentRequest();
            $requestData = array_key_exists('request_data', $record)
                ? $record['request_data']
                : ($request ? $request->all() : []);
            $uri = isset($record['request_uri'])
                ? (string)$record['request_uri']
                : ($request ? $request->getRequestUri() : '');
            $datetime = isset($record['datetime']) ? $record['datetime'] : null;
            $timestamp = $datetime instanceof \DateTimeInterface
                ? $datetime->getTimestamp()
                : strtotime((string)$datetime);

            $log = [
                'title' => isset($record['message']) ? (string)$record['message'] : '',
                'level' => isset($record['level_name']) ? (string)$record['level_name'] : 'UNKNOWN',
                'host' => isset($record['request_host'])
                    ? (string)$record['request_host']
                    : ($request ? $request->getSchemeAndHttpHost() : ''),
                'uri' => $this->sanitizeUri($uri),
                'method' => isset($record['request_method'])
                    ? (string)$record['request_method']
                    : ($request ? $request->getMethod() : ''),
                'ip' => $request ? (string)$request->getClientIp() : '',
                'data' => $this->encode($this->sanitize($requestData)),
                'context' => $this->encode($this->sanitize(isset($record['context']) ? $record['context'] : [])),
                'created_at' => $timestamp ?: time(),
                'updated_at' => $timestamp ?: time(),
            ];

            LogModel::insert($log);
        } catch (\Throwable $e) {
            // Avoid recursively serializing a failed log record or exception trace.
            Log::channel('daily')->error(sprintf(
                'Mysql logger failed: %s: %s',
                get_class($e),
                $e->getMessage()
            ));
        }
    }

    private function currentRequest()
    {
        try {
            if (app()->runningInConsole() || !app()->bound('request')) {
                return null;
            }

            $request = app('request');
            return $request instanceof Request ? $request : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function sanitize($value, $key = null, $depth = 0)
    {
        if ($key !== null && $this->isSensitiveKey((string)$key)) {
            return self::REDACTED;
        }
        if ($depth >= 12) {
            return '[MAX_DEPTH]';
        }
        if ($value instanceof \Throwable) {
            return [
                'class' => get_class($value),
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitize($childValue, $childKey, $depth + 1);
            }
            return $sanitized;
        }
        if (is_object($value)) {
            return ['class' => get_class($value)];
        }
        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        return $value;
    }

    private function isSensitiveKey($key)
    {
        $key = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key));
        if ($key === '') {
            return false;
        }

        return (bool)preg_match(
            '/password|authorization|authdata|token|secret|privatekey|apikey|clientkey|emailcode|giftcard|signature/',
            $key
        );
    }

    private function sanitizeUri($uri)
    {
        if ($uri === '' || strpos($uri, '?') === false) {
            return $uri;
        }

        list($base, $queryString) = array_pad(explode('?', $uri, 2), 2, '');
        $fragment = '';
        if (strpos($queryString, '#') !== false) {
            list($queryString, $fragmentValue) = explode('#', $queryString, 2);
            $fragment = '#' . $fragmentValue;
        }

        parse_str($queryString, $query);
        $query = $this->sanitize($query);
        $safeQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $base . ($safeQuery !== '' ? '?' . $safeQuery : '') . $fragment;
    }

    private function encode($value)
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $encoded === false ? '{}' : $encoded;
    }
}
