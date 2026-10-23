#!/bin/sh
# Spike mPDF pod PHP-FPM: priame volanie FastCGI (bez nginx a bez CI4 routy).
# Spúšťať v kontajneri php ako www-data: sh scripts/spikes/mpdf-fpm.sh
set -eu

SCRIPT=/var/www/html/scripts/spikes/mpdf.php

run() {
    # $1 = prefix, $2 = query string; z odpovede odreže HTTP hlavičky (po prvý prázdny riadok)
    out=$(SCRIPT_FILENAME="$SCRIPT" SCRIPT_NAME=/spikes/mpdf.php REQUEST_METHOD=GET QUERY_STRING="$2" \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | tr -d '\r' | awk 'body{print} /^$/ && !body{body=1}')
    printf '%s ' "$1"
    printf '%s\n' "$out" | tr -d '\n' | sed 's/  */ /g'
    printf '\n'
}

run FPM-COLD 'cold=1'
run FPM-WARM ''
