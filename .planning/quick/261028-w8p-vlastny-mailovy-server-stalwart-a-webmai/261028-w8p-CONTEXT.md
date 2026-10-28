# Quick Task 261028-w8p: Vlastný mailový server (Stalwart) a webmail - Context

**Gathered:** 2026-10-28
**Status:** Ready for research/planning

<domain>
## Task Boundary

Plný mailový server so schránkami na existujúcom Hetzner serveri 2.31.74.48 (Ubuntu 26.04, Docker, 3.8 GB RAM, ~3 GB voľných), vedľa produkcie a stagingu task-trackera a zdieľaného edge nginx (/opt/edge, SNI passthrough + PROXY protocol, sieť `edge` 10.250.250.0/24). Aplikácia (fáza 7) bude cez tento server posielať e-maily.

</domain>

<decisions>
## Implementation Decisions (vlastník)

### Softvér
- Stalwart (all-in-one: SMTP, IMAP, JMAP, antispam, DKIM, web admin), Docker, pripnutá verzia.

### Doména a schránky
- Doména: vostack.dev (dnes `MX 0 .`, `SPF -all`, `DMARC p=reject` → treba zmeniť).
- Schránky: info@vostack.dev, noreply@vostack.dev.
- Aliasy na info@: postmaster@, abuse@, dmarc@ (DMARC rua reporty).

### Prístup
- IMAP klienti (iPhone Mail, Outlook, Thunderbird) + autoconfig/autodiscover.
- Webmail na webmail.vostack.dev (návrh SnappyMail, ľahký), cez Cloudflare (proxied) a existujúci edge proxy.

### Fakty zistené orchestrátorom
- Odchádzajúce porty 25/465/587 zo servera sú otvorené.
- PTR je predvolený (static.48.74.31.2.clients.your-server.de) → vlastník nastaví v Hetzner konzole na mail.vostack.dev.
- Hetzner Cloud Firewall: dnes 80/443 iba z Cloudflare IP, 22 otvorený; pre poštu treba otvoriť 25, 465, 587, 993 (vlastník v konzole).
- mail.vostack.dev musí byť DNS-only (sivý oblak) → odhalí origin IP; web ostáva chránený firewallom (80/443 iba CF).
- Cloudflare API token so Zone:DNS:Edit pre vostack.dev je na serveri v /opt/work-tracker/secrets/cloudflare.ini (LE DNS-01 už funguje, certbot/dns-cloudflare).
- Root operácie: `ssh my-server-agent` (aiagent, sudo); aplikačné ako `deploy`.

### Claude's Discretion
- Webmail softvér (SnappyMail vs Roundcube), umiestnenie Stalwart web admin (napr. mail.vostack.dev cez edge alebo iba cez SSH tunel), TLS pre SMTP/IMAP (LE DNS-01 certifikát pre mail.vostack.dev a reload), zálohy maildatabázy (restic plán z projektu, zatiaľ lokálny dump), limity pamäte kontajnerov, MTA-STS/TLS-RPT voliteľne.

</decisions>

<specifics>
## Specific Ideas
- Všetko v repozitári ako ops (napr. compose.mail.yaml, docker/mail/, docs/ops/mail.md), inštalované ručne raz ako edge (/opt/mail), nie cez CI.
- Outward kroky (DNS, firewall, PTR, štart na serveri, heslá schránok) vykoná orchestrátor/vlastník až po potvrdení.
</specifics>

<canonical_refs>
## Canonical References
- docs/ops/server.md (sekcie 1, 4 firewall, 17 edge, 18 cut-over)
- docker/edge/nginx.conf, compose.edge.yaml
</canonical_refs>
