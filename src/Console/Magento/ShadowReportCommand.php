<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magento;

use Cresset\TemplateParser\Console\Command\ShadowReportCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The standalone shadow:report command, registered with bin/magento.
 *
 * Nothing is reimplemented: the subclass only renames the command into the template:
 * namespace and takes the ObjectManager bin/magento already booted. See ObjectManagerBridge.
 */
#[AsCommand(name: 'template:shadow:report')]
class ShadowReportCommand extends BaseCommand
{
    use ObjectManagerBridge;
}
