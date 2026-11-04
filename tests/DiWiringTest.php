<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use PHPUnit\Framework\TestCase;

/**
 * Every optional collaborator a Magento-side class can take is one di.xml actually gives it.
 *
 * Magento's DI leaves a nullable parameter with a null default as null unless it is configured,
 * so a port written to use a FilterPool, a store manager or a render scope "when it has one"
 * silently never has one on a store. That is how the custom-directive FilterPool and the
 * {{customvar}} store went missing. Any class listed here as deliberately left null says why.
 */
final class DiWiringTest extends TestCase
{
    /** class => parameter => why di.xml leaves it null */
    private const LEFT_NULL = [
        'MageOS\\TemplateParser\\Magento\\VariableCustomVariableReader' => [
            'storeId' => 'a fixed store is for the CLI; on a store the filter decides',
        ],
        'MageOS\\TemplateParser\\Magento\\ConfigTemplateLoader' => [
            'storeId' => 'a fixed store is for the CLI; on a store config reads the current one',
        ],
        'MageOS\\TemplateParser\\Magento\\LayoutBlockRenderer' => [
            'allowedClasses' => "an integrator's own narrowing, off by default",
        ],
        'MageOS\\TemplateParser\\Magento\\TypeCheckedWidgetRenderer' => [
            'allowedTypes' => "an integrator's own narrowing, off by default",
        ],
        'MageOS\\TemplateParser\\Magento\\TemplateFilterAdapter' => [
            'options' => 'null is compatible mode, which is what a store renders with',
            'policy' => 'null is unrestricted, as the filter it stands in for has no policy',
        ],
        'MageOS\\TemplateParser\\Magento\\Shadow\\ShadowRecorder' => [
            'clock' => 'a test seam; null is the system clock',
        ],
    ];

    /** @return iterable<string,array{class-string}> */
    public static function magentoClasses(): iterable
    {
        $root = dirname(__DIR__) . '/src/Magento';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'MageOS\\TemplateParser\\Magento\\'
                . str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
            if (class_exists($class) && (new \ReflectionClass($class))->isInstantiable()) {
                yield $class => [$class];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('magentoClasses')]
    public function testEveryOptionalCollaboratorIsWired(string $class): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $configured = $this->configuredArguments($class);
        $missing = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $isObject = $type instanceof \ReflectionNamedType && !$type->isBuiltin();
            $leftNull = $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null;
            if (!$leftNull || (!$isObject && $type?->getName() !== 'array')) {
                continue;
            }
            if (!in_array($parameter->getName(), $configured, true)
                && !isset(self::LEFT_NULL[$class][$parameter->getName()])
            ) {
                $missing[] = '$' . $parameter->getName();
            }
        }

        self::assertSame([], $missing, "$class: optional collaborators di.xml never supplies, so DI leaves them null");
    }

    /** @return list<string> */
    private function configuredArguments(string $class): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__) . '/etc/{,*/}di.xml', GLOB_BRACE) ?: [] as $file) {
            $xml = simplexml_load_file($file);
            foreach ($xml->xpath(sprintf('//type[@name="%s"]/arguments/argument/@name', $class)) ?: [] as $name) {
                $names[] = (string)$name;
            }
        }

        return $names;
    }
}
