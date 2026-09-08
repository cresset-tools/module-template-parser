<?php
declare(strict_types=1);

/**
 * Minimal stand-ins for the Magento and PSR types the src/Magento adapters reference, so
 * they stay unit-testable without a Magento installation. Only declared when the real
 * classes are absent, so this file is inert inside a real install.
 */
namespace Magento\Framework\View\Element {
    if (!interface_exists(BlockInterface::class)) {
        interface BlockInterface { public function toHtml(); }
    }
}
namespace Magento\Framework\View {
    if (!interface_exists(LayoutInterface::class)) {
        interface LayoutInterface { public function createBlock($type, $name = '', array $arguments = []); }
    }
}
namespace Magento\Framework\ObjectManager {
    if (!interface_exists(ConfigInterface::class)) {
        interface ConfigInterface {
            public function getInstanceType($instanceName);
            public function getPreference($type);
        }
    }
}
namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class)) {
        interface ScopeConfigInterface {
            public function getValue($path, $scope = 'default', $scopeCode = null);
            public function isSetFlag($path, $scope = 'default', $scopeCode = null);
        }
    }
}
namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface {
            public function emergency($message, array $context = []);
            public function alert($message, array $context = []);
            public function critical($message, array $context = []);
            public function error($message, array $context = []);
            public function warning($message, array $context = []);
            public function notice($message, array $context = []);
            public function info($message, array $context = []);
            public function debug($message, array $context = []);
            public function log($level, $message, array $context = []);
        }
    }
}
namespace {
    if (!function_exists('__')) {
        function __($text, ...$args) { return $text; }
    }
}
