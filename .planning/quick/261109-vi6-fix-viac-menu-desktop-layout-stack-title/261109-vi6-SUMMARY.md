---
status: complete
---

# Quick 261109-vi6: Stack Viac menu title and hint Summary

`.more-item` is now a column flex container (48px min-height, left-aligned, 4px gap, vertically centered), so each Viac menu title sits above its hint on desktop and iPhone.

## Commits
- efaa342: test(quick-261109-vi6): guard stacked More menu items (RED, failed on flex-direction)
- fbec00a: fix(quick-261109-vi6): stack More menu title and hint on desktop (GREEN)

## Files
- public/assets/css/app.css (line 187, `.more-item` rule)
- tests/unit/Ui/AppCssScaleTest.php (new `testMoreItemStacksTitleAndHint`)

## Verification
AppCssScaleTest 3/3, PwaServiceWorkerTest 4/4, LayoutTest 29/29 green. Cache busting is automatic (filemtime `?v=` and SW version hash).

## Deviations
None.

## Pending
Manual staging check of /more by the owner (not deployed).

## Self-Check: PASSED
