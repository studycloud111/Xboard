<?php
namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;
use Monolog\LogRecord;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const MAX_VARCHAR_255 = 255;
    private const MAX_METHOD_LEN = 11;
    private const MAX_IP_LEN = 128;
    private const MAX_TEXT_BYTES = 60000;
    private const MAX_TRACE_CHARS = 20000;
    private const MAX_STRING_CHARS = 4000;
    private const MAX_ARRAY_ITEMS = 100;
    private const MAX_NESTING_DEPTH = 6;

    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $record = $record->toArray();
        try {
            if (!isset($record['context']) || !is_array($record['context'])) {
                $record['context'] = [];
            }

            if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                $exception = $record['context']['exception'];
                $record['context']['exception'] = $this->normalizeException($exception);
            }

            $request = request();
            $requestData = $this->compactValue($request->all());
            $record['context']['_meta'] = array_filter([
                'user_id' => $request->user()?->id,
                'path' => $request->path(),
            ], fn($value) => $value !== null && $value !== '');

            $log = [
                'title' => $this->truncateString((string) ($record['message'] ?? ''), self::MAX_STRING_CHARS),
                'level' => $record['level_name'],
                'host' => $this->truncateString((string) ($record['extra']['request_host'] ?? $request->getSchemeAndHttpHost()), self::MAX_VARCHAR_255),
                'uri' => $this->truncateString((string) ($record['extra']['request_uri'] ?? $request->getRequestUri()), self::MAX_VARCHAR_255),
                'method' => $this->truncateString((string) ($record['extra']['request_method'] ?? $request->getMethod()), self::MAX_METHOD_LEN),
                'ip' => $this->truncateString((string) $request->getClientIp(), self::MAX_IP_LEN),
                'data' => $this->encodeJsonForDb($requestData),
                'context' => $this->encodeJsonForDb($this->compactValue($record['context'])),
                'created_at' => $record['datetime']->getTimestamp(),
                'updated_at' => $record['datetime']->getTimestamp(),
            ];

            try {
                LogModel::insert($log);
            } catch (\Throwable $e) {
                // Attempt a minimal insert to surface the failure in the admin UI.
                try {
                    LogModel::insert([
                        'title' => $this->truncateString('MysqlLoggerHandler insert failed: ' . $e->getMessage(), self::MAX_STRING_CHARS),
                        'level' => 'ERROR',
                        'host' => $log['host'],
                        'uri' => $log['uri'],
                        'method' => $log['method'],
                        'ip' => $log['ip'],
                        'data' => null,
                        'context' => $this->encodeJsonForDb([
                            'exception' => $this->normalizeException($e),
                            '_meta' => $record['context']['_meta'] ?? null,
                        ]),
                        'created_at' => $log['created_at'],
                        'updated_at' => $log['updated_at'],
                    ]);
                } catch (\Throwable $ignored) {
                    // ignore
                }

                // Fallback to file to avoid completely losing the error.
                Log::channel('backup')->error('MysqlLoggerHandler failed', [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ],
                    'log' => [
                        'level' => $log['level'] ?? null,
                        'host' => $log['host'] ?? null,
                        'uri' => $log['uri'] ?? null,
                        'method' => $log['method'] ?? null,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('backup')->error('MysqlLoggerHandler write failed', [
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
        }
    }

    private function normalizeException(\Throwable $exception): array
    {
        return [
            "\0*\0message" => $exception->getMessage(),
            "\0*\0code" => $exception->getCode(),
            "\0*\0file" => $exception->getFile(),
            "\0*\0line" => $exception->getLine(),
            "\0*\0traceAsString" => Str::limit($exception->getTraceAsString(), self::MAX_TRACE_CHARS, "\n...(truncated)"),
        ];
    }

    private function compactValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_NESTING_DEPTH) {
            return '[depth-truncated]';
        }

        if ($value instanceof \Illuminate\Http\UploadedFile || $value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'mime' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value)) {
            return Str::limit($value, self::MAX_STRING_CHARS, '...(truncated)');
        }

        if (is_numeric($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= self::MAX_ARRAY_ITEMS) {
                    $result['__truncated__'] = true;
                    break;
                }
                $result[$key] = $this->compactValue($item, $depth + 1);
                $count++;
            }
            return $result;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->compactValue($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \Stringable) {
            return Str::limit((string) $value, self::MAX_STRING_CHARS, '...(truncated)');
        }

        if (is_object($value)) {
            return [
                '__class__' => get_class($value),
            ];
        }

        return (string) $value;
    }

    private function encodeJsonForDb(mixed $value): ?string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false) {
            $json = json_encode([
                '__json_error__' => json_last_error_msg(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($json === false) {
            return null;
        }

        if (strlen($json) <= self::MAX_TEXT_BYTES) {
            return $json;
        }

        // Keep the fallback JSON small and valid (the admin UI expects JSON.parse to succeed).
        $preview = substr($json, 0, 8000);
        return json_encode([
            '__truncated__' => true,
            '__bytes__' => strlen($json),
            'preview' => $preview,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function truncateString(string $value, int $maxLen): string
    {
        if ($maxLen <= 0) {
            return '';
        }
        if (Str::length($value) <= $maxLen) {
            return $value;
        }
        return Str::substr($value, 0, $maxLen);
    }
}
