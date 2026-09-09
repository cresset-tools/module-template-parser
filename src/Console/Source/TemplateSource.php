<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Source;

use Cresset\TemplateParser\Console\TemplateSubject;

/**
 * Somewhere templates live.
 *
 * The two that matter are opposites: the codebase can be audited before a deploy and is the
 * same for every merchant, while the database holds whatever a merchant typed and can only
 * be audited in place. A migration is sized by the second, not the first.
 */
interface TemplateSource
{
    public function name(): string;

    public function isAvailable(): bool;

    /** @return iterable<TemplateSubject> */
    public function subjects(): iterable;
}
