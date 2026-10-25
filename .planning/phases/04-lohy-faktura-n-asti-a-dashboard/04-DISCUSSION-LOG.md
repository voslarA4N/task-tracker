# Phase 4: Úlohy, fakturačné časti a dashboard - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-25
**Phase:** 04-lohy-faktura-n-asti-a-dashboard
**Areas discussed:** žiadne (prevzaté zo špecifikácie)

---

## Postup

| Option | Description | Selected |
|--------|-------------|----------|
| Prevziať zo špecifikácie | Rozhodnutia zo spec/PRD/REQUIREMENTS/výskumu, otvorené body 1–4 ako Claude's Discretion s navrhnutými predvoľbami | ✓ |
| Prediskutovať body 1–4 | Otvorené body jednotlivo, ostatné zo špecifikácie | |

**User's choice:** Prevziať zo špecifikácie.
**Notes:** Navrhnuté predvoľby: (1) dashboard: kalendárny mesiac s prepínaním, posledný klient s voľbou „Všetci“, súčty po menách; (2) „Zapracovaná“ aj bez novej časti pri nulovom zostatku, návrat do „V práci“ povolený; (3) časť si ukladá sadzbu a menu, klient úlohy sa nemení pri existujúcich častiach, mena klienta sa nemení pri nezamknutých častiach; (4) import: „Zapracované = NIE“ sa importuje ako úloha „V práci“ bez časti.

## Claude's Discretion

- Dashboard: mesiac, klient a súčty v rôznych menách
- „Zapracovaná“ bez zostatku a návrat do „V práci“
- Sadzba, klient a mena po vzniku častí
- Riadky „Zapracované = NIE“ v importe
- Tvar `billing_month`, „posledný použitý klient“, rozloženie PC zoznamu, texty, FK `invoice_id`

## Deferred Ideas

Žiadne.
