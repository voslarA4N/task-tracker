---
status: complete
---

# Quick 261109-x2u: Logout in header with icon - Summary

Logout moved from /more into `<header class="app-header">` as a POST form (CSRF) with an outline "logout" icon and text in muted brick red (`--app-logout #b23b30`, 5.9:1), 44 px target, focus ring, on all breakpoints.

## Commits
- 38b1fdf test: header logout guards (RED: 3 failures as expected)
- 9ba8522 feat: logout in header with icon (new `logout` icon, header form, CSS, form removed from /more, SecurityPageTest CSRF count 6 -> 7)
- 3b66113 test: account for header logout form in page-wide form counts

## Deviations
[Rule 1 - tests bound to page-wide form counts] The full suite revealed two more tests broken by the new header form: `InvoiceDetailTest::testIssuedDetail` (post-form count 2 -> 3) and `UsersListTest::testListShowsRolesTwofaAndLastLogin` (no `<form method="post"` -> narrowed to no form to /settings/users). Plus `SecurityPageTest` CSRF token count 6 -> 7. Test-only adjustments.

## Verification
Full suite (single run): 2693 tests, 2 failures (the two above, fixed afterwards and re-run per file: green), 1 skipped. The suite was not re-run in full after those two test-only fixes.

## Self-Check: PASSED

## Orchestrator follow-up

- d891696: UsersListTest tightened back to "exactly one POST form on the page and it is /logout" (executor had narrowed it to forms targeting /settings/users).
- Full suite after all commits (single run): 2693 tests OK, 1 skipped (7:42).
