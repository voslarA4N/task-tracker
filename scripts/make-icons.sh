#!/bin/sh
# Pregeneruje PNG ikony z public/assets/icons/icon.svg (librsvg v jednorazovom kontajneri).
# Pri zmene ikony zvys verziu v nazvoch (.v1 -> .v2) a v manifeste a layoutoch (immutable cache).
set -eu
cd "$(dirname "$0")/.."

docker run --rm -v "$PWD/public/assets/icons:/icons" alpine:3.22 sh -c '
  set -eu
  apk add --no-cache rsvg-convert >/dev/null
  cd /icons
  rsvg-convert -w 180 -h 180 icon.svg -o icon-180.v1.png
  rsvg-convert -w 192 -h 192 icon.svg -o icon-192.v1.png
  rsvg-convert -w 512 -h 512 icon.svg -o icon-512.v1.png
  rsvg-convert -w 512 -h 512 icon.svg -o icon-maskable-512.v1.png
'

for f in public/assets/icons/*.png; do
  php -r '[$w,$h]=getimagesize($argv[1]); echo $argv[1], " ", $w, "x", $h, "\n";' "$f" 2>/dev/null || file "$f"
done
