---
status: complete
phase: quick-261027-jjx
plan: 01
requirements: [WR-01, WR-04]
commits: [ed09bdf, 2aa58c5, cef426f]
---

# Quick 261027-jjx: Full-snapshot preview token and DB line order

- Preview token is now sha256 of canonical JSON of the whole snapshot (minus `number`, `variable_symbol`); order-sensitive. Draft button requires a token.
- Issuer: separate `FOR UPDATE OF a` lock statement (id order), then fetch ordered `t.created_at, a.id`; `usort` removed.
- New `PreviewTokenScopeTest` (stale: issue date, bank iban, entity version address, client address; unchanged issues; DST fall-back line order matches preview).
- `invIssue` guard: in-flight preview refresh, date mismatch, failed refresh disable Vystavit; stale message (sk/en `previewStaleRefresh`); `fresh` getter for CSP-safe binding.

## Deviations
- cs-fix reformatted four test/support files; included in the UI commit.
- Entity-version stale case uses owner-connection UPDATE (no trigger problem).

## Gates
- `make test`: OK (1077 tests, 7329 assertions)
- `composer stan`: OK (no errors); `composer cs`: 0 files to fix
- `node --test tests/js/`: 47 pass, 0 fail
