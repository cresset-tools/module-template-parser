<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Source;

use MageOS\TemplateParser\Console\TemplateSubject;

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

    /** Why this source cannot be read here, or null when it can. Said to the person running the command. */
    public function unavailableReason(): ?string;

    /**
     * @return iterable<TemplateSubject>
     * @throws SourceUnavailable when the source fails partway, so the failure is not read as "empty"
     */
    public function subjects(): iterable;
}
