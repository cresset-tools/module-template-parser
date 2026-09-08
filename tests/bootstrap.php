<?php
declare(strict_types=1);

require __DIR__ . '/magento-stubs.php';

spl_autoload_register(static function (string $class): void {
    foreach ([
        'MageOS\\TemplateParser\\Test\\' => __DIR__ . '/',
        'MageOS\\TemplateParser\\'       => __DIR__ . '/../src/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});
