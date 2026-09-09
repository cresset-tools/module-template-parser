<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * Something worth telling a merchant about one template.
 *
 * Severity is about what it costs them, not about which class raised it. A template that
 * will not render is an error; one that renders differently from today is a warning, because
 * it still sends; one this engine refuses on purpose is a note.
 */
final class Finding
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTE = 'note';

    public function __construct(
        public readonly string $severity,
        public readonly TemplateSubject $subject,
        public readonly string $summary,
        public readonly ?string $detail = null,
        public readonly ?string $fix = null,
        public readonly ?int $line = null,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }
}
