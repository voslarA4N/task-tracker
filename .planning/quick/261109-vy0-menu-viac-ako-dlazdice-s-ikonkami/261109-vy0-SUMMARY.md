---
status: complete
---

# Quick 261109-vy0: More menu as icon tiles - Summary

Responsive 2/3/4 column grid of 12 icon tiles on /more (own inline SVG icons from a single keyed partial), with a separate full-width logout button (POST + CSRF) under the grid.

## Commits
- 2bb8105 feat: icon partial `app/Views/more/_icon.php`, tile view `app/Views/more/index.php`, `.more-grid`/`.more-tile*`/`.more-logout*` CSS (old `.more-item` removed)
- e141edd test: `testMoreTilesGrid` replaces the vi6 guard in `AppCssScaleTest`; new `tests/feature/Settings/MoreTilesTest.php`

## Verification
AppCssScaleTest (3), MoreTilesTest (3), and 163 related feature tests pass. Not deployed.

## Deviations
None.

## Self-Check: PASSED
