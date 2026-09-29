<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magento;

use Cresset\TemplateParser\Console\Command\CheckCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The standalone check command, registered with bin/magento.
 *
 * Nothing is reimplemented: the subclass only renames the command into the template:
 * namespace and takes the ObjectManager bin/magento already booted. See ObjectManagerBridge.
 */
#[AsCommand(name: 'template:check')]
class CheckCommand extends BaseCommand
{
    use ObjectManagerBridge;
}
