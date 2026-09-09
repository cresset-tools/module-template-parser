<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * One thing to check: where it came from, what it says, and what it renders with.
 *
 * Deliberately flat. A CMS block from the database, an .html file in a module, and a line
 * typed into the REPL are all the same shape by the time anything looks at them, so the
 * checking and diffing code has one case to handle rather than four.
 */
class TemplateSubject
{
    /**
     * Which pipeline renders this in production.
     *
     * Not decoration: an email goes through a template model that adds store variables and
     * design params, a CMS block through a filter that has neither. Rendering one as the
     * other is how a comparison ends up measuring the harness.
     */
    public const KIND_EMAIL = 'email';
    public const KIND_NEWSLETTER = 'newsletter';
    public const KIND_CMS = 'cms';

    /** @param array<string,mixed> $variables */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $origin,
        public readonly string $content,
        public readonly array $variables = [],
        public readonly ?int $storeId = null,
        public readonly string $kind = self::KIND_EMAIL,
    ) {
    }
}
