<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * The Magento installation the tool is pointed at, if there is one.
 *
 * Two ways in, because the tool has two homes. Standalone it finds app/etc/env.php by walking
 * up from the working directory and boots Magento itself; inside n98-magerun2 the application
 * is already booted and hands over its ObjectManager, so booting again would be wrong. Both
 * end up behind `get()`, which is the only way anything downstream asks for a class.
 *
 * Absent a store, this is still constructible - `detect()` returns an unavailable context
 * rather than failing, so the REPL and the syntax checks work on a laptop with no Magento
 * anywhere near them.
 */
class MagentoContext
{
    private bool $areaSet = false;

    private function __construct(
        private readonly ?object $objectManager,
        private readonly ?string $root,
        private readonly ?string $reason,
    ) {
    }

    /** Wraps an ObjectManager an embedding application has already booted. */
    public static function fromObjectManager(object $objectManager, ?string $root = null): self
    {
        return new self($objectManager, $root, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, null, $reason);
    }

    /**
     * Finds a Magento root at or above $directory and boots it.
     *
     * Booting is deliberately lazy and guarded: a store that cannot boot - no database, a
     * half-finished install - must degrade to "no Magento" with a reason, not take the tool
     * down. Plenty of what this tool does needs no store at all.
     */
    public static function detect(?string $directory = null): self
    {
        $root = self::findRoot($directory ?? getcwd() ?: '.');
        if ($root === null) {
            return self::unavailable('no app/etc/env.php found at or above the working directory');
        }

        $autoload = $root . '/app/bootstrap.php';
        if (!is_file($autoload)) {
            return self::unavailable(sprintf('%s has no app/bootstrap.php', $root));
        }

        try {
            require_once $autoload;

            /** @var class-string $bootstrapClass */
            $bootstrapClass = 'Magento\\Framework\\App\\Bootstrap';
            if (!class_exists($bootstrapClass)) {
                return self::unavailable('Magento\\Framework\\App\\Bootstrap did not load');
            }

            $bootstrap = $bootstrapClass::create(BP, $_SERVER);

            return new self($bootstrap->getObjectManager(), $root, null);
        } catch (\Throwable $e) {
            return self::unavailable(sprintf('%s: %s', $e::class, $e->getMessage()));
        }
    }

    public function isAvailable(): bool
    {
        return $this->objectManager !== null;
    }

    public function root(): ?string
    {
        return $this->root;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Sets an area code, if nothing has set one yet.
     *
     * A CLI process has no area, and Magento refuses to build a layout without one - so
     * {{block}}, {{widget}} and {{layout}} all fail to wire with "Area code is not set",
     * which looks like the ports are missing rather than like the environment is incomplete.
     * Frontend is the right default: it is the area transactional email renders in.
     *
     * Idempotent, and quiet when the host already chose - n98-magerun2 sets its own.
     */
    public function ensureAreaCode(string $area = 'frontend'): void
    {
        if ($this->objectManager === null || $this->areaSet) {
            return;
        }
        $this->areaSet = true;

        try {
            $state = $this->objectManager->get(\Magento\Framework\App\State::class);
            try {
                $state->getAreaCode();
                return;                  // already set by whoever booted us
            } catch (\Throwable) {
                $state->setAreaCode($area);
            }
        } catch (\Throwable) {
            // An install too broken to set an area is one where blocks were never going to
            // render; the rest of the tool still works.
        }
    }

    /** Resolves a class from the object manager, or null when it or Magento is absent. */
    public function get(string $class): ?object
    {
        if ($this->objectManager === null) {
            return null;
        }

        try {
            /** @phpstan-ignore-next-line the OM is duck-typed here so this file stays loadable without Magento */
            return $this->objectManager->get($class);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function findRoot(string $directory): ?string
    {
        $directory = realpath($directory) ?: $directory;

        while ($directory !== '' && $directory !== '/' && $directory !== '.') {
            if (is_file($directory . '/app/etc/env.php')) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return null;
    }
}
