<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\ObjectManager\ConfigInterface;

/**
 * What a class name will turn into, without turning it into that.
 *
 * A type named in template text is attacker-influenced wherever the template is, so both
 * `{{block class=}}` and `{{widget type=}}` have to know what they are about to build before
 * they build it: checking the constructed instance is too late, an arbitrary constructor
 * having already run by then. The check has to go through the ObjectManager's own config,
 * because a preference or a virtual type means the name in the template is not the class
 * that gets instantiated.
 *
 * One class rather than one per caller. The route-parameter guard in this package was
 * written twice and the second copy missed a fix for a year, which is the argument.
 */
final class DeclaredType
{
    /**
     * Whether $name resolves to something implementing $interface.
     *
     * Fail-closed: a config that cannot answer is not a licence to instantiate, and a
     * resolved name that is neither a class nor an interface is not a type at all - the
     * config will happily return a virtual type's own name.
     */
    public static function resolvesTo(ConfigInterface $config, string $name, string $interface): bool
    {
        try {
            $resolved = $config->getInstanceType($config->getPreference($name));
        } catch (\Throwable) {
            return false;
        }

        return is_string($resolved)
            && (class_exists($resolved) || interface_exists($resolved))
            && is_a($resolved, $interface, true);
    }
}
