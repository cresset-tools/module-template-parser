<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * The directives and modifiers a store has that this engine does not implement.
 *
 * Magento is extensible in two places the template language reaches, and both are easy to
 * miss because nothing in a stock install uses them:
 *
 *   - `SimpleDirective\ProcessorPool` registers a NAMED directive, so a module adding `mydir`
 *     makes `{{mydir "v" p=1}}body{{/mydir}}` render on that store.
 *   - `DirectiveProcessor\Filter\FilterPool` registers a MODIFIER, so one adding `foofilter`
 *     makes `{{var x|foofilter}}` render.
 *
 * This engine implements neither mechanism, and an unknown directive comes back verbatim
 * while an unknown modifier is skipped - both silently, both identical to what the filter
 * does on a store that has no such extension. So the difference is invisible unless someone
 * asks the store what it has, which is what this does.
 *
 * Reflection, for the same reason `LegacyRenderer` uses it on `templateVars`: both pools keep
 * their contents in a private property with no getter, and `get($name)` answers only about a
 * name you already suspect. A pool this cannot read reports nothing rather than guessing.
 */
class HostExtensions
{
    /** Modifiers this engine implements itself, so a pool entry for one of these is no gap. */
    private const IMPLEMENTED_MODIFIERS = ['escape', 'nl2br', 'raw'];

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
     * Modifier names the store applies and this engine skips.
     *
     * @return string[]
     */
    public function modifiers(): array
    {
        return array_values(array_diff(
            $this->namesIn(\Magento\Framework\Filter\DirectiveProcessor\Filter\FilterPool::class, 'filters'),
            self::IMPLEMENTED_MODIFIERS
        ));
    }

    /**
     * The extensions a template actually uses, as `{{name}}` / `|name` spellings.
     *
     * Matched the way the store matches them: a directive name is `[a-z]+` immediately after
     * `{{`, and a modifier follows a `|` inside a construct. Deliberately not clever - a
     * false positive costs a note nobody needed, and a false negative costs the silence this
     * exists to end.
     *
     * @return array{directives:string[],modifiers:string[]}
     */
    public function usedBy(string $template): array
    {
        $directives = [];
        foreach ($this->directives() as $name) {
            if (preg_match('/\{\{' . preg_quote($name, '/') . '(?![a-z0-9_])/i', $template) === 1) {
                $directives[] = $name;
            }
        }

        $modifiers = [];
        foreach ($this->modifiers() as $name) {
            if (preg_match('/\|\s*' . preg_quote($name, '/') . '(?![a-z0-9_])/i', $template) === 1) {
                $modifiers[] = $name;
            }
        }

        return ['directives' => $directives, 'modifiers' => $modifiers];
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
        ['directives' => $directives, 'modifiers' => $modifiers] = $this->usedBy($template);
        $directives = array_values(array_diff($directives, $rendered));

        $parts = [];
        if ($directives !== []) {
            // Deliberately silent about WHAT this engine does with it, because that depends on
            // the shape: `{{mydir "v"}}` renders as its own text, while
            // `{{mydir}}x{{/mydir}}` is refused - the closing tag has no opener this engine
            // knows. Naming one outcome would be wrong half the time.
            $parts[] = sprintf(
                '{{%s}}, which this store registers a directive processor for and this engine '
                . 'does not implement',
                implode('}}, {{', $directives)
            );
        }
        if ($modifiers !== []) {
            $parts[] = sprintf(
                '|%s, which this store registers a filter for and this engine skips',
                implode(', |', $modifiers)
            );
        }

        return $parts === [] ? null : 'this template uses ' . implode('; and ', $parts);
    }

    /**
     * @param class-string $class
     * @return string[]
     */
    private function namesIn(string $class, string $property): array
    {
        $pool = $this->magento->get($class);
        if ($pool === null) {
            return [];
        }

        try {
            $registered = (new \ReflectionProperty($class, $property))->getValue($pool);
        } catch (\Throwable) {
            // A tree whose pool is shaped differently tells us nothing, which is not the same
            // as telling us there is nothing. Reporting no extension is the safe direction:
            // it under-claims rather than inventing a name.
            return [];
        }

        if (!is_array($registered)) {
            return [];
        }

        $names = array_filter(array_keys($registered), 'is_string');
        sort($names);

        return $names;
    }
}
