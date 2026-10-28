#!/bin/sh
# Lokalny koncovy test edge -> stack nginx (docs/ops/server.md, D-02).
# Spusta sa z korena repozitara; potrebuje iba docker, curl a openssl.
# Overuje: HTTP/2 cez edge, realip z CF-Connecting-IP, zatvorenie neznameho SNI,
# presmerovanie portu 80, X-Robots-Tag iba ak je nastaveny, zahodenie PROXY zdroja mimo Cloudflare.
# Vsetky kontajnery, siet a docasny adresar sa na konci odstrania.
# shellcheck disable=SC2015,SC2016
set -eu
cd "$(dirname "$0")/.."

NET=wt-edge-selftest
EDGE=wt-edge-selftest-edge
STACK=wt-edge-selftest-stack
IMG=wt-nginx:selftest
HOST=work-tracker-stage.vostack.dev
TMPD=$(mktemp -d)
FAILED=0

cleanup() {
  docker rm -f "$EDGE" "$STACK" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$TMPD"
}
trap cleanup EXIT INT TERM

ok() { echo "OK    $1"; }
fail() { echo "FAIL  $1"; FAILED=1; }

docker rm -f "$EDGE" "$STACK" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true

# 0. konfiguracia edge
if docker run --rm --add-host wt-prod-nginx:127.0.0.1 --add-host wt-stage-nginx:127.0.0.1 \
  -v "$PWD/docker/edge/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:1.30-alpine nginx -t >/dev/null 2>&1; then
  ok "nginx -t edge konfiguracie"
else
  fail "nginx -t edge konfiguracie"
fi

docker build -q -f docker/nginx/Dockerfile -t "$IMG" . >/dev/null

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$HOST" \
  -keyout "$TMPD/key.pem" -out "$TMPD/cert.pem" >/dev/null 2>&1
chmod 755 "$TMPD"
chmod 644 "$TMPD/key.pem" "$TMPD/cert.pem"
printf 'geo $proxy_protocol_addr $cf_peer { default 1; }\n' > "$TMPD/permissive.conf"
chmod 644 "$TMPD/permissive.conf"

if ! docker network create --subnet 10.250.250.0/24 "$NET" >/dev/null 2>&1; then
  echo "FAIL  nepodarilo sa vytvorit siet 10.250.250.0/24 (podsiet je obsadena, napr. skutocna siet edge); test sa nespusti" >&2
  exit 1
fi

docker run -d --name "$EDGE" --network "$NET" \
  -p 127.0.0.1:18443:443 -p 127.0.0.1:18080:80 \
  -v "$PWD/docker/edge/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:1.30-alpine >/dev/null

# start_stack <robots_tag> <permissive 0|1>
start_stack() {
  docker rm -f "$STACK" >/dev/null 2>&1 || true
  if [ "$2" = 1 ]; then
    set -- "$1" -v "$TMPD/permissive.conf:/etc/nginx/http.d/cf-peer.conf:ro"
  else
    set -- "$1"
  fi
  robots=$1
  shift
  docker run -d --name "$STACK" --network "$NET" --network-alias wt-stage-nginx \
    --add-host php:127.0.0.1 \
    -v "$TMPD:/certs:ro" "$@" \
    -e APP_DOMAIN="$HOST" -e NGINX_HSTS_VALUE=max-age=300 \
    -e SSL_CERT=/certs/cert.pem -e SSL_KEY=/certs/key.pem \
    -e NGINX_PROXY_PROTOCOL=proxy_protocol -e NGINX_ROBOTS_TAG="$robots" \
    "$IMG" >/dev/null
  i=0
  while [ "$i" -lt 30 ]; do
    if docker exec "$STACK" sh -c 'nginx -t' >/dev/null 2>&1 && docker exec "$STACK" sh -c 'pgrep nginx' >/dev/null 2>&1; then
      sleep 1
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  echo "FAIL  stack nginx nenabehol" >&2
  docker logs "$STACK" >&2 || true
  exit 1
}

fetch() {
  curl -sk --http2 --max-time 10 --resolve "$HOST:18443:127.0.0.1" "$@"
}

# Run A: noindex, povolene vsetky PROXY zdroje
start_stack "noindex, nofollow" 1
OUT=$(fetch -o /dev/null -D "$TMPD/h.txt" -w '%{http_code} %{http_version}' -H 'CF-Connecting-IP: 203.0.113.9' "https://$HOST:18443/robots.txt" || true)
[ "$OUT" = "200 2" ] && ok "HTTP/2 cez edge, 200 ($OUT)" || fail "HTTP/2 cez edge: $OUT"
grep -qi '^x-robots-tag: noindex' "$TMPD/h.txt" && ok "X-Robots-Tag noindex na staging" || fail "X-Robots-Tag noindex"
grep -qi '^content-security-policy:' "$TMPD/h.txt" && ok "Content-Security-Policy" || fail "Content-Security-Policy"
docker logs "$STACK" 2>&1 | grep -q '203\.0\.113\.9' && ok "realip: 203.0.113.9 v logu stacku" || fail "realip z CF-Connecting-IP"

if curl -sk --max-time 10 --resolve "evil.example:18443:127.0.0.1" -o /dev/null "https://evil.example:18443/" 2>/dev/null; then
  fail "neznamy SNI nebol zatvoreny"
else
  ok "neznamy SNI zatvoreny"
fi

HDR=$(curl -s --max-time 10 --resolve "$HOST:18080:127.0.0.1" -o /dev/null -D - "http://$HOST:18080/x" | tr -d '\r')
if echo "$HDR" | head -n1 | grep -q ' 301' && echo "$HDR" | grep -qi "^location: https://$HOST/x"; then
  ok "port 80 -> 301 https"
else
  fail "port 80 presmerovanie"
fi

# Run B: prod, bez X-Robots-Tag
start_stack "" 1
OUT=$(fetch -o /dev/null -D "$TMPD/h2.txt" -w '%{http_code}' "https://$HOST:18443/robots.txt" || true)
[ "$OUT" = "200" ] && ok "prod /robots.txt 200" || fail "prod /robots.txt: $OUT"
grep -qi '^x-robots-tag' "$TMPD/h2.txt" && fail "prod posiela X-Robots-Tag" || ok "prod bez X-Robots-Tag"

# Run C: skutocny cf-peer.conf, PROXY zdroj mimo Cloudflare
start_stack "" 0
RC=0
CODE=$(fetch -o /dev/null -w '%{http_code}' "https://$HOST:18443/robots.txt" -H 'CF-Connecting-IP: 203.0.113.9') || RC=$?
# 444 zatvori spojenie bez odpovede: curl 52/56 (HTTP/1.1) alebo 92 (HTTP/2 stream), kod 000.
if [ "$CODE" = 000 ] && [ "$RC" != 0 ]; then
  ok "PROXY zdroj mimo Cloudflare zahodeny (curl $RC)"
else
  fail "PROXY zdroj mimo Cloudflare nebol zahodeny (curl $RC)"
fi

[ "$FAILED" = 0 ] || exit 1
echo "Edge self-test hotovy"
