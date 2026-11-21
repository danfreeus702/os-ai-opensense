# OPNsense AI - Security Wizard — Notes & Test Reports

This file is committed to git on purpose. Session notes, verification results,
and decisions live here so the portfolio repo tells the whole story, not just
the code. Sensitive config snapshots stay in `analysis/` (gitignored).

## Project

Native OPNsense plugin that embeds an AI assistant and a 3-tier security wizard
in the firewall web GUI. Cloud LLM only (OpenRouter primary, NVIDIA NIM
fallback via `LLMProvider.php`). No local/Ollama backend.

Repo: https://github.com/danfreeus702/os-ai-opensense

---

## 2026-11-21 — Initial cloud rewrite

- Replaced the hardwired Ollama `/api/chat` calls with an OpenAI-compatible
  `LLMProvider` service (OpenRouter + NVIDIA fallback, key from root-only
  `/conf/aiopensense.key`).
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
  Optional API key stays in `/conf/aiopensense.key`; local mode can be key-less.
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
  `/conf/config.xml` to `/conf/aiopensense/backups/config-<ts>.xml` before any apply,
  preserving `644 root:wheel`, with a checksum and metadata
  (level, timestamp, who applied). Keep last N (e.g. 10).
- **Semantic diff** — a controller helper that compares two snapshots and
  reports *changed field paths* in the wizard's sections only
  (`unboundplus`, `clamav`, `crowdsec`, `firewall`, `aiopensense`, `tailscale`):
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
  `/var/log/aiopensense-security.log`; exportable report for compliance.
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

## 2026-11-21 — Renamed plugin to os-ai-opensense

All plugin-specific "Hermes" identifiers were renamed to `AIOpenSense` /
`aiopensense` (195 references across code and docs). External references to
the separate Hermes Agent on `.153` (`~/.hermes`, `hermes-gateway`) were left
untouched. Renames:

- Package `os-hermes` -> `os-ai-opensense`; Makefile comment/pkg-descr updated.
- MVC namespace `OPNsense\Hermes` -> `OPNsense\AIOpenSense`; controller/model/
  view/service directories and `Hermes.xml/.php` -> `AIOpenSense.xml/.php`.
- URLs `/ui/hermes/*` -> `/ui/aiopensense/*`, `/api/hermes/*` -> `/api/aiopensense/*`;
  ACL patterns and menu entries updated.
- Scripts `scripts/hermes` -> `scripts/aiopensense`; configd actions file
  `actions_hermes.conf` -> `actions_aiopensense.conf`; command prefix
  `configctl hermes` -> `configctl aiopensense`.
- Config mount `//OPNsense/hermes` -> `//OPNsense/aiopensense`; key
  `/conf/hermes.key` -> `/conf/aiopensense.key`; log
  `/var/log/hermes-security.log` -> `/var/log/aiopensense-security.log`.
- UI branding now "OPNsense AI" / "OPNsense AI - Security Wizard".

Deployment to `.50` removed all old paths, installed new ones, migrated the
`<hermes>` config node to `<aiopensense>` (settings and applied_level preserved)
and copied the key to the new path. Verified: 13 configd actions, all 3 wizard
levels re-tested via the NEW URL (`/ui/aiopensense/security/`) — L1/L2/L3 all
success with 0 failures, correct level markers; old paths gone; config.xml
valid 644 root:wheel; PHP + Python lint clean; LLM path `RENAMED_OK`; zero
browser console errors.

GitHub repo URL to update manually to:
https://github.com/danfreeus702/os-ai-opensense (rename in GitHub settings).

## 2026-11-21 — Firewall rule installation tested & fixed

Found and fixed real bugs in the firewall step:

- The plan claimed explicit rules would be added, but the action skipped
  everything when a default "allow LAN to any" rule existed (no-ops).
- Without that default rule, the legacy action added ALL THREE ports at every
  level and duplicated them on re-apply (no idempotency, no level scoping).
- DNS only covered TCP, not UDP.

Fixed:

- Three separate configd actions: `firewall-dns`, `firewall-doh`, `firewall-dot`
  (`actions_aiopensense.conf`), controller allowlist updated.
- `security.py`: `_firewall_rule()` adds one scoped LAN pass rule, idempotent by
  exact description; DNS adds both TCP 53 and UDP 53; DoH TCP 443; DoT TCP 853.
- Plan text now accurately describes what is installed.

Also hit and fixed a regression during the edit: replacing the firewall block
accidentally deleted `action_clamav`, `action_crowdsec` and `action_record_level`
while the dispatch table still referenced them (all configd actions failed with
`Error (1)`). Restored and verified.

Verification on .50 from a clean slate (0 wizard rules):

| Level | Wizard rules after apply | Expected |
|---|---|---|
| 1 | 2 (DNS TCP 53, UDP 53) | pass |
| 2 | 3 (+ DoH TCP 443) | pass |
| 3 | 4 (+ DoT TCP 853) | pass |
| Re-apply 1/2/3 | still 4, zero duplicates | pass |

All 8 actions returned OK at each level with zero failures. Rules verified in
config.xml (4, exact descriptions/ports) AND in the active pf ruleset:
`port = domain` (53 tcp + udp), `port = https` (443), `port = domain-s` (853).
config.xml valid `644 root:wheel`, configd running, browser console clean.

Note: pf displays well-known ports by NAME (`domain`, `https`, `domain-s`) —
grepping pfctl output for numeric ports gives false negatives.

## 2026-11-21 — Official plugin packaging validated (make package)

Prepared a real OPNsense plugins checkout layout on `.50` (FreeBSD 15, matching
OPNsense 26.7):

- Sparse shallow clone of `github.com/opnsense/plugins` (`Mk`, `net`, `Templates`)
  at `/tmp/opnsense-plugins`.
- Directory `/tmp/opnsense-plugins/net/ai-opensense/` with our `Makefile`,
  `pkg-descr` and `src/` (27 files).

Makefile corrections discovered during the build:

- `PLUGIN_NAME` must be `ai-opensense`, NOT `os-ai-opensense` — the build
  prepends `os-` via `PLUGIN_PREFIX`, so the old value would have produced
  `os-os-ai-opensense`.
- `PLUGIN_DEVEL` must be explicitly EMPTY — the plugins repo's `devel.mk` sets
  it to `yes` by default, producing an unwanted `-devel` suffix.
- Maintainer set to `casainspector66@gmail.com`.
- Sparse checkout must include `Templates/` or `make package` fails with
  "Templates/version: No such file or directory".

Result: `make package` SUCCEEDED:

```
>>> Staging files for os-ai-opensense-0.1_1... done
>>> Packaging files for os-ai-opensense-0.1_1:
file sizes/checksums [28]: . done
packing files [28]: . done
```

Package: `os-ai-opensense-0.1_1.pkg` (29,821 bytes), metadata verified:

- Name `os-ai-opensense`, Origin `opnsense/os-ai-opensense`
- Architecture `FreeBSD:15:amd64`, ABI `26.7`, Category `net`
- Maintainer `casainspector66@gmail.com`
- 28 payload files including all controllers, models, views, scripts,
  configd actions, templates and `/usr/local/opnsense/version/ai-opensense`.

Test-installed with `pkg add` on `.50`: extracted cleanly, stopped/started
configd, reloaded templates (`Reloading template OPNsense/AIOpenSense: OK`).
`pkg info os-ai-opensense` confirms it is now a managed package. GUI smoke test
after install: 3 wizard cards, 8 comparison rows, zero console errors.

The built .pkg is kept locally in the repo root (gitignored via `*.pkg`).
To submit officially: fork `opnsense/plugins`, add `net/ai-opensense/`, and
open a PR; OPNsense maintainers review/sign/publish to the package repo.

## 2026-11-21 — Fresh VM 304 (OPNsense-Test2) test suite

Built a second OPNsense VM on Proxmox `.11` to validate the packaged plugin on
a separate host:

- Full-cloned VM 302 -> VM 304 (`OPNsense-Test2`, 2 GB RAM, disk on local-lvm).
- Moved its only NIC to the isolated `vmbr50` bridge; assigned LAN IP
  `192.168.50.3/24`, gateway `192.168.50.1` (the original test VM).
- Fixed the cloned pf `reply-to` gateway by reloading the filter, and restarted
  sshd with `ListenAddress 192.168.50.3` (it had bound to the old `.50` IP).
- Transferred the built `os-ai-opensense-0.1_1.pkg` via `.11` (the isolated net
  has no route from .16/.153) and did a clean `pkg delete` + `pkg add`.

Bugs found and fixed during this test:

1. **Package installed `security.py` as 0644** — configd returned `Error (126)`
   (not executable). Fixed by `chmod 755` in the repo source and rebuilding the
   package; verified the .pkg now contains `-rwxr-xr-x`.
2. **Controller treated configd rc=0 as success even when output said
   `Error (126)`** — the GUI reported "applied successfully" while every action
   failed. Fixed `runConfigd()` to also flag output beginning with
   `Error`/`Action not allowed`.

Verification after fixes (via SSH tunnel .16:8097 -> .11 -> 192.168.50.3:80,
because the browser tool cannot route to the isolated network):

| Test | Result |
|---|---|
| L1 -> L2 -> L3 | 4/7/10 steps, all OK, zero failures, levels 1/2/3 |
| Re-test L1/L2/L3 | all OK, zero failures, correct level markers |
| Config.xml | level 3; unbound=1, dnssec=1, clamav=1, crowdsec=1 |
| Firewall | 4 wizard rules in config AND 4 active pf rules |
| Package | `os-ai-opensense-0.1_1` installed, hooks fired cleanly |
| GUI | 3 cards, 8 comparison rows, zero console errors |

The single FAIL line in the audit log is from the earlier (pre-fix) failed
attempts on this VM; all post-fix runs are clean.

## 2026-11-21 — Full left-menu sweep (fresh VM 304)

Clicked/navigated every menu item from Dashboard down to Power/reboot/shutdown. Enumerated the sidebar tree and visited all **157** page links across Lobby, Reporting, System, Interfaces, Firewall, VPN, Services, Power, Help and the plugin's own section. Each page was checked for HTTP failure, fatal PHP errors, 404 text and console errors.

Bugs found and fixed:

1. **Configuration Guides returned 404** — the menu links to `/ui/aiopensense/guides/` (plural) but only `GuideController` (singular) existed; OPNsense maps the URL segment to the controller class name. Added `GuidesController.php` as an alias so both `/guides/` and `/guide/view/?id=...` work. Verified: page body went from 197 to 1158 chars.
2. **Only one of three plugin menu entries was visible** — `Menu.xml` reused the same `<AIOpenSense>` tag for all three `<menuitem>` blocks, so the last one overwrote the others. Rewrote it in OPNsense's native hierarchical form with a top-level `OPNsense AI` section containing `Security Wizard`, `AI Assistant` and `Configuration Guides`. All three now appear.

Final verification after rebuild + clean reinstall on VM 304:

| Test | Result |
|---|---|
| All 157 menu links | 200 OK, zero PHP/404/text errors |
| Browser console | zero errors across the full sweep |
| Power > Reboot / Power Off | confirmation pages load; reboot/shutdown NOT executed |
| Plugin menu | 3 entries visible and clickable |
| L1 -> L2 -> L3 re-test | all OK, zero failures, levels 1/2/3 |

### Repeat run 1 (2026-11-21) — update check + full menu sweep

- **Update software check:** System → Firmware → Updates → "Check for updates" returned *"Could not find the repository on the selected mirror."* The VM is on the isolated `vmbr50` test network with no internet route, so this is an environment limitation, not a plugin or GUI fault. No Update/Upgrade action was performed.
- **Menu sweep:** all 157 links visited. First pass flagged 9 core OPNsense pages with empty bodies (2 navigation timeouts). All 9 re-checked individually and loaded correctly — transient browser load timing, not real failures.
- **Result:** zero plugin-related errors; no code changes needed. Reboot/Power Off pages opened only, not executed.
