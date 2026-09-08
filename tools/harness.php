<?php
declare(strict_types=1);

/**
 * Shared harness for the tools in this directory.
 *
 * The recorders run REAL Magento filter classes, so they need a Magento tree and the handful
 * of framework leaves those classes touch. Only the leaves are stubbed here; every line of
 * filter logic under test is Magento's own.
 *
 * Point MAGENTO_ROOT at a Magento or Mage-OS checkout:
 *
 *     MAGENTO_ROOT=/path/to/magento php tools/record-legacy.php
 *
 * Use a PRISTINE checkout. Recording against a tree with a fix applied turns the differential
 * into a comparison of two fixed engines, and it does so silently.
 */

namespace {
    $magentoRoot = getenv('MAGENTO_ROOT') ?: '';
    if ($magentoRoot === '') {
        fwrite(STDERR, "MAGENTO_ROOT is not set. Point it at a Magento or Mage-OS checkout:
"
            . "  MAGENTO_ROOT=/path/to/magento php " . ($_SERVER['argv'][0] ?? 'tools/...') . "
");
        exit(1);
    }
    $magentoRoot = rtrim($magentoRoot, '/');
    if (!is_dir($magentoRoot . '/lib/internal/Magento/Framework/Filter')) {
        fwrite(STDERR, "MAGENTO_ROOT=$magentoRoot does not look like a Magento tree:
"
            . "  lib/internal/Magento/Framework/Filter is missing
");
        exit(1);
    }

    /** The Magento tree being recorded against. */
    define('MROOT', $magentoRoot);

    /** This package's root, so tools need no knowledge of where they were invoked from. */
    define('PKGROOT', dirname(__DIR__));
}

namespace Laminas\Filter { interface FilterInterface { public function filter($value); } }

namespace Magento\Framework\ObjectManager { interface ResetAfterRequestInterface { public function _resetState(): void; } }
namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception {}
}
namespace Magento\Framework {
    class Phrase {
        private $t; private $a;
        public function __construct($t, array $a = []) { $this->t = $t; $this->a = $a; }
        public function render(): string { return (string)$this->t; }
        public function __toString(): string { return (string)$this->t; }
    }
    class Profiler { public static function start($n = null) {} public static function stop($n = null) {} }
}
namespace Magento\Framework\Stdlib {
    class StringUtils {
        public function split($v, $l = 1, $w = false, $t = false, $d = '') {
            return preg_split('//u', (string)$v, -1, PREG_SPLIT_NO_EMPTY);
        }
        public function strlen($v) { return mb_strlen((string)$v); }
        public function substr($v, $o, $l = null) { return mb_substr((string)$v, $o, $l); }
        public function cleanString($v) { return (string)$v; }
    }
}
namespace Magento\Framework\App {
    class ObjectManager {
        public static $registry = [];
        public static function getInstance() { return new self(); }
        public function get($type) {
            if (!isset(self::$registry[$type])) { throw new \RuntimeException("harness OM: no binding for $type"); }
            return self::$registry[$type];
        }
    }
}

// ---- canary: records block instantiation without constructing anything ----
namespace Harness {
    class Canary {
        public static $hits = [];
        public static function reset(): void { self::$hits = []; }
        public static function hit(string $class, array $args): void {
            self::$hits[] = ['class' => $class, 'args' => $args];
        }
    }
    class CanaryLayout {
        public function createBlock($type, $name = '', array $arguments = []) {
            Canary::hit((string)$type, $arguments);
            return new CanaryBlock();
        }
    }
    class CanaryBlock {
        private $d = [];
        public function setBlockParams($p) { return $this; }
        public function setDataUsingMethod($k, $v = null) { $this->d[$k] = $v; return $this; }
        public function hasData($k) { return isset($this->d[$k]); }
        public function getCacheKey() { return 'canary'; }
        public function toHtml() { return '[CANARY-BLOCK-RENDERED]'; }
    }
}

namespace Magento\Framework\Filter\Template\Tokenizer {
    // Magento generates these factories at build time.
    class VariableFactory { public function create(array $d = []) { return new Variable(); } }
    class ParameterFactory { public function create(array $d = []) { return new Parameter(); } }
}
