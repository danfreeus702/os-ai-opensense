# OPNsense AI Security Wizard: The Practical Guide

**Draft v0.1 — Daniel Freeman (danfreeus702)**
*Companion to the open-source plugin: https://github.com/danfreeus702/os-ai-opensense*

> This is a working draft. Chapters 1–2 are complete; the remaining chapters
> are outlined and will be filled in from the project's NOTES.md test reports.

---

## Table of contents

1. Why a wizard like this exists
2. Quick start: install and your first apply
3. The three levels, explained
4. Encrypted DNS on OPNsense 26.7 (why DoT, not DoH)
5. The AI assistant: docs grounding and hardware awareness
6. Reading the plain-language diff
7. Rollback: snapshots and one-click undo
8. Running the assistant offline
9. Verification: how the plugin was tested
10. Troubleshooting and real-world caveats
11. Appendix A — configd actions and file locations
12. Appendix B — upgrading and uninstalling

---

## Chapter 1 — Why a wizard like this exists

A default firewall is a blank canvas. That is its strength and its weakness.
It can do anything, but the person in front of the GUI has to decide *what*,
*where*, and *in which order*. On OPNsense that means navigating more than 150
menu links across firewalls, DNS, VPN, services and system pages. Most people
either leave the box half-configured or copy settings they do not understand.

The Security Wizard takes a different approach: it asks one question — *how much
protection do you want?* — and then produces a reviewable plan. You still see
every change before it happens, including the exact GUI path where you could
make the same change by hand. The wizard is not a black box; it is a shortcut
with a paper trail.

### The design rules

Four rules shaped the project:

1. **Nothing changes without confirmation.** Every applying action requires an
   explicit `confirm=yes`, and the GUI shows a plain-language diff first.
2. **No secrets in config.xml.** The cloud LLM key lives in a root-only file,
   `/conf/aiopensense.key` (mode 600), so it is not copied into backups.
3. **Every apply has a rollback.** A timestamped config snapshot is written
   before the first change, and a single "Undo last change" button restores it.
4. **Cloud LLM only.** No local model is bundled, so the plugin works on small
   appliances with limited RAM.

### Who this is for

- Home users who want a safe baseline in a few minutes.
- Small offices without a dedicated network engineer.
- Anyone learning OPNsense who wants to see *what a correct configuration looks
  like* before doing it manually.

If you run a complex multi-WAN deployment with BGP and a full IDS stack, this
wizard is not a replacement for your design — it is a clean starting point.

---

## Chapter 2 — Quick start: install and your first apply

### What you need

- OPNsense **26.7** or newer (tested on 26.7.9_3).
- Internet access from the firewall for blocklist downloads and the cloud LLM.
- An API key for OpenRouter (primary) or NVIDIA NIM (fallback).

### Install the package

Download `os-ai-opensense-0.1_1.pkg` from the GitHub releases page, copy it to
the firewall, and install it as root:

```sh
pkg add /tmp/os-ai-opensense-0.1_1.pkg
```

The install hooks restart configd and load the new template. Confirm with:

```sh
pkg info os-ai-opensense
```

### Add the API key (root-only)

```sh
printf '%s\n' 'your-key-here' > /conf/aiopensense.key
chmod 600 /conf/aiopensense.key
```

Never put the key in `config.xml` — that file ends up in backups and support
bundles.

### Open the wizard

In the web GUI, choose **OPNsense AI → Security Wizard**. You will see three
cards. Pick a level; the plan panel opens with a list of steps. Below it is a
**What will change** table with four columns:

- the change,
- its current value,
- the value it will have after applying,
- the exact GUI path where that setting lives.

Read that table. If anything looks wrong, close the page — nothing has changed
yet.

### Apply

Tick any options (the Tailscale exit node is Level 3 only), then click **Apply
selected level**. The confirmation dialog mentions that a rollback snapshot is
saved automatically. After a few seconds you will see one green tick per step.
The result panel also shows the snapshot note.

### Verify

```sh
tail -20 /var/log/aiopensense-security.log
pfctl -sr | grep -E 'domain|https|domain-s'
```

You should see the wizard's DNS and HTTPS rules among the active pf rules, and
the audit log should contain an `OK` line for each action.

### Undo

If you want to revert, click **Undo last change** in the result panel. The
controller restores the last snapshot and reloads the filter rules. Confirm the
applied level afterwards:

```sh
grep -o '<security_level>[0-9]*</security_level>' /conf/config.xml
```

---

*Chapters 3–12: outlined above. Next to draft: "The three levels, explained"
and "Encrypted DNS on OPNsense 26.7 (why DoT, not DoH)" — both can be written
directly from NOTES.md and the model's RESOLVERS/BLOCKLISTS constants.*
