<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * A filter that can render a template a second time with the neutralizer marking instead of
 * encoding, so ShadowComparator can tell the deliberate difference from a filter without the
 * neutralizer from any other - see DeliberateDifference.
 *
 * Separate from TemplateFilterInterface so a substitute adapter need not implement it; without
 * it, every difference is reported as a divergence, as before.
 */
interface MarksNeutralizedOutput
{
    /**
     * The marked render of `$value` under the scope the last setVariables(), setPolicy(),
     * setPlainTemplateMode() and setDesignParams() set, and the work it deferred; null when it
     * did not render cleanly. Changes nothing the last filter() published.
     *
     * @return ?array{0:string,1:array<int,array{kind:string,payload:array}>}
     */
    public function filterMarked(string $value): ?array;
}
