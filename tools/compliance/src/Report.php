<?php

namespace WPMCP\Compliance;

/**
 * The result of one run: findings, plus enough metadata for the reporters and
 * for CI to explain itself.
 */
final class Report
{
    /**
     * @param Finding[] $findings
     * @param string[]  $rules_run
     */
    public function __construct(
        private string $profile,
        private string $root,
        private array $findings,
        private array $rules_run,
        private int $files_scanned
    ) {
        usort($this->findings, [Severity::class, 'compare']);
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return Finding[]
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return string[]
     */
    public function rules_run(): array
    {
        return $this->rules_run;
    }

    public function rule_count(): int
    {
        return count($this->rules_run);
    }

    public function files_scanned(): int
    {
        return $this->files_scanned;
    }

    /**
     * @return array<string,int> severity => count, in descending severity order
     */
    public function counts(): array
    {
        $counts = [];
        foreach ([Severity::BLOCKER, Severity::LIKELY_REJECT, Severity::REVIEWER_DISCRETION, Severity::BEST_PRACTICE] as $severity) {
            $counts[$severity] = 0;
        }
        foreach ($this->findings as $finding) {
            $counts[$finding->severity()]++;
        }
        return $counts;
    }

    public function count_of(string $severity): int
    {
        return $this->counts()[$severity] ?? 0;
    }

    public function has_blockers(): bool
    {
        return $this->count_of(Severity::BLOCKER) > 0;
    }

    /**
     * @return Finding[]
     */
    public function at_least(string $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding) => Severity::at_least($finding->severity(), $severity)
        ));
    }

    /**
     * @return array<string,Finding[]> rule id => findings
     */
    public function by_rule(): array
    {
        $grouped = [];
        foreach ($this->findings as $finding) {
            $grouped[$finding->rule_id()][] = $finding;
        }
        return $grouped;
    }

    public function to_array(): array
    {
        return [
            'profile' => $this->profile,
            'root' => $this->root,
            'files_scanned' => $this->files_scanned,
            'rules_run' => $this->rules_run,
            'counts' => $this->counts(),
            'blocking' => $this->has_blockers(),
            'findings' => array_map(static fn (Finding $finding) => $finding->to_array(), $this->findings),
        ];
    }
}
