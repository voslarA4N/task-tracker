#!/bin/sh
# Lokalny koncovy test celeho mail stacku (docs/ops/mail.md): Stalwart + unbound + Roundcube + nginx
# web + edge, s vlastnou testovacou CA. Spusta sa z korena repozitara; potrebuje docker, curl, openssl.
# Trva niekolko minut (stiahne obrazy, vyssie Stalwart a Roundcube startuju pomaly).
# Test NIKDY nepouzije host porty 25/465/587/993/80/443: iba 127.0.0.1:18025/18465/18587/18993/18080/18443.
# MAIL_SELFTEST_KEEP=1 nechá stack bezat na ladenie (potom ho treba odstranit rucne).
# Siete mail-selftest (10.250.251.0/24) a mail-selftest-edge (10.250.250.0/24) maju pevne podsiete;
# ak su obsadene (napr. skutocne siete mail/edge), test sa nespusti. Vsetko sa na konci odstrani.
# shellcheck disable=SC2015,SC2016,SC2086
set -eu
cd "$(dirname "$0")/.."

P=mail-selftest
NET=mail-selftest
ENET=mail-selftest-edge
EDGE=mail-selftest-edge-proxy
RESTORE=mail-selftest-restore
CURLIMG=curlimages/curl:8.17.0
TMPD=$(mktemp -d)
FAILED=0
RUN=w8p$(date +%s)
VHOST=mail.vostack.dev

ok() { echo "OK    $1"; }
fail() { echo "FAIL  $1"; FAILED=1; }
warn() { echo "WARN  $1"; }

cleanup() {
  if [ "${MAIL_SELFTEST_KEEP:-0}" = 1 ]; then
    echo "MAIL_SELFTEST_KEEP=1: stack ostava bezat (projekt $P, adresar $TMPD); odstran rucne"
    return 0
  fi
  docker rm -f "$EDGE" "$RESTORE" >/dev/null 2>&1 || true
  docker compose -p "$P" $COMPOSE_FILES down -v >/dev/null 2>&1 || true
  docker volume rm "$P-restore-etc" "$P-restore-data" >/dev/null 2>&1 || true
  docker network rm "$NET" "$ENET" "${P}_default" >/dev/null 2>&1 || true
  # soubory v tls/ a backups/ patria uid 2000 resp. root z kontajnerov
  docker run --rm -v "$TMPD:/t" alpine:3.24 rm -rf /t/tls /t/le /t/backups >/dev/null 2>&1 || true
  rm -rf "$TMPD"
}
COMPOSE_FILES="-f compose.mail.yaml -f docker/mail/compose.selftest.yaml"
trap cleanup EXIT INT TERM

for n in "$NET" "$ENET"; do
  docker network rm "$n" >/dev/null 2>&1 || true
done
if ! docker network create --subnet 10.250.251.0/24 "$NET" >/dev/null 2>&1; then
  echo "FAIL  nepodarilo sa vytvorit siet 10.250.251.0/24 (obsadena, napr. skutocna siet mail); test sa nespusti" >&2
  exit 1
fi
if ! docker network create --subnet 10.250.250.0/24 "$ENET" >/dev/null 2>&1; then
  echo "FAIL  nepodarilo sa vytvorit siet 10.250.250.0/24 (obsadena, napr. skutocna siet edge); test sa nespusti" >&2
  exit 1
fi

# --- priprava: CA, certifikat, tajomstva ---------------------------------------------------------
LE=$TMPD/le/live/$VHOST
mkdir -p "$LE" "$TMPD/tls" "$TMPD/secrets" "$TMPD/backups"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=mail-selftest-ca" \
  -keyout "$TMPD/ca-key.pem" -out "$TMPD/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj "/CN=$VHOST" -keyout "$LE/privkey.pem" -out "$TMPD/srv.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:%s,DNS:webmail.vostack.dev,DNS:autoconfig.vostack.dev,DNS:autodiscover.vostack.dev,DNS:mta-sts.vostack.dev\n' "$VHOST" > "$TMPD/san.ext"
openssl x509 -req -in "$TMPD/srv.csr" -CA "$TMPD/ca.pem" -CAkey "$TMPD/ca-key.pem" -CAcreateserial \
  -days 2 -extfile "$TMPD/san.ext" -out "$LE/fullchain.pem" >/dev/null 2>&1
chmod 755 "$TMPD" "$TMPD/le" "$TMPD/le/live" "$LE"
chmod 644 "$LE/privkey.pem" "$LE/fullchain.pem" "$TMPD/ca.pem"

# systemovy CA balik z obrazu Roundcube + testovacia CA (verify_peer ostava zapnute)
docker run --rm --entrypoint cat roundcube/roundcubemail:1.7.5-apache-nonroot /etc/ssl/certs/ca-certificates.crt > "$TMPD/ca-bundle.crt"
cat "$TMPD/ca.pem" >> "$TMPD/ca-bundle.crt"
chmod 644 "$TMPD/ca-bundle.crt"
printf 'geo $proxy_protocol_addr $cf_peer { default 1; }\n' > "$TMPD/permissive.conf"
printf 'dns_cloudflare_api_token = selftest\n' > "$TMPD/cloudflare.ini"
chmod 644 "$TMPD/permissive.conf" "$TMPD/cloudflare.ini"
: > "$TMPD/env"
for n in admin info noreply; do
  openssl rand -hex 16 | tr -d '\n' > "$TMPD/secrets/${n}_password"
done
INFO_PW=$(cat "$TMPD/secrets/info_password")
NOREPLY_PW=$(cat "$TMPD/secrets/noreply_password")
BOOT_PW=boot$(openssl rand -hex 8)

export COMPOSE_PROJECT=$P MAIL_NETWORK=$NET EDGE_NETWORK=$ENET MAIL_TLS_DIR=$TMPD/tls MAIL_LE_DIR=$TMPD/le \
  SECRETS_DIR=$TMPD/secrets ROUNDCUBE_DES_KEY=selftestdeskey123456789 STALWART_ADMIN_PORT=18080 \
  MAIL_CF_PEER=$TMPD/permissive.conf MAIL_SELFTEST_CA_BUNDLE=$TMPD/ca-bundle.crt MAIL_SELFTEST_CF_INI=$TMPD/cloudflare.ini \
  COMPOSE_FILES MAIL_DIR=$PWD BACKUP_DIR=$TMPD/backups BACKUP_ENV_FILE=$TMPD/env
unset STALWART_RECOVERY_ADMIN || true
dc() { docker compose -p "$P" $COMPOSE_FILES "$@"; }

# --- 0. konfiguracia -------------------------------------------------------------------------------
if python3 -c 1 2>/dev/null; then
  if env -u MAIL_NETWORK -u EDGE_NETWORK -u MAIL_TLS_DIR -u MAIL_LE_DIR -u STALWART_ADMIN_PORT -u MAIL_CF_PEER \
      docker compose -f compose.mail.yaml config --format json | python3 -c '
import json, sys
c = json.load(sys.stdin)
pp = [(n, p) for n, sv in c["services"].items() for p in sv.get("ports", []) if p.get("published")]
assert all(p.get("host_ip") for n, p in pp), "port bez host_ip"
assert sorted(int(p["target"]) for n, p in pp if p["host_ip"] == "0.0.0.0") == [25, 465, 587, 993]
adm = [p for n, p in pp if int(p["target"]) == 8080]
assert len(adm) == 1 and adm[0]["host_ip"] == "127.0.0.1"
assert {p["host_ip"] for n, p in pp} <= {"0.0.0.0", "127.0.0.1"}
assert all(n == "stalwart" for n, p in pp)
'; then
    ok "1a produkcne porty: 25/465/587/993 iba 0.0.0.0, 8080 iba 127.0.0.1, 443 nepublikovany"
  else
    fail "1a produkcne porty v compose.mail.yaml"
  fi
else
  warn "python3 chyba, preskakujem kontrolu publikovanych portov"
fi

# --- 1. certifikat (kopia cez mail-cert-renew.sh --copy-only), bootstrap, provisioning -------------
dc build -q unbound >/dev/null
sh scripts/mail-cert-renew.sh --copy-only >/dev/null && [ -s "$TMPD/tls/privkey.pem" ] \
  && ok "mail-cert-renew.sh --copy-only skopiroval certifikat" || fail "mail-cert-renew.sh --copy-only"
TLS_OWNER=$(docker run --rm -v "$TMPD/tls:/t" alpine:3.24 stat -c '%u:%g %a' /t/privkey.pem)
[ "$TLS_OWNER" = "2000:2000 400" ] && ok "privkey 2000:2000 0400" || fail "privkey prava: $TLS_OWNER"

sh scripts/mail-provision.sh --init >/dev/null
STALWART_RECOVERY_ADMIN=admin:$BOOT_PW dc up -d unbound stalwart >/dev/null
export STALWART_BOOTSTRAP_USER=admin STALWART_BOOTSTRAP_PASSWORD=$BOOT_PW
sh scripts/mail-provision.sh >/dev/null && ok "provisioning (bootstrap, 1. beh)" || { fail "provisioning 1. beh"; exit 1; }
STATE1=$(for o in Domain Account NetworkListener AllowedIp Certificate; do sh scripts/mail-provision.sh --cli query $o | sort; done)
sh scripts/mail-provision.sh >/dev/null && ok "provisioning (2. beh)" || fail "provisioning 2. beh"
STATE2=$(for o in Domain Account NetworkListener AllowedIp Certificate; do sh scripts/mail-provision.sh --cli query $o | sort; done)
[ "$STATE1" = "$STATE2" ] && ok "provisioning idempotentny (ziadne duplicity, rovnake id)" || fail "provisioning nie je idempotentny"
unset STALWART_BOOTSTRAP_USER STALWART_BOOTSTRAP_PASSWORD

# obnova bez recovery admina; dalsi beh uz ako stalwart-admin
dc up -d --force-recreate stalwart >/dev/null
sh scripts/mail-provision.sh >/dev/null && ok "provisioning ako stalwart-admin (bez recovery admina)" || fail "provisioning ako stalwart-admin"
if STALWART_BOOTSTRAP_USER=admin STALWART_BOOTSTRAP_PASSWORD=$BOOT_PW sh scripts/mail-provision.sh --cli query Domain >/dev/null 2>&1; then
  fail "recovery admin stale funguje"
else
  ok "recovery admin je vypnuty"
fi
dc up -d roundcube web >/dev/null
docker run -d --name "$EDGE" --network "$ENET" -p 127.0.0.1:18443:443 \
  -v "$PWD/docker/edge/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:1.30-alpine >/dev/null

# pockaj na Roundcube a web
i=0
until curl -s --max-time 5 --cacert "$TMPD/ca.pem" --resolve webmail.vostack.dev:18443:127.0.0.1 https://webmail.vostack.dev:18443/ | grep -q rcmloginuser; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then echo "FAIL  webmail sa nespustil" >&2; dc logs --tail 30 roundcube web >&2; exit 1; fi
  sleep 3
done

# --- 2. porty, DNS ---------------------------------------------------------------------------------
if docker ps --filter "name=$P-" --format '{{.Ports}}' | grep -q '::'; then
  fail "2a publikovany port na [::]"
else
  ok "2a ziadny publikovany port na [::]"
fi
ADMIN_BIND=$(docker port "$P-stalwart-1" 8080/tcp | tr '\n' ' ')
case "$ADMIN_BIND" in
  "127.0.0.1:18080 ") ok "2b admin 8080 iba na 127.0.0.1" ;;
  *) fail "2b admin 8080: $ADMIN_BIND" ;;
esac
dc exec -T unbound drill example.com @127.0.0.1 | grep -q 'rcode: NOERROR' && ok "3a unbound rozlisuje example.com" || fail "3a unbound example.com"
if dc exec -T unbound drill 2.0.0.127.zen.spamhaus.org @127.0.0.1 | grep -q '127\.0\.0\.[0-9]*$'; then
  ok "3b Spamhaus testovacia adresa vracia 127.0.0.x"
else
  warn "3b Spamhaus testovacia adresa bez odpovede (lokalna IP moze byt odmietnuta, neblokuje)"
fi

# --- 3. SMTP, IMAP ---------------------------------------------------------------------------------
mkmsg() { # <subject> <to>
  printf 'From: noreply@vostack.dev\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\nMessage-ID: <%s.%s@vostack.dev>\r\n\r\nTelo spravy %s\r\n' \
    "$2" "$1" "$(date -R)" "$RUN" "$(printf '%s' "$1" | tr -c 'A-Za-z0-9' '_')" "$1"
}
mkmsg "$RUN submission" info@vostack.dev > "$TMPD/m1.txt"
if curl -s -S --max-time 30 --ssl-reqd --cacert "$TMPD/ca.pem" --resolve "$VHOST:18465:127.0.0.1" "smtps://$VHOST:18465" \
  --mail-from noreply@vostack.dev --mail-rcpt info@vostack.dev --user "noreply@vostack.dev:$NOREPLY_PW" -T "$TMPD/m1.txt"; then
  ok "4 submission 465 s AUTH: noreply -> info@"
else
  fail "4 submission 465 AUTH"
fi

# 587 STARTTLS (listener z planu; po restarte stalwartu)
mkmsg "$RUN starttls" info@vostack.dev > "$TMPD/m3.txt"
if curl -s -S --max-time 30 --ssl-reqd --cacert "$TMPD/ca.pem" --resolve "$VHOST:18587:127.0.0.1" "smtp://$VHOST:18587" \
  --mail-from noreply@vostack.dev --mail-rcpt info@vostack.dev --user "noreply@vostack.dev:$NOREPLY_PW" -T "$TMPD/m3.txt"; then
  ok "5a submission 587 STARTTLS s AUTH"
else
  fail "5a submission 587 STARTTLS"
fi

mkmsg "$RUN alias" postmaster@vostack.dev > "$TMPD/m2.txt"
if curl -s -S --max-time 30 --resolve "$VHOST:18025:127.0.0.1" "smtp://$VHOST:18025" \
  --mail-from "bounce@$RUN.invalid" --mail-rcpt postmaster@vostack.dev -T "$TMPD/m2.txt" 2> "$TMPD/e4.txt"; then
  ok "5b neautentifikovany SMTP 25 prijme postmaster@ (alias)"
else
  fail "5b prijem na postmaster@: $(cat "$TMPD/e4.txt")"
fi

mkmsg "$RUN relay" someone@example.com > "$TMPD/m4.txt"
if curl -s -S --max-time 30 --resolve "$VHOST:18025:127.0.0.1" "smtp://$VHOST:18025" \
  --mail-from "bounce@$RUN.invalid" --mail-rcpt someone@example.com -T "$TMPD/m4.txt" >/dev/null 2>&1; then
  fail "6 OPEN RELAY: neautentifikovany RCPT na example.com bol prijaty"
else
  ok "6 neautentifikovany RCPT na externu domenu odmietnuty (ziadny open relay)"
fi

# pocet sprav s predmetom obsahujucim $RUN v INBOX a Junk Mail (neautentifikovana sprava z neexistujucej
# domeny odosielatela sa spravne moze ulozit do Junk Mail)
imap_count() {
  n=0
  for f in INBOX Junk%20Mail; do
    c=$(curl -s --max-time 30 --cacert "$TMPD/ca.pem" --resolve "$VHOST:18993:127.0.0.1" --user "info@vostack.dev:$INFO_PW" \
      "imaps://$VHOST:18993/$f" -X "SEARCH SUBJECT \"$RUN\"" 2>/dev/null | tr -d '\r' | sed -n 's/^\* SEARCH//p' | wc -w)
    n=$((n + c))
  done
  echo "$n"
}
i=0
FOUND=0
while [ "$i" -lt 20 ]; do
  FOUND=$(imap_count)
  [ "$FOUND" -ge 3 ] && break
  i=$((i + 1))
  sleep 2
done
[ "$FOUND" -ge 3 ] && ok "7 IMAP 993: info@ vidi vsetky $FOUND sprav (submission, STARTTLS, alias postmaster@; INBOX alebo Junk Mail)" || fail "7 IMAP: najdene $FOUND z 3 sprav"

# --- 4. HTTP cez edge ------------------------------------------------------------------------------
edge_curl() { # <host> <argumenty curl>
  h=$1
  shift
  curl -s --max-time 15 --cacert "$TMPD/ca.pem" --resolve "$h:18443:127.0.0.1" "$@"
}
OUT=$(edge_curl autoconfig.vostack.dev "https://autoconfig.vostack.dev:18443/mail/config-v1.1.xml?emailaddress=info@vostack.dev" || true)
if echo "$OUT" | grep -q 'mail.vostack.dev' && echo "$OUT" | grep -q '993'; then
  ok "8 autoconfig cez edge: XML s mail.vostack.dev a 993"
else
  fail "8 autoconfig: $(echo "$OUT" | head -c 200)"
fi
cat > "$TMPD/ad.xml" <<'XML'
<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006"><Request><EMailAddress>info@vostack.dev</EMailAddress><AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>
XML
CODE=$(edge_curl autodiscover.vostack.dev -o "$TMPD/ad-out.xml" -w '%{http_code}' -X POST -H 'Content-Type: text/xml' --data-binary "@$TMPD/ad.xml" "https://autodiscover.vostack.dev:18443/Autodiscover/Autodiscover.xml" || true)
if [ "$CODE" = 200 ] && grep -q 'mail.vostack.dev' "$TMPD/ad-out.xml"; then
  ok "9 autodiscover POST /Autodiscover/Autodiscover.xml: 200 XML"
else
  fail "9 autodiscover: $CODE $(head -c 200 "$TMPD/ad-out.xml")"
fi
OUT=$(edge_curl mta-sts.vostack.dev -D "$TMPD/mtasts.h" "https://mta-sts.vostack.dev:18443/.well-known/mta-sts.txt" || true)
echo "$OUT" | grep -q 'mode: testing' && ok "10a mta-sts.txt: mode testing" || fail "10a mta-sts.txt: $OUT"
grep -qi '^cache-control: no-store' "$TMPD/mtasts.h" && ok "10b mta-sts.txt Cache-Control: no-store" || fail "10b mta-sts Cache-Control"
for path in /admin /api /jmap /; do
  CODE=$(edge_curl autoconfig.vostack.dev -o /dev/null -w '%{http_code}' "https://autoconfig.vostack.dev:18443$path" || true)
  [ "$CODE" = 404 ] && ok "11 https://autoconfig.vostack.dev$path -> 404" || fail "11 autoconfig $path -> $CODE"
done

CODE=$(edge_curl webmail.vostack.dev -c "$TMPD/cj" -o "$TMPD/login.html" -w '%{http_code}' "https://webmail.vostack.dev:18443/" || true)
if [ "$CODE" = 200 ] && grep -q 'rcmloginuser' "$TMPD/login.html"; then
  ok "12a webmail 200 s prihlasovacim formularom"
else
  fail "12a webmail: $CODE"
fi
TOKEN=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$TMPD/login.html" | head -n1)
[ -n "$TOKEN" ] || fail "12b chyba _token v prihlasovacom formulari"
FINAL=$(edge_curl webmail.vostack.dev -L -b "$TMPD/cj" -c "$TMPD/cj" -o "$TMPD/after.html" -w '%{url_effective}' \
  --data-urlencode "_token=$TOKEN" --data-urlencode "_task=login" --data-urlencode "_action=login" \
  --data-urlencode "_timezone=Europe/Bratislava" --data-urlencode "_url=" \
  --data-urlencode "_user=info@vostack.dev" --data-urlencode "_pass=$INFO_PW" \
  "https://webmail.vostack.dev:18443/?_task=login" || true)
case "$FINAL" in
  *_task=mail*) ok "12b Roundcube prihlasenie -> _task=mail (Roundcube -> Stalwart IMAP cez overene TLS)" ;;
  *) fail "12b Roundcube prihlasenie skoncilo na $FINAL" ;;
esac

# realna cf-peer.conf: PROXY zdroj musi byt Cloudflare (web bez permissive override)
MAIL_CF_PEER=$PWD/docker/nginx/http.d/cf-peer.conf dc up -d --no-deps --force-recreate web >/dev/null
sleep 3
WEBIP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$ENET\").IPAddress}}" "$P-web-1")
# curl >= 8.2 (--haproxy-clientip): pouzivame obraz $CURLIMG, nginx:1.30-alpine ma tiez curl 8.22
CURLOPT="-s -k --max-time 10 --haproxy-protocol --connect-to ::$WEBIP:443"
OUT=$(docker run --rm --network "$ENET" "$CURLIMG" $CURLOPT --haproxy-clientip 173.245.48.1 https://webmail.vostack.dev/ || true)
echo "$OUT" | grep -q rcmloginuser && ok "13a priamo na mail-web s PROXY zdrojom z rozsahu Cloudflare: Roundcube login" || fail "13a mail-web s CF zdrojom"
OUT=$(docker run --rm --network "$ENET" "$CURLIMG" $CURLOPT --haproxy-clientip 173.245.48.1 https://mta-sts.vostack.dev/.well-known/mta-sts.txt || true)
echo "$OUT" | grep -q 'mode: testing' && ok "13b priamo na mail-web: mta-sts.txt mode testing" || fail "13b mta-sts cez mail-web"
CODE=$(docker run --rm --network "$ENET" "$CURLIMG" $CURLOPT -o /dev/null -w '%{http_code}' --haproxy-clientip 173.245.48.1 https://autoconfig.vostack.dev/admin || true)
[ "$CODE" = 404 ] && ok "13c priamo na mail-web: /admin -> 404" || fail "13c /admin -> $CODE"
CODE=$(docker run --rm --network "$ENET" "$CURLIMG" $CURLOPT -o /dev/null -w '%{http_code}' --haproxy-clientip 203.0.113.9 https://webmail.vostack.dev/ || true)
[ "$CODE" = 000 ] && ok "13d PROXY zdroj mimo Cloudflare zahodeny (444)" || fail "13d zdroj mimo CF -> $CODE"
# presne prikaz z runbooku (krok 10d): nginx:1.30-alpine ma curl >= 8.2 a edge siet vie rezolvovat mail-web
OUT=$(docker run --rm --network "$ENET" --network-alias runbook-check --entrypoint curl nginx:1.30-alpine -sk --max-time 10 \
  --haproxy-protocol --haproxy-clientip 173.245.48.1 --connect-to ::"$P-web-1":443 https://webmail.vostack.dev/ || true)
echo "$OUT" | grep -q rcmloginuser && ok "13f runbook 10d: nginx:1.30-alpine curl --haproxy-clientip funguje" || fail "13f runbook 10d prikaz"
# runbook krok 10c sa spolieha na tento riadok v logu
dc logs --since 1m web 2>&1 | grep -q 'host=webmail.vostack.dev .* 444' && ok "13e log webu ukazuje host=webmail.vostack.dev so stavom 444" || fail "13e log webu bez host=webmail 444"
dc up -d --no-deps --force-recreate web >/dev/null

# --- 5. cert-renew (default rezim), zaloha, obnova -------------------------------------------------
sh scripts/mail-cert-renew.sh >/dev/null 2>&1 && ok "14 mail-cert-renew.sh (renew + kopia + ReloadTlsCertificates + reload nginx)" || fail "14 mail-cert-renew.sh"

sh scripts/mail-backup.sh > "$TMPD/backup.out" 2>&1 || { fail "15 mail-backup.sh: $(tail -3 "$TMPD/backup.out")"; }
ARCH=$(sed -n 's/^OK: //p' "$TMPD/backup.out")
if [ -n "$ARCH" ] && [ -f "$ARCH" ] && [ -f "$ARCH.sha256" ] \
  && [ "$(stat -c %a "$ARCH")" = 600 ] && [ "$(stat -c %a "$ARCH.sha256")" = 600 ]; then
  ok "15a zaloha $(basename "$ARCH") (0600) a .sha256"
else
  fail "15a zaloha: $(cat "$TMPD/backup.out")"
fi
RUNNING=$(docker inspect -f '{{.State.Running}}' "$P-stalwart-1" "$P-roundcube-1" | tr '\n' ' ')
[ "$RUNNING" = "true true " ] && ok "15b stalwart a roundcube bezia po zalohe" || fail "15b po zaloze: $RUNNING"
i=0
FOUND=0
while [ "$i" -lt 20 ]; do
  FOUND=$(imap_count)
  [ "$FOUND" -ge 3 ] && break
  i=$((i + 1))
  sleep 2
done
[ "$FOUND" -ge 3 ] && ok "15c po zaloze je schranka znova citatelna ($FOUND sprav)" || fail "15c schranka po zaloze: $FOUND"

# obnova do novych volumes a docasneho Stalwartu bez publikovanych portov
docker volume create "$P-restore-etc" >/dev/null
docker volume create "$P-restore-data" >/dev/null
docker run --rm -v "$P-restore-etc:/e" -v "$P-restore-data:/d" -v "$ARCH:/a.tgz:ro" alpine:3.24 \
  sh -c 'tar xzf /a.tgz -C /tmp ./stalwart-etc ./stalwart-data && cp -a /tmp/stalwart-etc/. /e/ && cp -a /tmp/stalwart-data/. /d/'
docker run -d --name "$RESTORE" --network "$NET" --network-alias restore-stalwart \
  -v "$P-restore-etc:/etc/stalwart" -v "$P-restore-data:/var/lib/stalwart" -v "$TMPD/tls:/opt/stalwart-tls:ro" \
  stalwartlabs/stalwart:v1.0.1 >/dev/null
i=0
RESTORED=""
while [ "$i" -lt 30 ]; do
  RESTORED=$(STALWART_URL=http://restore-stalwart:8080 sh scripts/mail-provision.sh --cli query Account 2>/dev/null || true)
  echo "$RESTORED" | grep -q 'info@vostack.dev' && break
  i=$((i + 1))
  sleep 2
done
echo "$RESTORED" | grep -q 'info@vostack.dev' && echo "$RESTORED" | grep -q 'noreply@vostack.dev' \
  && ok "16 obnova zo zalohy: ucty info@ a noreply@ existuju v obnovenom Stalwarte" || fail "16 obnova zo zalohy"

[ "$FAILED" = 0 ] || exit 1
echo "Mail self-test hotovy"
