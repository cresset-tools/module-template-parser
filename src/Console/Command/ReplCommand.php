<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\EngineFactory;
use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Console\StoreEmulator;
use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repl', description: 'Try template directives interactively')]
class ReplCommand extends Command
{
    use MagentoAware;

    protected function configure(): void
    {
        $this->addModeOption()
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store id to render in')
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Preset a variable, name=value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magento = $this->magento();
        $mode = Mode::parse((string)$input->getOption('mode'));
        $storeId = $input->getOption('store') !== null ? (int)$input->getOption('store') : null;
        $variables = $this->parseVariables((array)$input->getOption('var'));

        $factory = new EngineFactory($magento);
        $emulator = new StoreEmulator($magento);

        $output->writeln('<info>template-parser</info> interactive');
        $output->writeln('  mode    ' . $mode->describe());
        $output->writeln('  store   ' . ($magento->isAvailable()
            ? 'connected' . ($storeId !== null ? ' (store ' . $storeId . ')' : '')
            : 'not connected - ' . $magento->reason()));
        $output->writeln('  ' . count($factory->wiredDirectives($storeId)) . ' directives wired');
        $output->writeln('');
        $output->writeln('Type a template. <comment>:help</comment> for commands, <comment>:quit</comment> to leave.');
        $output->writeln('');

        $stream = $input instanceof StreamableInputInterface && $input->getStream() !== null
            ? $input->getStream()
            : STDIN;
        $interactive = $input->isInteractive() && $stream === STDIN && stream_isatty(STDIN);
        if ($interactive && function_exists('readline_read_history')) {
            @readline_read_history(self::historyFile());
        }

        while (true) {
            $line = $this->readLine($mode, $interactive, $output, $stream);
            if ($line === null) {
                break;                   // EOF: piped input ran out, or ctrl-D
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ':')) {
                $keepGoing = $this->command($line, $output, $mode, $storeId, $variables, $magento, $factory);
                if (!$keepGoing) {
                    break;
                }
                continue;
            }

            $this->render($line, $output, $mode, $storeId, $variables, $factory, $emulator);
        }

        if ($interactive && function_exists('readline_write_history')) {
            @readline_write_history(self::historyFile());
        }

        return Command::SUCCESS;
    }

    /**
     * Reads one line.
     *
     * readline() when a person is typing, so arrow keys and history work; a plain fgets()
     * otherwise, which is what makes `printf '...' | template-parser repl` usable in a
     * script and in this package's own tests. Symfony's QuestionHelper does neither: it
     * returns the default the moment input is not interactive, so a piped REPL exited
     * immediately.
     */
    /** @param resource $stream */
    private function readLine(Mode $mode, bool $interactive, OutputInterface $output, $stream): ?string
    {
        $prompt = sprintf('%s> ', $mode->value);

        if ($interactive && function_exists('readline')) {
            $line = readline($prompt);
            if ($line === false) {
                $output->writeln('');

                return null;
            }
            if (trim($line) !== '' && function_exists('readline_add_history')) {
                readline_add_history($line);
            }

            return $line;
        }

        if ($interactive) {
            $output->write($prompt);
        }

        $line = fgets($stream);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    private static function historyFile(): string
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();

        return $home . '/.template-parser_history';
    }

    /** @param array<string,mixed> $variables */
    private function render(
        string $template,
        OutputInterface $output,
        Mode $mode,
        ?int $storeId,
        array $variables,
        EngineFactory $factory,
        StoreEmulator $emulator,
    ): void {
        $context = new Context($variables, RenderPolicy::unrestricted());

        try {
            $rendered = $emulator->around($storeId, static fn (): string
                => $factory->create($mode, $storeId)->render($template, context: $context));
            $output->writeln($rendered === '' ? '<fg=gray>(empty)</>' : $rendered);
        } catch (TemplateError $e) {
            // The engine's own diagnostic is already written for whoever is typing.
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s: %s</error>', (new \ReflectionClass($e))->getShortName(), $e->getMessage()));
            return;
        }

        foreach ($context->violations() as $violation) {
            $output->writeln('<comment>policy: ' . $violation->describe() . '</comment>');
        }
        foreach ($context->incompatibilities() as $incompatibility) {
            $output->writeln('<comment>legacy: ' . $incompatibility->describe() . '</comment>');
        }
        foreach ($context->deferred() as $deferred) {
            $output->writeln(sprintf('<fg=gray>deferred: %s %s</>', $deferred['kind'], json_encode($deferred['payload'])));
        }
    }

    /** @param array<string,mixed> $variables */
    private function command(
        string $line,
        OutputInterface $output,
        Mode &$mode,
        ?int &$storeId,
        array &$variables,
        MagentoContext $magento,
        EngineFactory $factory,
    ): bool {
        [$name, $argument] = array_pad(explode(' ', $line, 2), 2, null);

        switch ($name) {
            case ':quit':
            case ':q':
            case ':exit':
                return false;

            case ':help':
                $output->writeln(<<<'HELP'
  <comment>:mode</comment> strict|lenient|compatible   switch engine posture
  <comment>:set</comment> name=value                   set a variable (see :types)
  <comment>:unset</comment> name                       remove one
  <comment>:vars</comment>                             list variables in scope
  <comment>:store</comment> [id]                       render in a store's context
  <comment>:stores</comment>                           list stores
  <comment>:directives</comment>                       what is wired here
  <comment>:types</comment>                            how :set reads a value
  <comment>:quit</comment>                             leave
HELP);
                return true;

            case ':types':
                $output->writeln(<<<'TYPES'
  Values are typed, because the difference matters here:

    <comment>:set qty=0</comment>          int 0
    <comment>:set qty="0"</comment>        string "0"
    <comment>:set price=1.5</comment>      float
    <comment>:set flag=true</comment>      bool     (also false, null)
    <comment>:set xs=[1,2]</comment>       array    (JSON)
    <comment>:set o={"a":1}</comment>      array    (JSON, associative)
    <comment>:set name=Ada</comment>       string   (a bare word)

  On PHP 8 the legacy filter treats int 0 as TRUTHY and string "0" as truthy too,
  while this engine uses standard PHP truthiness and calls both falsy. Being able
  to set one and not the other is the point.
TYPES);
                return true;

            case ':mode':
                try {
                    $mode = Mode::parse((string)$argument);
                    $output->writeln('  ' . $mode->describe());
                } catch (\InvalidArgumentException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                }
                return true;

            case ':set':
                if ($argument === null || !str_contains($argument, '=')) {
                    $output->writeln('<error>usage: :set name=value</error>');
                    return true;
                }
                [$key, $value] = explode('=', $argument, 2);
                $parsed = self::parseValue($value);
                $variables[trim($key)] = $parsed;
                $output->writeln(sprintf('  %s = %s', trim($key), self::describeValue($parsed)));
                return true;

            case ':unset':
                unset($variables[trim((string)$argument)]);
                return true;

            case ':vars':
                if ($variables === []) {
                    $output->writeln('  <fg=gray>(none)</>');
                }
                foreach ($variables as $key => $value) {
                    $output->writeln(sprintf('  %-20s %s', $key, self::describeValue($value)));
                }
                return true;

            case ':store':
                $storeId = $argument === null || trim($argument) === '' ? null : (int)$argument;
                $output->writeln('  store ' . ($storeId ?? 'none'));
                return true;

            case ':stores':
                $stores = (new StoreEmulator($magento))->stores();
                if ($stores === []) {
                    $output->writeln('  <fg=gray>(no store connected)</>');
                }
                foreach ($stores as $store) {
                    $output->writeln(sprintf('  %-4d %-20s %s', $store['id'], $store['code'], $store['name']));
                }
                return true;

            case ':directives':
                $names = $factory->wiredDirectives($storeId);
                sort($names);
                $output->writeln('  ' . implode(', ', $names));
                return true;

            default:
                $output->writeln('<error>unknown command ' . $name . ' - try :help</error>');
                return true;
        }
    }

    /**
     * @param string[] $pairs
     * @return array<string,mixed>
     */
    private function parseVariables(array $pairs): array
    {
        $variables = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $variables[trim($key)] = self::parseValue($value);
        }

        return $variables;
    }

    /**
     * Reads a typed value from what someone typed.
     *
     * Strings-only would make the REPL useless for the questions people actually bring to
     * it. `{{if qty}}` behaves differently for int 0 and string "0" - that difference is the
     * PHP 7 to 8 truthiness change this engine documents - and a REPL that can only produce
     * strings cannot show either half of it.
     *
     * Bare words stay strings, so the common case is unchanged; quoting forces a string when
     * the value would otherwise look like something else.
     */
    private static function parseValue(string $raw): mixed
    {
        $value = trim($raw);

        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (strlen($value) >= 2 && ($first === '"' || $first === "'") && $last === $first) {
            return substr($value, 1, -1);
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => self::parseScalarOrJson($value),
        };
    }

    private static function parseScalarOrJson(string $value): mixed
    {
        if (is_numeric($value)) {
            // An integer-looking value becomes an int, so {{if qty}} can be asked about 0.
            return str_contains($value, '.') || stripos($value, 'e') !== false
                ? (float)$value
                : (int)$value;
        }

        if ($value[0] === '[' || $value[0] === '{') {
            try {
                return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $value;           // not JSON after all; take it literally
            }
        }

        return $value;
    }

    /** Type and value, so `0` and `"0"` are never confused in the listing. */
    private static function describeValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => sprintf('string  %s', var_export($value, true)),
            is_bool($value) => sprintf('bool    %s', $value ? 'true' : 'false'),
            is_int($value) => sprintf('int     %d', $value),
            is_float($value) => sprintf('float   %s', var_export($value, true)),
            $value === null => 'null',
            is_array($value) => sprintf('array   %s', json_encode($value)),
            default => get_debug_type($value),
        };
    }
}
