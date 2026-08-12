<?php

namespace WPMCP\Compliance;

use Throwable;

/**
 * Walks the rule set over one plugin tree and resolves severities through the
 * active profile.
 *
 * A rule that throws is reported as a finding against the engine itself
 * rather than aborting the run: a broken rule must never be able to turn a
 * failing build green, and must never hide the other rules' output.
 */
final class Runner
{
    /** @var Rule[] */
    private array $rules;

    /**
     * @param Rule[] $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = array_values($rules);
    }

    public static function with_default_rules(): self
    {
        return new self(Rule_Registry::all());
    }

    /**
     * @return Rule[]
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function run(Rule_Context $context): Report
    {
        $findings = [];
        $ran = [];

        foreach ($this->rules as $rule) {
            if ($context->profile()->is_muted($rule)) {
                continue;
            }
            $ran[] = $rule->id();
            try {
                $raw = $rule->check($context);
            } catch (Throwable $error) {
                $findings[] = (new Finding(
                    'tools/compliance/src/Rules/' . $rule->id() . '.php',
                    0,
                    sprintf('rule %s failed to run: %s', $rule->id(), $error->getMessage()),
                    get_class($error)
                ))->bind($rule, Severity::BLOCKER);
                continue;
            }
            foreach ($raw as $finding) {
                $severity = $context->profile()->severity_for($rule, $finding->severity_override());
                if (null === $severity) {
                    continue;
                }
                $findings[] = $finding->bind($rule, $severity);
            }
        }

        return new Report(
            $context->profile()->name(),
            $context->source()->root(),
            $findings,
            $ran,
            count($context->php_files())
        );
    }

    /**
     * Restrict the runner to the given rule ids.
     *
     * @param string[] $ids
     */
    public function only(array $ids): self
    {
        $wanted = array_map('strtoupper', $ids);
        return new self(array_filter(
            $this->rules,
            static fn (Rule $rule) => in_array(strtoupper($rule->id()), $wanted, true)
        ));
    }
}
