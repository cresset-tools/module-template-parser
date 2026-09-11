<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Port\CustomDirectiveRenderer;
use Magento\Framework\Filter\DirectiveProcessor\Filter\FilterPool;
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;

/**
 * {{mydir}} through the store's own `SimpleDirective\ProcessorPool`.
 *
 * The processors belong to whichever module registered them, so this reproduces
 * `SimpleDirective::process()` rather than reimplementing anything: look the name up, hand it
 * the value, the parameters and the rendered body, then apply modifiers.
 *
 * The modifier rule is the part worth stating, because it is not what anyone expects. A
 * template that names ANY modifier suppresses the processor's own defaults, so
 * `{{mydir "v"|raw}}` applies nothing at all - `raw` is not in the FilterPool and an unknown
 * filter is skipped - and comes out UNFILTERED, while `{{mydir "v"}}` comes out through
 * whatever `getDefaultFilters()` returns. That is `FilterApplier::applyFromRawParam()`, and it
 * is reproduced here rather than approximated.
 */
class PoolCustomDirectiveRenderer implements CustomDirectiveRenderer
{
    /** @var string[]|null */
    private ?array $names = null;

    public function __construct(
        private readonly ProcessorPool $processors,
        private readonly ?FilterPool $filters = null,
    ) {
    }

    /**
     * What this store's pool holds.
     *
     * Reflection, because the pool keeps its contents in a private array and `get($name)`
     * answers only about a name you already suspect - there is no way to ask it what it has.
     * The array is keyed by directive name, which is the whole answer.
     *
     * Cached: these decide what the PARSER treats as a directive, so they must not change
     * between the spec being built and a template being read.
     *
     * @return string[]
     */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        try {
            $registered = (new \ReflectionProperty(ProcessorPool::class, 'processors'))
                ->getValue($this->processors);
        } catch (\Throwable) {
            // A pool shaped differently tells us nothing, which is not the same as telling us
            // there is nothing. Claiming no directives is the safe direction: the engine then
            // leaves `{{mydir}}` alone, which is what it did before any of this existed.
            return $this->names = [];
        }

        $names = is_array($registered) ? array_filter(array_keys($registered), 'is_string') : [];
        sort($names);

        return $this->names = $names;
    }

    /** @param array<string,string> $parameters @param string[] $modifiers */
    public function render(
        string $name,
        ?string $value,
        array $parameters,
        ?string $body,
        array $modifiers
    ): ?string {
        try {
            $processor = $this->processors->get($name);
        } catch (\Throwable) {
            // The pool changed under us, or the name was never really there. The filter
            // returns the construct verbatim in that case; this engine has already consumed
            // it as a directive, so the closest honest answer is nothing.
            return null;
        }

        try {
            // `!empty($construction['content']) ? ... : null` - an EMPTY body reaches the
            // processor as null, not as '', because that is the test the filter makes.
            $rendered = $processor->process($value, $parameters, $body === '' ? null : $body);
        } catch (\Throwable) {
            return null;
        }

        return $this->applyModifiers((string)$rendered, $modifiers, $processor);
    }

    /** @param string[] $modifiers */
    private function applyModifiers(string $value, array $modifiers, object $processor): string
    {
        if ($modifiers === []) {
            try {
                $modifiers = $processor->getDefaultFilters() ?? [];
            } catch (\Throwable) {
                $modifiers = [];
            }
        }

        if ($this->filters === null) {
            return $value;
        }

        foreach (array_filter($modifiers) as $modifier) {
            // `escape:html` - the name, then colon-separated arguments.
            $arguments = explode(':', (string)$modifier);
            $filterName = array_shift($arguments);

            try {
                $filter = $this->filters->get((string)$filterName);
            } catch (\Throwable) {
                continue;                    // an unknown filter is skipped, as the pool does
            }

            try {
                $value = $filter->filterValue($value, $arguments);
            } catch (\Throwable) {
                // A filter that raises leaves the value as it stood rather than losing the
                // whole render, which is the posture every other port here takes.
                return $value;
            }
        }

        return $value;
    }
}
