<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * The directives a store has that this engine does not implement.
 *
 * Magento is extensible in two places the template language reaches, and both are easy to
 * miss because nothing in a stock install uses them:
 *
 * `SimpleDirective\ProcessorPool` registers a NAMED directive, so a module adding `mydir`
 * makes `{{mydir "v" p=1}}body{{/mydir}}` render on that store and nowhere else. An unknown
 * directive comes back verbatim here, which is exactly what the filter does on a store with no
 * such module - so nothing distinguishes "this store has no such directive" from "this store
 * has one and we ignored it" without asking the store.
 *
 * `FilterPool` registers a MODIFIER, and it is deliberately NOT reported. Measured on a store
 * that registers one: `{{var x|foofilter}}` renders `ab<c>` on the filter and `ab<c>` here -
 * the pool is never consulted for `{{var}}`, because `Email\Model\Template\Filter::varDirective`
 * uses its own `$_modifiers` map and skips a name that is not in it. Every surface this package
 * replaces - email, CMS and newsletter - inherits that override, so a FilterPool modifier only
 * ever reaches a SimpleDirective, and those this engine now renders itself. Reporting it as a
 * gap was a false positive: it warned about a difference that does not exist, on the strength
 * of reading a registry rather than measuring what consults it.
 *
 * It would matter to a host rendering through a bare `Framework\Filter\Template`, whose
 * `VarDirective` does go through the pool. Nothing here does, and if something ever should,
 * this is the note that says what to restore and why it was taken out.
 *
 * Reflection, for the same reason `LegacyRenderer` uses it on `templateVars`: the pool keeps
 * its contents in a private property with no getter, and `get($name)` answers only about a
 * name you already suspect. A pool this cannot read reports nothing rather than guessing.
 */
class HostExtensions
{
    public function __construct(private readonly MagentoContext $magento)
    {
    }

    /**
     * Directive names the store renders and this engine does not.
     *
     * @return string[]
     */
    public function directives(): array
    {
        $pool = $this->magento->get(\Magento\Framework\Filter\SimpleDirective\ProcessorPool::class);

        // Asked of the renderer rather than read here, so the tooling that REPORTS these names
        // is asking the same thing that renders them and the two cannot drift apart.
        return $pool === null
            ? []
            : (new \Cresset\TemplateParser\Magento\PoolCustomDirectiveRenderer($pool))->names();
    }

    /**
     * Whether this text spells `{{name}}` - the way the store matches it.
     *
     * A registered name immediately after `{{`, and not a longer name that merely starts with
     * it: the store's `[a-z]{0,10}` is greedy, so `{{mydir}}` is not `my`. Deliberately not
     * clever - a false positive costs a note nobody needed, and a false negative costs the
     * silence this exists to end.
     *
     * Static and public because `Auditor` asks the same question of a rendered OUTPUT rather
     * than a template, and the rule was spelled three times before it was one.
     */
    public static function mentions(string $text, string $name): bool
    {
        return preg_match('/\{\{' . preg_quote($name, '/') . '(?![a-z0-9_])/i', $text) === 1;
    }

    /**
     * The extensions a template actually uses, as `{{name}}` spellings.
     *
     * @return string[]
     */
    public function usedBy(string $template): array
    {
        $directives = [];
        foreach ($this->directives() as $name) {
            if (self::mentions($template, $name)) {
                $directives[] = $name;
            }
        }

        return $directives;
    }

    /**
     * One sentence naming what a template uses that the ENGINE cannot render, or null.
     *
     * @param string[] $rendered the directive names this engine has a handler for
     *
     * The second argument is why this is not simply "what the store has". Once the custom
     * directive port is wired the engine renders these itself, and warning about a directive
     * it just rendered correctly would be noise - worse, it would train a reader to ignore the
     * warning that still matters. A host that wires the port partially, or not at all, still
     * gets told.
     */
    public function noteFor(string $template, array $rendered = []): ?string
    {
        $directives = array_values(array_diff($this->usedBy($template), $rendered));
        if ($directives === []) {
            return null;
        }

        // Deliberately silent about WHAT this engine does with it, because that depends on
        // the shape: `{{mydir "v"}}` renders as its own text, while
        // `{{mydir}}x{{/mydir}}` is refused - the closing tag has no opener this engine
        // knows. Naming one outcome would be wrong half the time.
        return sprintf(
            'this template uses {{%s}}, which this store registers a directive processor for '
            . 'and this engine has no handler for',
            implode('}}, {{', $directives)
        );
    }
}
