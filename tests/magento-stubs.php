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
namespace Magento\Widget\Block {
    if (!interface_exists(BlockInterface::class)) {
        interface BlockInterface { public function toHtml(); }
    }
}
namespace Magento\Store\Model {
    if (!interface_exists(ScopeInterface::class)) {
        interface ScopeInterface { public const SCOPE_STORE = 'store'; }
    }
    // TemplateModelUrlBuilder checks the receiver and the store by class, so exercising the
    // shipped implementation rather than a fake needs both to exist.
    if (!class_exists(Store::class)) {
        class Store {}
    }
}
namespace Magento\Email\Model\Template\Filter {
    // Mage-OS's deny list for the {{block}} directive. LayoutBlockRenderer is typed against it
    // concretely, because a compiled DI factory resolves an argument from its DECLARED type -
    // an `object` one is handed the raw descriptor array and the constructor gets a TypeError.
    if (!class_exists(BlockDirectivePolicy::class)) {
        class BlockDirectivePolicy
        {
            /** @param string[] $restrictedPatterns */
            public function __construct(private array $restrictedPatterns = [])
            {
            }

            public function isRestricted(string $class): bool
            {
                foreach ($this->restrictedPatterns as $pattern) {
                    if (stripos($class, $pattern) !== false) {
                        return true;
                    }
                }

                return false;
            }
        }
    }
}
namespace Magento\Email\Model {
    if (!class_exists(AbstractTemplate::class)) {
        abstract class AbstractTemplate
        {
            /** Url::getRouteUrl() concatenates _direct onto the base URL with no filtering. */
            public function getUrl($store, $route = '', $params = [])
            {
                return 'https://shop.example/' . ($params['_direct'] ?? $route);
            }
        }
    }
}
namespace Magento\Variable\Model\Source {
    if (!class_exists(Variables::class)) {
        class Variables { public function getAvailableVars() { return []; } }
    }
}
namespace Magento\Framework\App {
    if (!class_exists(State::class)) {
        class State {
            public function getAreaCode() { return 'frontend'; }
            public function emulateAreaCode($area, $callback) { return $callback(); }
        }
    }
}
namespace Magento\Framework\View {
    if (!class_exists(LayoutFactory::class)) {
        class LayoutFactory { public function create(array $data = []) { return null; } }
    }
}
namespace Magento\Framework {
    if (!interface_exists(UrlInterface::class)) {
        interface UrlInterface {
            public const URL_TYPE_LINK = 'link';
            public const URL_TYPE_DIRECT_LINK = 'direct_link';
            public const URL_TYPE_WEB = 'web';
            public const URL_TYPE_MEDIA = 'media';
            public const URL_TYPE_STATIC = 'static';
            public function getUrl($routePath = null, $routeParams = null);
        }
    }
}
namespace Magento\Framework\View\Asset {
    if (!class_exists(Repository::class)) {
        class Repository {
            public function createAsset($fileId, array $params = []) { return null; }
            public function getUrlWithParams($fileId, array $params) { return ''; }
        }
    }
}
namespace Magento\Store\Model {
    if (!interface_exists(StoreManagerInterface::class)) {
        interface StoreManagerInterface { public function getStore($storeId = null); }
    }
    if (!class_exists(Information::class)) {
        class Information {
            public const XML_PATH_STORE_INFO_REGION_CODE = 'general/store_information/region_id';
            public const XML_PATH_STORE_INFO_COUNTRY_CODE = 'general/store_information/country_id';
            public function getStoreInformationObject($store)
            {
                return new class { public function getData($key = '') { return null; } };
            }
        }
    }
}
namespace Magento\Framework\App {
    if (!interface_exists(TemplateTypesInterface::class)) {
        interface TemplateTypesInterface {
            public const TYPE_TEXT = 1;
            public const TYPE_HTML = 2;
        }
    }
    if (!class_exists(Area::class)) {
        class Area {
            public const AREA_GLOBAL = 'global';
            public const AREA_FRONTEND = 'frontend';
            public const AREA_ADMINHTML = 'adminhtml';
        }
    }
}
namespace Magento\Store\Model\App {
    if (!class_exists(Emulation::class)) {
        class Emulation {
            public function startEnvironmentEmulation($storeId, $area = 'frontend', $force = false) { return $this; }
            public function stopEnvironmentEmulation() { return $this; }
        }
    }
}
namespace Magento\Email\Model\Template\Css {
    if (!class_exists(Processor::class)) {
        class Processor { public function process($css) { return $css; } }
    }
}
namespace Magento\Email\Model {
    if (!class_exists(Template::class)) {
        class Template {
            public function load($id) { return $this; }
            public function loadDefault($id) { return $this; }
            public function getTemplateText() { return ''; }
        }
    }
    if (!class_exists(TemplateFactory::class)) {
        class TemplateFactory { public function create(array $data = []) { return new Template(); } }
    }
}
namespace Magento\Variable\Model {
    if (!class_exists(Variable::class)) {
        class Variable {
            public const TYPE_TEXT = 'text';
            public const TYPE_HTML = 'html';
            public function setStoreId($storeId) { return $this; }
            public function loadByCode($code) { return $this; }
            public function getValue($type = null) { return ''; }
        }
    }
    if (!class_exists(VariableFactory::class)) {
        class VariableFactory { public function create(array $data = []) { return new Variable(); } }
    }
}
namespace Magento\Framework\Filter {
    if (!class_exists(Template::class)) {
        class Template {
            protected $templateVars = [];
            public function setVariables(array $variables) { $this->templateVars = $variables; return $this; }
            public function filter($value) { return $value; }
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
        /**
         * Substitutes %1, %2 ... as Phrase\Renderer\Placeholder does.
         *
         * Returning the text unchanged made every `__('... %1', $x)` in the engine look
         * correct in tests while shipping a literal `%1` - which is exactly the class of
         * unfaithful stub that has hidden real defects here before.
         */
        function __($text, ...$args) {
            if ($args !== [] && is_array($args[0])) {
                $args = $args[0];
            }
            $map = [];
            foreach (array_values($args) as $i => $value) {
                $map['%' . ($i + 1)] = (string)$value;
            }

            return $map === [] ? $text : strtr((string)$text, $map);
        }
    }
}
