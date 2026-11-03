<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\Interception\PluginListInterface;

/**
 * The `*Directive()` methods of a filter class that run code Magento does not ship.
 *
 * The filter dispatches `{{name}}` to `nameDirective()` by reflection; this engine renders its
 * own handlers and nothing else. So a directive method is only this engine's to render when
 * it is the stock one, unplugged. Two ways it is not:
 *
 * - {@see self::OWN}: declared outside Magento - a module added it, or overrode a stock one,
 *   on a filter subclass or a preference;
 * - {@see self::PLUGGED}: a stock method a module has configured a plugin on, so the store's
 *   render runs the plugin and this engine's does not.
 *
 * A generated Interceptor's declarations say nothing on their own. The on-demand generator -
 * developer mode, or default mode without `setup:di:compile` - redeclares EVERY public method
 * (`Interception\Code\Generator\Interceptor::isInterceptedMethod()`), and the compiled one
 * every method any plugin on the class names, in any area. A method an Interceptor declares
 * is therefore read as the one it stands in for - the parent's - and is plugged only if the
 * plugin list says so for the area this process runs in. Read alone, the declarations
 * classed all sixteen stock directives as legacy-only on an uncompiled store, and over a
 * module's preference subclass (`Vendor\...\Filter\Interceptor`) as the module's own.
 *
 * Used by the plugin, to decline what only the filter renders, and by `template:check` and
 * `template:diff`, to say so - one rule, so the two cannot drift apart.
 */
final class DirectiveMethods
{
    public const OWN = 'own';
    public const PLUGGED = 'plugged';

    /**
     * @param class-string $class the filter class as instantiated - an Interceptor or not
     * @param ?object $instance an instance of it: its own plugin list, which `___init()` sets,
     *        is the one it dispatches through, so it is asked first
     * @return array<string,array{kind:string,class:string,plugins:list<string>}> directive name
     *         (lowercase, as the store matches it) => what kind, the class whose method it is
     *         (the declaring class for OWN, the intercepted one for PLUGGED) and, for PLUGGED,
     *         the plugin names the list gives
     */
    public static function of(string $class, ?PluginListInterface $pluginList = null, ?object $instance = null): array
    {
        $original = self::originalClass($class);
        $intercepted = $original !== $class;
        [$list, $type] = $intercepted ? self::listOf($instance, $pluginList, $original) : [null, $original];

        $found = [];
        // Every method, whatever its visibility: legacy dispatches through
        // ReflectionClass::getMethod()->invokeArgs(), which reaches protected, private and
        // static ones too. Only names legacy can reach - its capture is `[a-z]{0,10}`.
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if (preg_match('/^([a-zA-Z]{1,10})Directive$/', $method->getName(), $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            $declaring = $method->getDeclaringClass()->getName();

            if ($intercepted && $declaring === $class) {
                $plugins = self::plugins($list, $type, $method->getName());
                if ($plugins !== null) {
                    $found[$name] = ['kind' => self::PLUGGED, 'class' => $original, 'plugins' => $plugins];
                    continue;
                }
                if (!method_exists($original, $method->getName())) {
                    continue;
                }
                $declaring = (new \ReflectionMethod($original, $method->getName()))->getDeclaringClass()->getName();
            }

            if (!str_starts_with($declaring, 'Magento\\')) {
                $found[$name] = ['kind' => self::OWN, 'class' => $declaring, 'plugins' => []];
            }
        }

        ksort($found);

        return $found;
    }

    /** The class a generated Interceptor stands in for; any other class is its own. */
    public static function originalClass(string $class): string
    {
        if (!str_ends_with($class, '\\Interceptor')) {
            return $class;
        }
        $parent = get_parent_class($class);

        return is_string($parent) && $class === $parent . '\\Interceptor' ? $parent : $class;
    }

    /**
     * The plugin list to ask and the type to ask it for.
     *
     * The instance's own first - its private `pluginList` and `subjectType`, set by
     * `___init()` - then the one given.
     *
     * @return array{0:?PluginListInterface,1:string}
     */
    private static function listOf(?object $instance, ?PluginListInterface $pluginList, string $original): array
    {
        if ($instance !== null) {
            [$own, $type] = (function (): array {
                return [
                    property_exists($this, 'pluginList') ? $this->pluginList : null,
                    property_exists($this, 'subjectType') ? $this->subjectType : null,
                ];
            })->call($instance);
            if ($own instanceof PluginListInterface) {
                return [$own, is_string($type) && $type !== '' ? $type : $original];
            }
        }

        return [$pluginList, $original];
    }

    /**
     * The plugins configured on a method, or null when there are none.
     *
     * With no plugin list to ask, or one that raises, an Interceptor-declared method is taken
     * as plugged: that is what the compiled generator's output means, and declining a render
     * the filter could have been asked for costs nothing a customer sees. Neither happens on a
     * store - every Interceptor's constructor runs `___init()`, and the plugin and the console
     * are built by the ObjectManager that has a list - but a guess the other way would serve
     * output without a plugin's change.
     *
     * Every plugin on the method, not only the first stretch of the chain: `getNext()` stops
     * at each around plugin, and is asked again from there, as `___callPlugins()` does.
     *
     * @return ?list<string>
     */
    private static function plugins(?PluginListInterface $list, string $type, string $method): ?array
    {
        if ($list === null) {
            return [];
        }

        try {
            $names = [];
            $code = '__self';
            $seen = [];
            while (($info = $list->getNext($type, $method, $code)) !== null && !isset($seen[$code])) {
                $seen[$code] = true;
                foreach (['before', 'after'] as $kind) {
                    foreach ((array)($info[$kind] ?? []) as $name) {
                        $names[] = (string)$name;
                    }
                }
                if (!isset($info['around'])) {
                    break;
                }
                $code = (string)$info['around'];
                $names[] = $code;
            }
        } catch (\Throwable) {
            return [];
        }

        if ($seen === []) {
            return null;
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
