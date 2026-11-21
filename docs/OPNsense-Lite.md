# OPNsense-Lite — design draft

**Status:** draft / proposal
**Date:** 2026-11-21
**Based on:** `os-ai-opensense` 0.1_1 on OPNsense 26.7

## 1. Vision

OPNsense-Lite is a slimmed-down OPNsense profile that a non-specialist can set up
in **four steps and under ten minutes**. It keeps OPNsense's proven networking
core, but replaces the 150+ item menu tree with a small set of guided tiles and
safe, hardware-aware defaults.

It is not a fork. Phase 1 is a **profile + menu/theme overlay** delivered as a
plugin, so it inherits OPNsense security updates rather than creating a second
distribution to maintain.

**Tagline:** *Secure network in four steps. No networking degree required.*

## 2. Target user

- Home and small-office users with 1–5 people and one ISP connection
- Lab users who want a safe baseline quickly
- Anyone overwhelmed by the full OPNsense GUI

## 3. Non-goals

- No enterprise VPN mesh, BGP, multi-WAN policy routing, or VLAN-heavy designs
- No local/embedded LLM — cloud LLM only, key stored root-only outside config.xml
- No automatic internet-dependent setup: everything must work offline; AI is an aid, not a dependency
- No changes to production firewalls without explicit confirmation and a rollback point

## 4. The four-step setup

| Step | Screen | What the user does |
|---|---|---|
| 1 | **Choose a profile** | Home / Small office / Lab. Picks sane MTU, DNS, DHCP and security defaults. |
| 2 | **Network basics** | Confirm detected WAN/LAN NICs, set LAN subnet and admin password. Auto-detect link speeds. |
| 3 | **Security level** | L1 ad blocking + DNS cache + ClamAV; L2 adds DoT + CrowdSec; L3 adds DNSSEC + second DoT + optional exit node. |
| 4 | **Review & apply** | Plain-language diff, explicit confirm, automatic config snapshot for rollback. Done. |

After step 4 the user lands on a **single status screen**: Internet OK, DNS OK,
blocked ads today, active protection level, and one "Ask the assistant" box.

## 5. Feature matrix

### Keep and promote
- Dashboard with a four-tile summary (Internet / DNS / Protection / Updates)
- Firewall rules (simplified view), NAT (port-forward only), DHCP (one subnet)
- Unbound DNS with blocklists, DNS cache, DoT, DNSSEC
- ClamAV and CrowdSec as opt-in toggles driven by the security level
- WireGuard **client** with a single "connect to exit node" switch
- Firmware status and a single "Check for updates" button
- Root-only API key file; audit log; config snapshot/rollback

### Simplify
- AI Assistant: docs-grounded, hardware-aware, 3 quick prompts, no raw prompt editing
- Guides: three 3-step quick guides instead of a long catalogue
- Certificates: auto self-signed only; advanced CA UI hidden
- Logs: one combined "Recent events" page instead of per-daemon log pages

### Hide behind "Advanced (not recommended)"
- IPsec / multi-WAN / NPTv6 / one-to-one NAT / traffic shaping
- Tunables, advanced routing, HA, snapshots manager, mirror selection
- Full 157-link menu tree, per-service settings pages, raw config editor

### Remove from the Lite profile
- Ollama / local-model settings (cloud LLM only)
- Guide progress storage complexity (replace with a single completion flag)
- Duplicate DNS stacks: pick **Unbound + Kea** (or Unbound + dnsmasq for home) per profile, never both
- Manual per-rule advanced options; expose only allow/block + port + description

## 6. Hardware-aware recommendations

The AI layer already reads CPU model/core count, RAM, disk, version, interface
names and link status from the appliance. In Lite it must use those facts to
choose defaults, for example:

- < 2 GB RAM → L1 only; skip ClamAV on-access scanning; smaller DNS cache
- 2–4 GB RAM → L2 supported; ClamAV scheduled scans only
- > 4 GB RAM → all levels; larger cache; CrowdSec + ClamAV together
- Single NIC → warn and disable LAN-side protections that require a separate interface
- 100 Mbit link → conservative blocklist size and no DNSSEC validation overhead unless requested

Every answer must cite `docs.opnsense.org` and state assumptions when hardware
data is missing.

## 7. Safety model

1. Every applying action goes through configd with an explicit `confirm=yes`.
2. A config snapshot is written before any change; "Undo last change" is always visible.
3. Firewall changes are validated (ports/ranges/descriptions) before writing.
4. No destructive Power actions in the guided UI; reboot only via a separate confirm dialog.
5. No secrets in config.xml or logs; API key stays in `/conf/aiopensense.key`, mode 600.

## 8. Package set (slim)

Core OPNsense + `os-ai-opensense` only. No Suricata UI, no extra themes, no
third-party package repo by default. Optional add-ons install from one curated
list: CrowdSec, ClamAV, WireGuard.

## 9. Lite menu map

```
Home
  Status
  Protection
  Internet / DNS
  Ask AI
Setup (first run only)
Advanced (collapsed, warning)
Help
Power  (Reboot / Power Off — confirmation only)
```

~8 items instead of 157.

## 10. Implementation phases

1. **Profile overlay** — hide advanced menu nodes, install a Lite dashboard and four-step wizard. Reuse the existing `os-ai-opensense` package and configd actions.
2. **Smart defaults** — profile-based defaults + hardware-aware recommendations in the AI layer.
3. **Rollback** — snapshot before apply + one-click undo.
4. **Frozen image (optional)** — an install ISO with the overlay preinstalled, only if phase 1–3 prove the profile is stable.

## 11. Acceptance criteria

- New user reaches a protected state in <= 4 clicks and < 10 minutes
- L1/L2/L3 apply with zero failures and correct level marker (already proven in the parent plugin)
- No advanced menu items visible without deliberately opening "Advanced"
- Every AI answer references official docs and adapts to detected hardware
- Zero console errors on every Lite page; zero secrets in config.xml or logs
- Full menu sweep of the Lite profile passes with no PHP/404 errors

## 12. Risks / open questions

- Hiding menu nodes must not break ACL or upgrade paths — test on a major upgrade.
- Docs-grounded answers still need internet; bundle a small offline doc excerpt index for the offline case.
- Cloud LLM cost/latency — keep context small and redact hostnames if desired.
- Whether to ship a frozen ISO or only the overlay plugin (decide after a pilot).
