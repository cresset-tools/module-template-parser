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
     * `filter` was on this list and is not a directive: there is no `{{filter}}` in either of
     * Magento's two extension points - see Port\CustomDirectiveRenderer for what they are -
     * and no stock template uses one. Listing it made knownNames() claim a directive that does
     * not exist, which is what the diff notes and the render policy are built out of.
     *
     * A ProcessorPool directive is the third kind, and is declared through $extraOptional
     * rather than here - see isOptionalBlock().
     */
    private const VOID_DIRECTIVES = [
        'var', 'block', 'template', 'trans', 'inlinecss', 'layout', 'media', 'store',
        'config', 'customvar', 'protocol', 'view', 'widget', 'css', 'else',
    ];

    /** @var array<string,bool> */
    private array $blocks;

    /** @var array<string,true> */
    private array $voids;

    /** @var array<string,true> */
    private array $optional;

    /**
     * @param array<string,bool> $extraBlocks   name => accepts {{else}}
     * @param string[] $extraVoids
     * @param string[] $extraOptional names that are a block only when they are closed
     */
    public function __construct(array $extraBlocks = [], array $extraVoids = [], array $extraOptional = [])
    {
        $this->blocks = self::BLOCK_DIRECTIVES + $extraBlocks;
        $this->voids = array_fill_keys(array_merge(self::VOID_DIRECTIVES, $extraVoids), true);
        $this->optional = array_fill_keys($extraOptional, true);
    }

    public function isBlock(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /**
     * A name that is a block when it is closed and a void directive when it is not.
     *
     * The third kind, and it exists because Magento's `SimpleDirective` has only one shape for
     * both: its pattern ends `(?:(?P<content>.*?){{\/(?P=directiveName)}})?`, an OPTIONAL
     * lazily-matched body. So a module that registers `mydir` gets `{{mydir "v"}}` and
     * `{{mydir}}body{{/mydir}}` from the same registration, and a template may use either -
     * which neither of the other two kinds can express. `{{if}}` without its closer is an
     * error and `{{var}}` with one is a stray tag; this is neither.
     *
     * Lazily, and that matters: the body runs to the FIRST matching close, not a balanced one,
     * so a second `{{/mydir}}` further along belongs to nobody.
     */
    public function isOptionalBlock(string $name): bool
    {
        return isset($this->optional[$name]);
    }

    public function acceptsElse(string $name): bool
    {
        return $this->blocks[$name] ?? false;
    }

    /** @return string[] */
    public function knownNames(): array
    {
        return array_values(array_unique([
            ...array_keys($this->blocks),
            ...array_keys($this->voids),
            ...array_keys($this->optional),
        ]));
    }

    public function isKnown(string $name): bool
    {
        return isset($this->blocks[$name]) || isset($this->voids[$name]) || isset($this->optional[$name]);
    }
}
