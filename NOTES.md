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

---

## 2026-11-21 — Competitive roadmap: the two highest-value additions

These are **planned, not implemented**. Target market today is residential /
SOHO; these two features are what would make a corporate move credible.

### 1. On-prem / private LLM option

Goal: zero firewall metadata leaving the appliance. Currently `LLMProvider.php`
only talks to OpenRouter and NVIDIA NIM over the internet.

Required work:

- **Third provider preset `local`** in `LLMProvider::PRESETS` with configurable
  `api_endpoint` (e.g. `http://<llm-host>:11434/v1`, llama.cpp, vLLM, LM Studio).
  Ollama's `/v1/chat/completions` is OpenAI-compatible — no code fork needed.
  Optional API key stays in `/conf/hermes.key`; local mode can be key-less.
- **Model capability gate** — a `local` preflight probe: does the model return
  valid JSON for rule parsing? Small models (1B–3B) hallucinated structured
  output in earlier testing; require a minimum context/tool-capable model or
  fall back to the existing heuristic parser in `FirewallRulesService`.
- **Egress rule automation** — the wizard must add a rule allowing the
  appliance to reach the LLM host, scoped to that single IP/port (not `any`).
- **Timeout/tuning** — local inference is slower; expose `timeout` per provider
  (already a config field) with a higher default for `local`, plus streaming
  support so the GUI doesn't appear frozen.
- **Privacy documentation** — exactly what context is sent (rule counts,
  interface names, gateway IPs, installed plugins), what is never sent
  (credentials, keys, VPN secrets), retention, and a `send_context=0` switch
  (already implemented) that must remain honored in local mode.
- **Resilience** — if the LLM host is unreachable, fall back to the heuristic
  parser and tell the user which path produced the answer; never silently
  apply AI-generated rules from an unverified model.
- **Testing** — bring up the existing Ollama host (`.150`, model `gemma4:26b`
  already pulled — **do not pull models**), switch the profile to `local`,
  re-run all 3 wizard levels and the rule parser against it.

Competitive value: private, offline, no subscription — the main objection from
any security-conscious buyer disappears.

### 2. Rollback and semantic diff review

Goal: make every wizard change reviewable and reversible. OPNsense already has
generic config backups, so to be competitive this must be wizard-aware and
one-click, not just a raw XML file.

Required work:

- **Pre-apply snapshot** — a configd `[backup]` action copying
  `/conf/config.xml` to `/conf/hermes/backups/config-<ts>.xml` before any apply,
  preserving `644 root:wheel`, with a checksum and metadata
  (level, timestamp, who applied). Keep last N (e.g. 10).
- **Semantic diff** — a controller helper that compares two snapshots and
  reports *changed field paths* in the wizard's sections only
  (`unboundplus`, `clamav`, `crowdsec`, `firewall`, `hermes`, `tailscale`):
  e.g. `unboundplus/general/dnssec: 0 -> 1`, `+ rule: AI-TEST allow DNS`.
  Show this in the plan/review step, not a raw XML dump.
- **Rollback action** — configd `[rollback]` restore: validate candidate XML
  first, write atomically with preserved mode/owner, then reload services via
  `configctl` (filter, unbound, clamav, crowdsec) and verify health.
  Accept only wizard-created snapshots (manifest check) and require
  `confirm=yes`.
- **Automatic rollback on partial apply** — today `applyAction` returns
  `status: partial` with per-step results but leaves the box half-configured.
  On any failed step, restore the pre-apply snapshot automatically and report
  which step failed. This is the single most important reliability feature.
- **Concurrency lock** — prevent apply/rollback overlapping (lock file) so a
  rollback can't race an in-flight apply.
- **UI** — a "Snapshots" panel in `security.volt`: list with level/time, diff
  preview button, one-click rollback button, and the current `applied_level`
  highlighted.
- **Audit** — log backup / apply / rollback with identity to
  `/var/log/hermes-security.log`; exportable report for compliance.
- **Testing** — apply L1 -> snapshot -> apply L2 -> diff shows only DoT/CrowdSec
  changes -> rollback to L1 snapshot -> verify values reverted and services
  healthy; plus bad-snapshot rejection and partial-apply auto-rollback tests.

Competitive value: safe, auditable change management on a firewall — the thing
that keeps a corporate security team from rejecting an AI-driven wizard.

### Suggested order

1. Snapshot + automatic rollback on partial apply (smallest change, biggest
   safety win)
2. Semantic diff in the review step
3. On-prem LLM preset + capability probe
4. Full rollback UI and audit export

Keep the existing market positioning until both are shipped and tested; then
revisit the corporate pitch.
