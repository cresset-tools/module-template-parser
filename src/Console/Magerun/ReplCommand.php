<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\Command\ReplCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** The standalone Repl command, renamed for magerun. See Magerun\CheckCommand. */
#[AsCommand(name: 'template-parser:repl')]
class ReplCommand extends BaseCommand
{
    use MagerunBridge;
}
