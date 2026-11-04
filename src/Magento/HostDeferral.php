<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * How the host's template filter hands a deferred directive up: to the root template, or to
 * whichever filter() call encloses the child that deferred it.
 *
 * Mage-OS 3.6.0 (and Magento's October 2026 security patch it ports) replaced signature-based
 * deferral with `Template\DeferredDirectiveRegistry`, which only the root template resolves.
 * The filter takes the registry as a constructor argument, and that argument is the signal
 * read here: it is the code path itself, where the class merely existing would not say the
 * filter uses it. Looked up by name and reflected once, so this loads on every Magento.
 *
 * Read by the Magento layer to set Options::$deferralResolvedAtRoot - see there for what
 * changes in rendering.
 */
final class HostDeferral
{
    public const REGISTRY = 'Magento\\Framework\\Filter\\Template\\DeferredDirectiveRegistry';

    public const FILTER = 'Magento\\Framework\\Filter\\Template';

    /** @var array<string,bool> */
    private static array $answers = [];

    /** Whether the host's filter resolves an include's deferred directives at the root. */
    public static function resolvesAtRoot(string $filterClass = self::FILTER, string $registryClass = self::REGISTRY): bool
    {
        return self::$answers[$filterClass . '|' . $registryClass] ??= self::takesRegistry($filterClass, $registryClass);
    }

    private static function takesRegistry(string $filterClass, string $registryClass): bool
    {
        try {
            if (!class_exists($filterClass)) {
                return false;
            }
            $constructor = (new \ReflectionClass($filterClass))->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType
                    && strcasecmp(ltrim($type->getName(), '\\'), $registryClass) === 0
                ) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // A filter that cannot be reflected tells us nothing. Answering "below the root"
            // is the cautious direction: it only ever declines more renders to the filter.
        }

        return false;
    }
}
