<?php

namespace WPMCP\Compliance;

/**
 * One compliance rule.
 *
 * Rules are pure: check() reads the context and returns findings, never
 * writes, never exits, never echoes. Severity resolution and profile
 * overrides happen in the Runner, so a rule states only its own default.
 */
interface Rule
{
    /**
     * Stable machine id, for example WPORG-05-TRIALWARE. Used in output, in
     * --rule filters, and in profile severity overrides, so it never changes
     * once shipped.
     */
    public function id(): string;

    /**
     * Where the rule comes from: a wp.org guideline number and title, a
     * Plugin Check class, or a line of the reviewer handbook checklist.
     */
    public function guideline(): string;

    /**
     * One-line human title.
     */
    public function title(): string;

    /**
     * Why this matters and what a compliant plugin looks like. Printed in the
     * markdown report and in --explain.
     */
    public function explanation(): string;

    /**
     * Severity when the active profile has no override.
     */
    public function default_severity(): string;

    /**
     * @return Finding[]
     */
    public function check(Rule_Context $context): array;
}
