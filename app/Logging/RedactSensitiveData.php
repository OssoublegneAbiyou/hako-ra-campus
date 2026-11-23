<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $this->process($record));
    }

    public function process(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactText($record->message),
            context: $this->redactContext($record->context),
            extra: $this->redactContext($record->extra),
        );
    }

    private function redactText(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [masqué]', $value) ?? '[masqué]';

        return preg_replace(
            '/\b(password|passwd|secret|token|authorization|cookie|api[_-]?key|app[_-]?key)\s*[:=]\s*[^\s,;]+/i',
            '$1=[masqué]',
            $value,
        ) ?? '[masqué]';
    }

    /** @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|token|authorization|cookie|api.?key|app.?key|payload|body|email|check.?in|grade/i', $key)) {
                $context[$key] = '[masqué]';
            } elseif ($value instanceof Throwable) {
                $context[$key] = ['type' => $value::class, 'code' => $value->getCode()];
            } elseif (is_array($value)) {
                $context[$key] = $this->redactContext($value);
            } elseif (is_string($value)) {
                $context[$key] = $this->redactText($value);
            } elseif (is_object($value)) {
                $context[$key] = '[objet masqué]';
            }
        }

        return $context;
    }
}
