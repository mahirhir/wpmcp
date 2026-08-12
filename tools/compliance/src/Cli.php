<?php

namespace WPMCP\Compliance;

use InvalidArgumentException;
use WPMCP\Compliance\Reporters\Json_Reporter;
use WPMCP\Compliance\Reporters\Markdown_Reporter;
use WPMCP\Compliance\Reporters\Reporter;
use WPMCP\Compliance\Reporters\Table_Reporter;

/**
 * Argument parsing and orchestration, kept out of bin/compliance.php so the
 * whole entry point is testable without spawning a process.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_FINDINGS = 1;
    public const EXIT_USAGE = 2;

    public function __construct(private string $cwd)
    {
    }

    /**
     * @param  string[] $argv without the script name
     * @return array{status:int,output:string}
     */
    public function run(array $argv): array
    {
        try {
            $options = $this->parse($argv);
        } catch (InvalidArgumentException $error) {
            return ['status' => self::EXIT_USAGE, 'output' => 'error: ' . $error->getMessage() . "\n\n" . $this->usage()];
        }

        if ($options['help']) {
            return ['status' => self::EXIT_OK, 'output' => $this->usage()];
        }
        if ($options['list_rules']) {
            return ['status' => self::EXIT_OK, 'output' => $this->list_rules()];
        }
        if ('' !== $options['explain']) {
            $rule = Rule_Registry::get($options['explain']);
            if (null === $rule) {
                return ['status' => self::EXIT_USAGE, 'output' => sprintf("error: unknown rule %s\n", $options['explain'])];
            }
            return ['status' => self::EXIT_OK, 'output' => $this->explain($rule)];
        }

        $path = $this->resolve_path($options['path']);
        if (! is_dir($path)) {
            return ['status' => self::EXIT_USAGE, 'output' => sprintf("error: %s is not a directory\n", $path)];
        }

        $profile = Profile::named($options['profile']);
        if (null !== $options['artifact']) {
            $profile = $profile->with_options(['artifact' => $options['artifact']]);
        }

        $context = Rule_Context::for_path($path, $profile, $options['excludes']);
        $runner = Runner::with_default_rules();
        if ([] !== $options['packs']) {
            $rules = [];
            foreach ($options['packs'] as $pack) {
                $rules = array_merge($rules, Rule_Registry::pack($pack));
            }
            $runner = new Runner($rules);
        }
        if ([] !== $options['rules']) {
            $runner = $runner->only($options['rules']);
        }

        $report = $runner->run($context);
        $output = $this->reporter($options['format'], $options['colour'])->render($report);
        $status = [] === $report->at_least($options['fail_on']) ? self::EXIT_OK : self::EXIT_FINDINGS;

        return ['status' => $status, 'output' => $output];
    }

    /**
     * @param  string[] $argv
     * @return array{profile:string,path:string,format:string,rules:string[],packs:string[],fail_on:string,excludes:?array,artifact:?bool,colour:bool,help:bool,list_rules:bool,explain:string}
     */
    public function parse(array $argv): array
    {
        $options = [
            'profile' => Profile::DISTRIBUTION,
            'path' => '.',
            'format' => 'table',
            'rules' => [],
            'packs' => [],
            'fail_on' => Severity::BLOCKER,
            'excludes' => null,
            'artifact' => null,
            'colour' => false,
            'help' => false,
            'list_rules' => false,
            'explain' => '',
        ];

        foreach ($argv as $argument) {
            if ('--help' === $argument || '-h' === $argument) {
                $options['help'] = true;
                continue;
            }
            if ('--list-rules' === $argument) {
                $options['list_rules'] = true;
                continue;
            }
            if ('--color' === $argument || '--colour' === $argument) {
                $options['colour'] = true;
                continue;
            }
            if ('--artifact' === $argument) {
                $options['artifact'] = true;
                continue;
            }
            if ('--no-artifact' === $argument) {
                $options['artifact'] = false;
                continue;
            }
            if (! preg_match('/^--([a-z\-]+)=(.*)$/', $argument, $matches)) {
                throw new InvalidArgumentException(sprintf('unrecognised argument "%s"', $argument));
            }
            [, $name, $value] = $matches;
            switch ($name) {
                case 'profile':
                    if (! in_array($value, Profile::names(), true)) {
                        throw new InvalidArgumentException(
                            sprintf('unknown profile "%s", expected one of: %s', $value, implode(', ', Profile::names()))
                        );
                    }
                    $options['profile'] = $value;
                    break;
                case 'path':
                    $options['path'] = $value;
                    break;
                case 'format':
                    if (! in_array($value, ['table', 'json', 'markdown'], true)) {
                        throw new InvalidArgumentException(sprintf('unknown format "%s", expected table, json or markdown', $value));
                    }
                    $options['format'] = $value;
                    break;
                case 'rule':
                    $options['rules'] = array_values(array_filter(array_map('trim', explode(',', $value))));
                    break;
                case 'pack':
                    foreach (array_filter(array_map('trim', explode(',', $value))) as $pack) {
                        if (! in_array($pack, Rule_Registry::pack_names(), true)) {
                            throw new InvalidArgumentException(
                                sprintf('unknown pack "%s", expected one of: %s', $pack, implode(', ', Rule_Registry::pack_names()))
                            );
                        }
                        $options['packs'][] = $pack;
                    }
                    break;
                case 'fail-on':
                    if (! Severity::is_valid($value)) {
                        throw new InvalidArgumentException(
                            sprintf('unknown severity "%s", expected one of: %s', $value, implode(', ', Severity::all()))
                        );
                    }
                    $options['fail_on'] = $value;
                    break;
                case 'exclude':
                    $options['excludes'] = array_values(array_filter(array_map('trim', explode(',', $value))));
                    break;
                case 'explain':
                    $options['explain'] = $value;
                    break;
                default:
                    throw new InvalidArgumentException(sprintf('unrecognised option "--%s"', $name));
            }
        }

        return $options;
    }

    private function resolve_path(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        $resolved = realpath($this->cwd . '/' . $path);
        return false === $resolved ? rtrim($this->cwd . '/' . $path, '/') : $resolved;
    }

    private function reporter(string $format, bool $colour): Reporter
    {
        switch ($format) {
            case 'json':
                return new Json_Reporter();
            case 'markdown':
                return new Markdown_Reporter();
        }
        return new Table_Reporter($colour);
    }

    private function list_rules(): string
    {
        $out = '';
        foreach (Rule_Registry::packs() as $pack => $rules) {
            $out .= sprintf("%s\n", strtoupper($pack));
            foreach ($rules as $rule) {
                $out .= sprintf("  %-28s %-20s %s\n", $rule->id(), $rule->default_severity(), $rule->title());
            }
            $out .= "\n";
        }
        return $out . sprintf("%d rules\n", count(Rule_Registry::all()));
    }

    private function explain(Rule $rule): string
    {
        return sprintf(
            "%s\n%s\n\nPack:     %s\nSource:   %s\nSeverity: %s\n\n%s\n",
            $rule->id(),
            $rule->title(),
            Rule_Registry::pack_of($rule->id()),
            $rule->guideline(),
            $rule->default_severity(),
            wordwrap($rule->explanation(), 78)
        );
    }

    /**
     * Built by concatenation rather than a heredoc: PluginCheck.CodeAnalysis.Heredoc
     * prohibits heredoc and nowdoc, and this engine holds itself to its own
     * rules.
     */
    private function usage(): string
    {
        $lines = [
            'wp.org compliance engine',
            '',
            'Usage:',
            '  php tools/compliance/bin/compliance.php [options]',
            '',
            'Options:',
            '  --profile=NAME   wporg-free (strict) or distribution (default: distribution)',
            '  --path=DIR       tree to scan; a checkout or an extracted zip (default: .)',
            '  --format=FORMAT  table, json or markdown (default: table)',
            '  --pack=NAMES     comma separated: ' . implode(', ', Rule_Registry::pack_names()),
            '  --rule=IDS       comma separated rule ids, for example WPORG-05-TRIALWARE',
            '  --fail-on=SEV    exit non-zero at this severity or above (default: blocker)',
            '  --exclude=DIRS   comma separated top-level paths to skip',
            '  --artifact       treat the tree as a packaged zip (packaging rules at full severity)',
            '  --no-artifact    treat the tree as a development checkout',
            '  --color          colourise the table output',
            '  --list-rules     print the rule set and exit',
            '  --explain=ID     print one rule\'s guideline reference and rationale',
            '  -h, --help       this text',
            '',
            'Exit codes:',
            '  0  nothing at or above the --fail-on severity',
            '  1  findings at or above the --fail-on severity',
            '  2  usage error',
            '',
        ];
        return implode("\n", $lines);
    }
}
