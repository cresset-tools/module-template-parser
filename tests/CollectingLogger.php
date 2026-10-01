<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Psr\Log\LoggerInterface;

/** Keeps every record as [level, message, context]. */
final class CollectingLogger implements LoggerInterface
{
    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    public array $records = [];

    public function emergency($message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert($message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical($message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error($message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning($message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice($message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info($message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug($message, array $context = []): void { $this->log('debug', $message, $context); }

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [(string)$level, (string)$message, $context];
    }
}
