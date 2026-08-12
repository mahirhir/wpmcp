# wp.org compliance engine

Static checks for the WordPress.org [plugin directory
guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
the [reviewer
checklist](https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/)
and the rules [Plugin Check](https://github.com/WordPress/plugin-check)
enforces, run over a plugin tree or an extracted zip.

Dev-only tooling. Nothing here ships in a plugin build, and nothing here loads
inside WordPress: the engine is plain PHP 8.1 with no WordPress dependency, so
it also runs against a built artifact on a machine with no WordPress on it.

## Running it

```bash
composer compliance                 # distribution profile, source tree
composer compliance:wporg           # wporg-free profile, source tree

php tools/compliance/bin/compliance.php --help
php tools/compliance/bin/compliance.php --list-rules
php tools/compliance/bin/compliance.php --explain=WPORG-05-TRIALWARE

# what CI does with the directory build: build-wporg-release.sh strips the
# paid tier, packages the zip, unpacks it and runs the line below as its own
# final gate, so this is one command
composer build:wporg

# or by hand, against any extracted zip
php tools/compliance/bin/compliance.php \
  --profile=wporg-free --artifact --path=build/wporg/wpmcp
```

Exit codes: `0` nothing at or above `--fail-on` (default `blocker`), `1`
findings at that severity or above, `2` a usage error. That is what CI gates
on.

Formats: `--format=table` (default), `--format=json`, `--format=markdown`.

## Profiles

| | `wporg-free` | `distribution` |
| --- | --- | --- |
| Intended for | the cut submitted to the directory | the self-hosted paid zip |
| Paid gating, quotas, licensing code | blocker | best practice |
| readme.txt conformance, packaging hygiene, trademark | blocker or reviewer discretion | downgraded |
| Guarded execution sites | blocker, allowlist is empty | the two audited sites are allowlisted |
| Escaping, nonces, capabilities, direct file access | blocker | blocker |
| Dishonest privacy copy | blocker | blocker |

The split is deliberate: things that are only a problem because
WordPress.org says so are relaxed off-directory, and things that are defects
wherever the zip comes from are not.

`--artifact` / `--no-artifact` overrides whether the tree is treated as a
packaged zip. Packaging rules speak at full severity for an artifact and skip
development-only paths for a checkout, because a git checkout is supposed to
contain tests and build scripts and a zip is not.

## Rule packs

`--pack=licensing,network,code,security,listing,packaging`, mapping to the
groups in the rulebook. `--rule=ID[,ID]` narrows further.

Every rule states its id, the guideline or Plugin Check class it comes from,
a default severity, an explanation, and returns findings with `file:line`.
Severities are `blocker`, `likely-reject`, `reviewer-discretion` and
`best-practice`.

## Adding a rule

1. Add a class under `src/Rules/` extending `Base_Rule`.
2. Register it in the right pack in `src/Rule_Registry.php`. Nothing else
   needs to change.
3. Add a test in `tests/free/Compliance/` proving it fires **and** proving it
   stays quiet on correct code. The second half is the one that matters:
   a rule that cries wolf on ordinary WordPress code gets switched off, and
   then it protects nothing.

Prefer token-level detection to text matching. Pattern strings and
documentation comments must never produce a finding: `Malware_Audit` contains
the literal text `eval(base64_decode(`, and it is not a violation.

## What this is not

It is not Plugin Check. Run
[Plugin Check](https://wordpress.org/plugins/plugin-check/) as well before
submitting: it has runtime checks (enqueued script size and scope, blocking
scripts) that need a live WordPress and a rendered page, and it is what the
reviewer will run. This engine covers the guideline-level judgements Plugin
Check does not encode (trialware, disclosure, honest privacy copy, trademark
context) and runs in CI on every push in about a second.
