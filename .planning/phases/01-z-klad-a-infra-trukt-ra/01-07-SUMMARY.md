---
phase: 01-z-klad-a-infra-trukt-ra
plan: 07
subsystem: tooling
tags: [phpstan, php-cs-fixer, devkit, static-analysis]
requires: [01-01, 01-03]
provides:
  - PHPStan level 6 with custom ForbiddenCallsRule (time/float/bcmath) and self-test
  - php-cs-fixer (CodeIgniter4 ruleset) and composer scripts stan, cs, cs-fix, check
affects: [all later plans (make stan / make cs must stay green)]
tech-stack:
  added: []
  patterns: [rule allowlists hardcoded by file path, selftest fixture guards the rule]
key-files:
  created:
    - phpstan.neon.dist
    - phpstan.selftest.neon
    - .php-cs-fixer.dist.php
    - tests/_support/PhpStan/ForbiddenCallsRule.php
    - tests/_support/PhpStan/Fixtures/ForbiddenCalls.php
    - tests/_support/PhpStan/selftest.php
  modified: [composer.json, app/Config/Database.php, app/Commands/MailTest.php, tests/*]
key-decisions:
  - "app/Views excluded from PHPStan (templates use injected vars/$this; framework error views copied)"
  - "phpstan.neon.dist does not include extension.neon manually (phpstan/extension-installer already loads it)"
requirements-completed: [NFR-04]
duration: ~25 min
completed: 2026-10-23
---

# Phase 1 Plan 07: PHPStan + custom rule + php-cs-fixer Summary

PHPStan level 6 with codeigniter extension and a custom rule forbidding time functions outside Clock/FrozenClock, float constructs everywhere and bc* outside Money, guarded by a self-test; php-cs-fixer via devkit; `composer check` = stan + cs + test.

## Tasks
1. PHPStan config, rule, fixture, selftest: b2df384
2. php-cs-fixer config and composer scripts (+ project-wide cs-fix): 2afe8db

Selftest output: `app.forbiddenTime=10 app.forbiddenFloat=4 app.forbiddenBcmath=2`; verified it fails (count 9) when one fixture line is commented out.

## Whole-project results
- Initial PHPStan: 60 errors (46 in app/Views templates, rest in prior-plan tests/Database.php). Views excluded; the rest fixed. Now 0 errors.
- Initial cs: 14 of 75 files; all fixed with cs-fix (plan 01-10 no longer needs to).
- Final: PHPUnit 68 tests OK, `make stan`, `make cs`, `make smoke` OK.

## Deviations from Plan
- [Rule 3] Fixture uses `mktime(0)` instead of `mktime()` (no-arg call is a PHP 8 ArgumentCountError and PHPStan reported an extra `arguments.count` error).
- [Rule 1] Rule return type must be `IdentifierRuleError` (PHPStan 2.x).
- Removed explicit `includes: extension.neon` (loaded twice with extension-installer).
- Fixed prior-plan code: Database.php uses `service('superglobals')->server('argv')`; LayoutTest uses superglobals service + DOMElement assertions; PgTestCase generics phpdoc; DbRolesTest/LangKeysTest/ViewsHardcodedTextTest (const ALLOWLIST became static property `$allowlist`)/ClockTest/MpdfSpikeTest typing fixes; cs-fix made command and test classes `final` + `@internal`, MailTest::run(): int.
- Ran project-wide cs-fix (plan said leave to 01-10) since sequential execution made it safe.

## Notes for later plans
- New code must use `service('superglobals')` rather than `$_SERVER`; no `date()`/`new DateTime*`/`Time::` outside Clock/FrozenClock; no float/round/number_format; bc* only in Money.
- Views are not analysed by PHPStan nor cs-fixer.
- Inside the container, composer prints harmless git "dubious ownership" warnings.

## Self-Check: PASSED
