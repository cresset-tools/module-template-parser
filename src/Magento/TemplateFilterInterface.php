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

    public function filter(string $value): string;

    /** @return array<int,array{kind:string,payload:array}> deferred work from the last render */
    public function deferred(): array;

    /** @return \Cresset\TemplateParser\PolicyViolation[] refusals from the last render */
    public function violations(): array;

    /** @return \Cresset\TemplateParser\LegacyIncompatibility[] from the last render */
    public function incompatibilities(): array;
}
