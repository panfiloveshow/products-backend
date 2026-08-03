<?php

namespace Tests\Unit;

use App\Logging\ApplySensitiveDataProcessor;
use App\Logging\SensitiveDataProcessor;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SensitiveDataProcessorTest extends TestCase
{
    public function test_log_tap_attaches_the_processor_to_monolog(): void
    {
        $monolog = new Logger('security-test', [new NullHandler]);
        $logger = new LaravelLogger($monolog);

        (new ApplySensitiveDataProcessor)($logger);

        $attached = false;
        foreach ($monolog->getProcessors() as $processor) {
            $attached = $attached || $processor instanceof SensitiveDataProcessor;
        }

        $this->assertTrue($attached);
    }

    public function test_nested_and_json_encoded_credentials_are_redacted(): void
    {
        $processor = new SensitiveDataProcessor;
        $record = new LogRecord(
            new \DateTimeImmutable,
            'security-test',
            Level::Info,
            'external response',
            [
                'params' => [
                    'token' => 'nested-secret',
                    'workspace' => 101,
                ],
                'body' => '{"access_token":"json-secret","status":"ok"}',
            ],
        );

        $processed = $processor($record);

        $this->assertSame('<REDACTED>', $processed->context['params']['token']);
        $this->assertStringNotContainsString('json-secret', $processed->context['body']);
        $this->assertStringContainsString('<REDACTED>', $processed->context['body']);
        $this->assertSame(101, $processed->context['params']['workspace']);
    }
}
