<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\RenderPolicy;

/**
 * The shape the Magento integration renders through.
 *
 * An interface rather than a concrete class so an integrator can substitute their own via a
 * DI preference, and so the plugin depends on a contract rather than on a concrete type.
 */
interface TemplateFilterInterface
{
    /** @param array<string,mixed> $variables */
    public function setVariables(array $variables): static;

    /** Narrows what the next render may do. Null restores the configured default. */
    public function setPolicy(?RenderPolicy $policy): static;

    /**
     * The next render is the PLAIN part of an email.
     *
     * Named as Framework\Filter\Template names it, because AbstractTemplate::
     * getProcessedTemplate() calls exactly this on whatever filter it is given - so a host
     * that swaps this engine in behind that call reaches this method without knowing it.
     * {{customvar}} then reads a variable's text value rather than its HTML one, and {{css}}
     * and {{inlinecss}} render nothing.
     */
    public function setPlainTemplateMode(bool $plain): static;

    public function filter(string $value): string;

    /** @return array<int,array{kind:string,payload:array}> deferred work from the last render */
    public function deferred(): array;

    /** @return \Cresset\TemplateParser\PolicyViolation[] refusals from the last render */
    public function violations(): array;

    /** @return \Cresset\TemplateParser\LegacyIncompatibility[] from the last render */
    public function incompatibilities(): array;
}
