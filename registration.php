<?php
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * composer.json autoloads this file unconditionally, and this package is usable - and
 * tested - without Magento present. Registering only when the registrar exists keeps
 * `composer require` outside a Magento install from fataling on a missing class.
 */
if (class_exists(ComponentRegistrar::class)) {
    ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Cresset_TemplateParser', __DIR__);
}
