---
phase: quick-261110-ukw
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - compose.mail.yaml
  - docker/mail/
  - scripts/mail-backup.sh
  - scripts/mail-cert-renew.sh
  - scripts/mail-provision.sh
  - scripts/mail-selftest.sh
  - docs/ops/mail.md
  - docs/ops/server.md
  - docker/edge/nginx.conf
  - .gitignore
  - compose.server.mail.yaml
autonomous: false
requirements: [QUICK-261110-ukw]
must_haves:
  truths:
    - "Server /opt/mail ma plnu konfiguraciu a cron/skripty nezavisle od repa (overene pouzivatelom pred zmazanim)"
    - "Mail stack subory (Stalwart, Roundcube, unbound, web, mail-* skripty, compose.mail.yaml) nie su v repe"
    - "Klientska vazba ostava: compose.server.mail.yaml, MAIL_NETWORK v deploy.sh, scp v ci.yml, DeployGuardTest"
    - "Runbook nie je stratený: kopia v /opt/mail/README.md a git tag mail-stack-final"
    - "Ziadne dáta ani subory na serveri sa nemazu"
  artifacts:
    - path: "docs/ops/mail.md"
      provides: "kratka poznamka, ze mail stack je spravovany samostatne v /opt/mail, a co app od mailu ocakava"
  key_links:
    - from: "scripts/deploy.sh"
      to: "compose.server.mail.yaml"
      via: "MAIL_NETWORK"
      pattern: "compose.server.mail.yaml"
---

<objective>
Odstranit mailovy stack (Stalwart + Roundcube + unbound + nginx web) z repa task-tracker. Stack nadalej bezi samostatne na serveri v /opt/mail bez vypadku a bez straty dat. Aplikacia zostava iba klientom SMTP (docker siet `mail`, overlay `compose.server.mail.yaml`, SMTP blok v app.env). Nove samostatne repo sa riesi neskor (nie teraz).

Rozhodnutie pouzivatela (locked): "Zatial to iba odstran z repa, a nakonfiguruj webmail samostatne priamo na server, samozrejme existujuce data treba zmigrovat." Data sa nemigruju fyzicky: /opt/mail uz obsahuje volumes aj konfiguraciu (git archive v docs/ops/mail.md sekcia 2). Migracia = overenie, ze server nezavisi od repa, plus ulozenie runbooku na server.

Zistenia z repa:
- Subory na odstranenie: compose.mail.yaml, docker/mail/** (compose.selftest.yaml, nginx, roundcube, stalwart, unbound), scripts/mail-{backup,cert-renew,provision,selftest}.sh, docs/ops/mail.md.
- NEODSTRANOVAT: docker/nginx/realip/edge.conf a docker/nginx/http.d/cf-peer.conf (spolocny zdroj pravdy so stack nginx), compose.server.mail.yaml, scripts/deploy.sh, .github/workflows/ci.yml (scp compose.server.mail.yaml je klientska strana), tests/unit/Scripts/DeployGuardTest.php (testuje len overlay a siet `mail`, nie mail stack), docker/edge/nginx.conf mapu na mail-web:443 (edge je stale v repe a smeruje webmail hosty).
- Na serveri bezia mail-backup.sh a mail-cert-renew.sh z /opt/mail/scripts/ (cron deploy, docs/ops/mail.md sekcia 4 krok 12), kopie su nezavisle od repa; overit v Task 1.
- Dokumentacia: docs/ops/mail.md sa NAHRADI kratkou poznamkou (nie zmaze), lebo docs/ops/server.md (riadok 23, 362, sekcia 20) a docker/edge/nginx.conf na nu odkazuju, a poznamka zachova co app od mailu ocakava (siet `mail`, SMTP host mail.vostack.dev:465, alias `mail-web` v sieti edge). Plny runbook sa skopiruje na server do /opt/mail/README.md a zachova sa v git historii cez tag `mail-stack-final`.
</objective>

<execution_context>
@/home/voslar/projects/task-tracker/.claude/get-shit-done/workflows/execute-plan.md
@/home/voslar/projects/task-tracker/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@docs/ops/mail.md
@scripts/deploy.sh
@compose.server.mail.yaml
</context>

<tasks>

<task type="checkpoint:human-action" gate="blocking">
  <name>Task 1: Overit na serveri, ze /opt/mail je nezavisly od repa, a ulozit tam runbook</name>
  <what-built>Nic sa nemeni. Agent `ssh my-server-agent` nema pristup do /opt/mail ani k docker socketu, preto tieto kroky vykonava pouzivatel (ako `deploy` na serveri). Nic sa nemaze.</what-built>
  <how-to-verify>
    1. Kopia konfiguracie zodpoveda repu (rozdiely ocakavat len v .env/secrets/tls/letsencrypt, ktore v repe nie su). Z korena repa (WSL) spustit:
       `git archive HEAD compose.mail.yaml docker/mail docker/nginx/realip/edge.conf docker/nginx/http.d/cf-peer.conf scripts/mail-provision.sh scripts/mail-backup.sh scripts/mail-cert-renew.sh | ssh deploy@2.31.74.48 'mkdir -p /tmp/mailcmp && tar -x -C /tmp/mailcmp && diff -r /tmp/mailcmp /opt/mail 2>&1 | grep -v "^Only in /opt/mail" ; rm -rf /tmp/mailcmp'`
       Vystup bez riadkov "differ" a "Only in /tmp/mailcmp" = server ma kompletnu kopiu. Ak su rozdiely, server je zdroj pravdy, zachovat ho (nic neprepisovat); poznamenat rozdiely.
    2. Odkial bezia skripty: `crontab -l -u deploy | grep mail` musi ukazovat `cd /opt/mail && sh scripts/mail-...` (nie cestu do /opt/work-tracker ani do klonu repa). `systemctl list-timers 2>/dev/null | grep -i mail` ma byt prazdne alebo tiez /opt/mail. `ls -la /opt/mail /opt/mail/scripts` nema obsahovat symlinky do mimo /opt/mail.
    3. Compose funguje bez repa: `cd /opt/mail && docker compose -f compose.mail.yaml config -q && echo OK` a `docker compose -f compose.mail.yaml ps` ukazuje beziace stalwart, roundcube, web, unbound. (Iba cita, nereštartuje sa nic.)
    4. Neodstranuje sa ziadna siet: `docker network inspect mail edge --format '{{.Name}}'` vypise obe.
    5. Ulozit runbook na server: z korena repa `scp docs/ops/mail.md deploy@2.31.74.48:/opt/mail/README.md` (pridanie suboru, nic neprepisuje).
    6. Rozhodnut, kde sa teraz spravuje webmail pre dalsie projekty: ostava v /opt/mail (host webmail.vostack.dev cez edge `mail-web:443`), ziadna zmena konfiguracie teraz.
  </how-to-verify>
  <resume-signal>Napis "approved" (body 1 az 5 v poriadku, prip. zoznam rozdielov z bodu 1) alebo popis problemu. Bez "approved" sa subory z repa nemazu.</resume-signal>
</task>

<task type="auto">
  <name>Task 2: Zachovat historiu tagom a odstranit mail stack subory z repa</name>
  <files>compose.mail.yaml, docker/mail/, scripts/mail-backup.sh, scripts/mail-cert-renew.sh, scripts/mail-provision.sh, scripts/mail-selftest.sh</files>
  <action>
Najprv vytvor lokalny anotovany tag na aktualnom HEAD: `git tag -a mail-stack-final -m "Posledny stav repa s mail stackom (Stalwart + Roundcube), pred odstranenim quick 261110-ukw"`. Tag nepushuj (pushne pouzivatel). Potom `git rm -r` na: compose.mail.yaml, docker/mail, scripts/mail-backup.sh, scripts/mail-cert-renew.sh, scripts/mail-provision.sh, scripts/mail-selftest.sh. NEODSTRANUJ docker/nginx/realip/edge.conf, docker/nginx/http.d/cf-peer.conf, compose.server.mail.yaml, ani nic v .planning/ (archivy a historia sa neupravuju). Po zmazani over, ze ziadny zivy kod/test/workflow neodkazuje na zmazane subory: `grep -rIn -E "compose\.mail\.yaml|docker/mail|mail-(backup|cert-renew|provision|selftest)" --exclude-dir=.git --exclude-dir=vendor --exclude-dir=.planning .` smie ukazat iba docs/ops/mail.md (riesi Task 3) a pripadne .gitignore/komentare riesene v Task 3. Spusti `vendor/bin/phpunit tests/unit/Scripts/DeployGuardTest.php` (musi prejst, kopiruje compose.server.mail.yaml, ktory ostava).
  </action>
  <verify>
    <automated>cd /home/voslar/projects/task-tracker && git tag -l mail-stack-final | grep -q . && test ! -e compose.mail.yaml && test ! -d docker/mail && test ! -e scripts/mail-backup.sh && test -f compose.server.mail.yaml && test -f docker/nginx/realip/edge.conf && vendor/bin/phpunit tests/unit/Scripts/DeployGuardTest.php</automated>
  </verify>
  <done>Tag mail-stack-final existuje lokalne, mail stack subory su zmazane, klientsky overlay a spolocne nginx konfiguracie ostali, DeployGuardTest prechadza.</done>
</task>

<task type="auto">
  <name>Task 3: Nahradit mail.md poznamkou a opravit odkazy v docs, edge komentaroch a .gitignore</name>
  <files>docs/ops/mail.md, docs/ops/server.md, docker/edge/nginx.conf, .gitignore, compose.server.mail.yaml</files>
  <action>
Prepis docs/ops/mail.md na kratku poznamku v slovencine (do cca 30 riadkov): (a) mail stack (Stalwart, Roundcube, unbound, nginx web, certbot) sa od 2026-11-10 spravuje samostatne na serveri v /opt/mail, nie z tohto repa; slúži aj inym projektom; (b) plny runbook je na serveri v /opt/mail/README.md a v git historii pod tagom `mail-stack-final` (`git show mail-stack-final:docs/ops/mail.md`); (c) co task-tracker od mailu ocakava: externa docker siet `mail` (overlay compose.server.mail.yaml, iba produkcia cez `MAIL_NETWORK=mail` v .env, scripts/deploy.sh to overuje), SMTP blok `email.*` v app.env (host mail.vostack.dev, port 465, odosielatel noreply@vostack.dev, detaily v docs/ops/server.md sekcia 20), edge nginx smeruje webmail/autoconfig/autodiscover/mta-sts hosty na alias `mail-web:443` (docker/edge/nginx.conf), kopia /opt/mail/docker/nginx/realip a http.d/cf-peer.conf pochadza zo spolocneho zdroja v tomto repe; (d) samostatne repo pre mail stack sa rozhodne neskor. Rozhodnutie nechat poznamku namiesto zmazania: odkazy z server.md a edge nginx.conf zostavaju platne a poznamka zachytava klientsku zmluvu.
Potom uprav odkazy: v docs/ops/server.md riadok 23 zmen vetu tak, ze mail stack je spravovany samostatne v /opt/mail (odkaz na mail.md zostava, text "inštalácia, DNS, zálohy a rollback sú v mail.md" nahrad "spravuje sa samostatne, pozri mail.md"), skontroluj riadok 362 a sekciu 20 (kroky o sieti mail a SMTP ostavaju, odstran len tvrdenia, ze skripty/kopie mail stacku su v tomto repe). V docker/edge/nginx.conf uprav komentar (riadky 4 az 5) tak, aby uvadzal "/opt/mail (spravovane samostatne, docs/ops/mail.md)"; mapu hostov nemen. V .gitignore zmen komentar nad /tls/, /letsencrypt/, /secrets/ na "Serverove adresare (certifikaty, tajomstva), nikdy do gitu" a polozky nechaj (staré kopie z /opt/mail by sa nemali dostat do gitu). V compose.server.mail.yaml uprav iba komentar na prvom riadku: "pripoji php do externej siete mail (mail stack bezi samostatne v /opt/mail)". Nemen ziadnu logiku. Skontroluj README.md a docs/ops/*.md na dalsie odkazy na zmazane subory (`grep -rn "mail-selftest\|mail-provision\|compose.mail.yaml\|docker/mail" docs README.md`) a oprav ich na odkaz na docs/ops/mail.md; .planning/ archivy neupravuj.
  </action>
  <verify>
    <automated>cd /home/voslar/projects/task-tracker && ! grep -rIn -E "compose\.mail\.yaml|docker/mail|mail-(backup|cert-renew|provision|selftest)" --exclude-dir=.git --exclude-dir=vendor --exclude-dir=.planning . | grep -v "docs/ops/mail.md" && test $(wc -l < docs/ops/mail.md) -lt 60 && vendor/bin/phpunit tests/unit/Scripts/DeployGuardTest.php && docker compose -f compose.server.yaml -f compose.server.mail.yaml config -q 2>&1 | head -3; true</automated>
  </verify>
  <done>docs/ops/mail.md je kratka poznamka, ostatne dokumenty a komentare neodkazuju na zmazane subory, deploy logika a testy nezmenene a zelene.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| repo -> server /opt/mail | Zmazanie z repa sa nesmie premietnut na server (deploy nema mail stack v scp zozname) |
| app php -> siet `mail` | SMTP klient, tajomstva v app.env, nie v gite |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-ukw-01 | Denial of Service | /opt/mail (data, cron) | mitigate | Task 1 blocking checkpoint overi, ze cron a compose bezia z /opt/mail; v repe sa nic nemaze pred "approved"; ziadny prikaz neriadi down/rm/prune |
| T-ukw-02 | Denial of Service | deploy app (siet mail) | mitigate | compose.server.mail.yaml, deploy.sh, ci.yml scp a DeployGuardTest ostavaju nezmenene, test sa spusta v Task 2 a 3 |
| T-ukw-03 | Information Disclosure | tajomstva mailu v gite | accept | repo ich nikdy neobsahovalo (/opt/mail/secrets generovane na serveri); .gitignore polozky ostavaju |
| T-ukw-04 | Repudiation | strata runbooku | mitigate | kopia v /opt/mail/README.md a tag mail-stack-final |
</threat_model>

<verification>
- Task 1 odsuhlasený pouzivatelom ("approved").
- grep v Task 2/3 nenajde odkazy na zmazane subory mimo mail.md a .planning/.
- DeployGuardTest prechadza; deploy.sh a ci.yml bez zmeny (git diff --stat ich nelistuje).
</verification>

<success_criteria>
Repo neobsahuje mail stack, server /opt/mail nedotknuty a bez vypadku, klientske napojenie aplikacie na siet `mail` funguje rovnako, runbook zachovany (server + tag), dokumentacia konzistentna.
</success_criteria>

<output>
Create `.planning/quick/261110-ukw-odstranit-mailovy-stack-z-repa/261110-ukw-SUMMARY.md` when done
</output>
