# OPNsense AI - Security Wizard

A native OPNsense plugin that puts an AI assistant and a 3-tier security wizard
directly in the firewall web GUI. Describe what you want in plain language; the
assistant returns correct OPNsense configuration — firewall rules, NAT, aliases,
DNS settings — ready to review and apply.

**Cloud LLM only** (OpenRouter primary, NVIDIA NIM fallback). No local/Ollama
backend, so it works on appliances with limited resources.

🔗 **Live repo:** https://github.com/danfreeus702/os-ai-opensense-plugin

---

## The 3-tier Security Wizard

| Level | Features |
|---|---|
| **1 — Ad Blocking, DNS Cache & Antivirus** | Unbound cache + prefetch, DNS blocklists, ClamAV antivirus |
| **2 — Encrypted DNS & Intrusion Prevention** | Everything in 1 + encrypted DNS upstream (DoT) + CrowdSec IDS/IPS |
| **3 — Maximum Security** | Everything in 2 + DNSSEC + second DoT upstream (Quad9) + optional Tailscale exit node |

Every level produces a **reviewable plan** before anything is applied, includes
the necessary firewall rules, and writes an audit log to
`/var/log/aiopensense-security.log`.

> OPNsense 26.7 has no native DNS-over-HTTPS setting in Unbound (only `dot` and
> `forward`), so the "encrypted DNS" tier uses DoT — the same mechanism used on
> the production firewall this was modeled from.

## Architecture

```
OPNsense GUI (Volt/jQuery)
   ├── AI chat panel        → /api/aiopensense/chat/query
   ├── Security Wizard      → /api/aiopensense/securityWizard/{levels,plan,apply,status}
   └── Guides               → /api/aiopensense/guide/*
                ↓
PHP controllers / models (Phalcon MVC)
                ↓
LLMProvider.php  →  OpenRouter  (primary)
                 →  NVIDIA NIM  (failover)
                ↓
configd actions  →  security.py  →  /conf/config.xml (atomic, mode-preserving)
```

## Repository layout

- `src/opnsense/` — plugin source (controllers, models, views, scripts, configd actions)
- `ARCHITECTURE.md` — original design document
- `NOTES.md` — development notes, production-config findings, full test reports
- `analysis/` — gitignored config snapshots (contain secrets; never committed)

## Verification

All three levels were applied through the real OPNsense GUI, then the full
sequence was run again for idempotency: **6/6 runs passed, zero failures**.
Final config values, service health, LLM path, and browser console were all
verified. Full details in [NOTES.md](NOTES.md).

## Why this project

Built as a hands-on portfolio project toward becoming an AWS Cloud Engineer —
Infrastructure as Code thinking, provider abstraction, safe config management,
and automated testing applied to a real network appliance.

Daniel Freeman — CCNA (Cisco, 2006). Networking / Linux / VMs / self-hosted AI.
