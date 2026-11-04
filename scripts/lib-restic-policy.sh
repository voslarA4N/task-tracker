#!/bin/sh
# D-07, D-10: jediny zdroj retencnej politiky restic (sourcovany, bez vedlajsich ucinkov).
# Pouzivaju ho backup-restic.sh, archive-yearly.sh a restore-test.sh a test retencie.
# --group-by host,tags: snapshoty sa skupinuju podla hostitela a tagu, nie podla cesty (RESEARCH Pitfall 2).
# --keep-tag archive: rocny archiv sa nikdy nezmaze.
RESTIC_HOST=work-tracker
RESTIC_FORGET_POLICY='--group-by host,tags --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive'
