# Homelab Runbooks — Sanitized

Engineering notes captured from a Proxmox/OPNsense lab. This staged copy is
**sanitized**: all credentials, tokens, private keys, real IP addresses,
hostnames, MAC addresses, serial numbers, and personal identifiers have been
replaced with placeholders.

Nothing here has been committed or pushed. Replace placeholders locally before
use, and keep filled-in copies out of version control.

## Placeholder convention

| Placeholder | Meaning |
|---|---|
| `<PVE_HOST_IP>` | Proxmox host management IP |
| `<VM_ID>` | Guest VMID used for the device under test |
| `<CT_ID>` | LXC CTID used for the traffic generator |
| `<DUT_IP>` | Device-under-test management IP |
| `<GEN_IP>` | Traffic-generator IP on the isolated test bridge |
| `<TEST_BRIDGE>` | Isolated Proxmox bridge for test traffic |
| `<DS_WORKSTATION_IP>` | DeepSeek/AI workstation IP |
| `<NAS_IP>` | NAS/ZFS-style storage appliance IP |
| `<HERMES_CT_IP>` | Hermes CT IP |
| `<OPS_WORKSTATION_IP>` | Operator workstation IP |
| `<SHIELD_IP>` | Android TV / media device IP |
| `<PROD_FW_IP>` | Production firewall IP (read-only in all notes) |
| `<STORAGE>` | Proxmox storage identifier |
| `<TEMPLATE>` | LXC template volume |
| `<ADMIN_USER>` | Local administrator account name |
| `<HOME>` | User home directory |

## Contents

- `01-proxmox-stress-test.md` — RFC 2544-style firewall stress test,
  persistent traffic generator CT, results, findings, limitations.
- `02-runbooks.md` — operational code words and repair procedures:
  VM power-cycle/sync, boot/shutdown chime, Tailscale container repair,
  DeepSeek key repair, APK backup, VM memory adjustment.

## Safety rules carried forward

1. Treat the production firewall as **read-only** unless explicitly authorized.
2. Never store credentials, tokens, or private keys in repository files.
3. Use `sudo -S` with a credential helper or out-of-band secret store; never
   hard-code passwords in scripts or docs.
4. Keep test networks isolated from production bridges.
5. Before pushing, run a secret scanner (`gitleaks`, `trufflehog`, or equivalent)
   and review `git diff` in full.

## Suggested `.gitignore` entries

```gitignore
.secrets/
auth-keys.md
*.key
*.pem
*.cred
*.env
*.ab
*.sqlite
*.state
*token*
```

## Status

Staged only — repository name and remote will be supplied later.
