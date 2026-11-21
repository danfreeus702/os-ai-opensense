# OPNsense AI - Security Wizard — Notes & Test Reports

This file is committed to git on purpose. Session notes, verification results,
and decisions live here so the portfolio repo tells the whole story, not just
the code. Sensitive config snapshots stay in `analysis/` (gitignored).

## Project

Native OPNsense plugin that embeds an AI assistant and a 3-tier security wizard
in the firewall web GUI. Cloud LLM only (OpenRouter primary, NVIDIA NIM
fallback via `LLMProvider.php`). No local/Ollama backend.

Repo: https://github.com/danfreeus702/os-hermes-plugin

---

## 2026-11-21 — Initial cloud rewrite

- Replaced the hardwired Ollama `/api/chat` calls with an OpenAI-compatible
  `LLMProvider` service (OpenRouter + NVIDIA fallback, key from root-only
  `/conf/hermes.key`).
- Fixed 3 pre-existing bugs: invalid XML in the settings form
  (`< equalities>`), `Guide.php` extending `BaseModel` without `Guide.xml`
  (broke migrations), deprecated `curl_close()` on PHP 8.5.
- Added the 3-tier Security Wizard with plan/apply API, configd actions,
  audit log, and a feature-comparison matrix.

## 2026-11-21 — Production config inspection (read-only)

Production `.140` config.xml was copied read-only and analyzed. Key findings:

- DNS lives in `<OPNsense><unboundplus>`, not `<unbound>`.
- **OPNsense 26.7 has no native DoH type** — Unbound supports only `dot` and
  `forward`. Level 2 therefore uses DoT as the encrypted upstream, matching
  production (`1.1.1.1:853`, verify `cloudflare-dns.com`).
- ClamAV and CrowdSec structures were copied verbatim from production:
  - `OPNsense/clamav/general` — 35 fields, e.g. `enabled=1`, `fc_enabled=1`,
    `maxscansize=100M`, `maxfilesize=25M`, mirror `database.clamav.net`.
  - `OPNsense/crowdsec/general` — `agent_enabled=1`, `lapi_enabled=1`,
    `firewall_bouncer_enabled=1`, LAPI on `127.0.0.1:8080`, `rules_enabled=1`.
- Firewall rules: `OPNsense/Firewall/Filter/rules/rule` (59 fields each).
- Tailscale: `OPNsense/tailscale/settings` (`advertiseExitNode`, `acceptDNS=0`).
- Backups: `/conf/config.xml.bak-*`. Production was never modified.

## 2026-11-21 — ClamAV & CrowdSec added

- Level 1 = ad blocking + DNS cache + **ClamAV**.
- Level 2 = Level 1 + encrypted DNS + **CrowdSec**.
- Level 3 = Level 2 + DNSSEC/DoT + optional Tailscale exit node.
- New configd actions `[clamav]`, `[crowdsec]`; `security.py` applies the exact
  production values atomically, preserving config.xml mode/owner
  (`644 root:wheel`) — a wrong mode broke the GUI with `flock(false)` and was
  fixed and verified.

## 2026-11-21 — Full 3-level test suite (ALL PASS)

Run L1 -> L2 -> L3 through the authenticated OPNsense GUI, then re-run the
entire sequence for idempotency:

| Run | Level | Steps | Result | Marker |
|---|---|---|---|---|
| 1 | 1 | 4 | all OK | 1 |
| 1 | 2 | 7 | all OK | 2 |
| 1 | 3 | 10 | all OK | 3 |
| 2 | 1 | 4 | 0 failures | 1 |
| 2 | 2 | 7 | 0 failures | 2 |
| 2 | 3 | 10 | 0 failures | 3 |

Level 3 was tested with the Tailscale option off to avoid changing the live
exit node.

Final config verified on `.50`:

- Unbound enabled, DNSSEC=1, prefetch=1, DNSBL type `atf,ag,sb,hgz005,hgz010,hgz019`
- DoT upstream `1.1.1.1:853` + local forward to dnsmasq
- ClamAV: enabled, freshclam, TCP scan, 100M/25M limits
- CrowdSec: agent, LAPI, bouncer, rules all enabled
- 4 firewall rules, `applied_level=3`, audit FAIL count 0
- Health: configd running, lighttpd up, config.xml valid `644 root:wheel`,
  PHP lint clean, LLM path returned `TESTS_OK`, 3 wizard cards + 8 comparison
  rows, zero browser console errors.

No code changes were needed after the tests.

## 2026-11-21 — Test environment

- VM 302 (OPNsense test `.50`) on Proxmox `.11`.
- Firmware updated `26.7.9_1 -> 26.7.9_3` via `pkg upgrade -y`, no reboot.
- Isolated AI test network built: Proxmox `vmbr50` (no physical port),
  `192.168.50.2/24` on the host; VM 302 `net2` -> `vtnet2`; `.50` `opt2` =
  `192.168.50.1/24` (AI-TEST), firewall block to LAN + allow any.
  Verified `192.168.50.2 <-> 192.168.50.1` ping 0% loss.
- Also fixed a broken `/etc/network/interfaces` on Proxmox `.11` (it held
  CT 104's address and would have broken networking on reboot) by restoring
  the correct PVE config from a backup.

## Conventions

- Configd action files use OPNsense 26.7 colon syntax (`command:`, `type:`).
- Shebang scripts are deployed with mode 755.
- All writes to `config.xml` are atomic and preserve `644 root:wheel` so the
  web GUI (wwwonly) can read it.
- Never commit real credentials; keys live in root-only files or gitignored
  `analysis/` snapshots.
