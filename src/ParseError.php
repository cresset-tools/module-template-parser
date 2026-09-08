<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

final class ParseError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $offset = 0)
    {
        parent::__construct($message . ' at offset ' . $offset);
    }
}
