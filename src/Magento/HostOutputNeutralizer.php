<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Whether the host's template filter encodes `{{` in resolved directive output, as Mage-OS
 * 3.5.0's `Template\DirectiveOutputNeutralizer` does.
 *
 * This engine always encodes it. On a filter that does not, a render whose values contain
 * `{{` differs - deliberately, see DeliberateDifference - and the Magento layer uses this to
 * know when to look for that difference rather than report it as a divergence.
 *
 * Read the way HostDeferral reads the registry: the framework filter takes the neutralizer as
 * a constructor argument, which is the code path itself rather than a class that merely exists.
 * Looked up by name and reflected once, so this loads on every Magento.
 *
 * Only a POSITIVE reading says "no": a constructor that is the framework filter's - its first
 * argument the `StringUtils` every 2.x release takes - and that takes no neutralizer. Anything
 * this cannot read answers "encodes", the current filter, under which nothing is treated
 * differently - so a misreading can only ever report a deliberate difference as a divergence,
 * never hide a divergence on a current host.
 */
final class HostOutputNeutralizer
{
    public const NEUTRALIZER = 'Magento\\Framework\\Filter\\Template\\DirectiveOutputNeutralizer';

    public const FILTER = 'Magento\\Framework\\Filter\\Template';

    private const STRING_UTILS = 'Magento\\Framework\\Stdlib\\StringUtils';

    /** @var array<string,bool> */
    private static array $answers = [];

    /** False only for a filter positively read as one from before the neutralizer. */
    public static function encodesValues(string $filterClass = self::FILTER, string $neutralizerClass = self::NEUTRALIZER): bool
    {
        return self::$answers[$filterClass . '|' . $neutralizerClass] ??= !self::predatesNeutralizer($filterClass, $neutralizerClass);
    }

    private static function predatesNeutralizer(string $filterClass, string $neutralizerClass): bool
    {
        try {
            if (!class_exists($filterClass)) {
                return false;
            }
            $parameters = (new \ReflectionClass($filterClass))->getConstructor()?->getParameters() ?? [];
            if ($parameters === [] || !self::takes($parameters[0], self::STRING_UTILS)) {
                return false;
            }
            foreach ($parameters as $parameter) {
                if (self::takes($parameter, $neutralizerClass)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function takes(\ReflectionParameter $parameter, string $class): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && strcasecmp(ltrim($type->getName(), '\\'), $class) === 0;
    }
}
