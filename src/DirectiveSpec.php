<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Knows which directives take a body and a closing tag.
 *
 * This is the grammar the legacy regex could not express: `CONSTRUCTION_PATTERN` made the
 * closing tag optional and matched it with a backreference, so any directive could be
 * "closed" by any matching name - which is what let a stray {{/var}} consume across
 * unrelated constructs.
 */
final class DirectiveSpec
{
    /** Directives with a body: name => whether {{else}} is accepted. */
    private const BLOCK_DIRECTIVES = [
        'if' => true,
        'depend' => false,
        'for' => false,
    ];

    /**
     * Known self-closing directives, from the stock filter surface.
     *
     * `filter` was on this list and is not a directive. Magento has two extension points and
     * they are easy to confuse: SimpleDirective\ProcessorPool registers arbitrary NAMED
     * directives, so a module adding `mydir` gets `{{mydir}}`, while
     * DirectiveProcessor\Filter\FilterPool registers MODIFIERS, so one adding `foofilter`
     * gets `{{var x|foofilter}}`. There is no `{{filter}}` in either, and no stock template
     * uses one. Listing it made knownNames() claim a directive that does not exist, which is
     * what the diff notes and the render policy are built out of.
     *
     * Which leaves a real gap this engine does not close: a store whose module registered a
     * ProcessorPool directive has a `{{mydir}}` the filter renders and this leaves verbatim.
     * That needs a port and is a design decision, not a list entry.
     */
    private const VOID_DIRECTIVES = [
        'var', 'block', 'template', 'trans', 'inlinecss', 'layout', 'media', 'store',
        'config', 'customvar', 'protocol', 'view', 'widget', 'css', 'else',
    ];

    /** @var array<string,bool> */
    private array $blocks;

    /** @var array<string,true> */
    private array $voids;

    /**
     * @param array<string,bool> $extraBlocks   name => accepts {{else}}
     * @param string[] $extraVoids
     */
    public function __construct(array $extraBlocks = [], array $extraVoids = [])
    {
        $this->blocks = self::BLOCK_DIRECTIVES + $extraBlocks;
        $this->voids = array_fill_keys(array_merge(self::VOID_DIRECTIVES, $extraVoids), true);
    }

    public function isBlock(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    public function acceptsElse(string $name): bool
    {
        return $this->blocks[$name] ?? false;
    }

    /** @return string[] */
    public function knownNames(): array
    {
        return array_values(array_unique([...array_keys($this->blocks), ...array_keys($this->voids)]));
    }

    public function isKnown(string $name): bool
    {
        return isset($this->blocks[$name]) || isset($this->voids[$name]);
    }
}
