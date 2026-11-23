# Operational Runbooks (Sanitized)

All values in angle brackets are placeholders. Fill them in locally; never
commit filled-in files containing credentials or internal identifiers.

## 1. Code word: “up/down”

“up/down” applies **only** to the DeepSeek/AI workstation VM on Proxmox:

1. Start the VM:

```sh
qm start <VM_ID>
```

2. Wait for guest SSH on `<DS_WORKSTATION_IP>`, then sync the memory baseline:

```sh
# from the operator workstation
ssh <ADMIN_USER>@<DS_WORKSTATION_IP> \
  "mkdir -p ~/Documents/DeepSeek && cat > ~/Documents/DeepSeek/AGENTS.md"
< <(cat "$HOME/.config/opencode/AGENTS.md")
```

3. Verify checksum on both sides.

4. Shut the VM back down:

```sh
qm shutdown <VM_ID>
qm status <VM_ID>   # expect: status: stopped
```

Do not apply this code word to any other guest without explicit instruction.

## 2. Proxmox boot/shutdown chime (internal PC speaker)

The host has a legacy PC speaker (`pcspkr`) and no useful software volume
control. Loudness is improved by using frequencies in the beeper’s efficient
range.

### Install

```sh
apt-get update
apt-get install -y alsa-utils beep
```

### Playback script

`/usr/local/bin/pve-chime`:

```sh
#!/bin/sh
LABEL="${1:-test}"
logger -t pve-chime "playing $LABEL chime on internal speaker" 2>/dev/null || true
beep -f 1047 -l 220 -d 30 -n \
     -f 1319 -l 220 -d 30 -n \
     -f 1568 -l 220 -d 30 -n \
     -f 2093 -l 600
```

### systemd unit

`/etc/systemd/system/pve-chime.service`:

```ini
[Unit]
Description=Boot and shutdown chime
After=sound.target alsa-restore.service
Before=shutdown.target
DefaultDependencies=no

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/bin/pve-chime boot
ExecStop=/usr/local/bin/pve-chime shutdown
TimeoutStartSec=30
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
```

```sh
systemctl daemon-reload
systemctl enable --now pve-chime.service
```

## 3. Tailscale container repair on NAS

Symptom: app activated but container not visible in the NAS UI; `tailscaled`
failed to create its tunnel interface.

Root cause: container had `network_mode: host` and `/dev/net/tun`, but no Linux
capabilities. Logs showed `iptables: Permission denied` and
`tstun.New("tailscale0"): operation not permitted`.

Repair outline:

1. Re-create the container under the NAS app-compose manager so UI labels exist.
2. Preserve capabilities:

```yaml
cap_add:
  - NET_ADMIN
  - NET_RAW
```

3. Preserve the state bind mount and entrypoint that starts `tailscaled` and the
   `tailscale web` UI.
4. Bind the web UI to the NAS LAN IP rather than `0.0.0.0` to avoid conflicting
   with `tailscaled`’s own tailnet-IP listener on port 5252.
5. Keep backups of the previous compose file before editing.

Verification: `tailscale status` shows the node logged in; web UI returns HTTP
200 on the LAN address; NAS app list shows the container.

## 4. DeepSeek API-key / web-search issue

Finding: model API keys were valid. Failures came from a stale DeepSeek account
grant (403 on the account API) and a duplicate web process on the harness port.

Repair outline:

1. Remove only the stale account record from the credential store; keep device
   and browser-session records.
2. Kill the duplicate manual web process.
3. Cycle the web service and refresh the local bridge token from the service journal.
4. Verify with an end-to-end model reply.

Web search remained unavailable because it requires a DeepSeek account token or
`DEEPSEEK_API_KEY`. Since no official free API tier exists, a credential must be
supplied by the owner. Never obtain keys from public sources.

## 5. Android TV APK backup

Pull APK files only — no app data or caches.

- Use `pm list packages -f` on the device and parse paths from the **right**
  (package name after the last `=`; paths may contain `==`).
- `adb pull` may fail on adopted-storage/vendor paths; use
  `adb exec-out "cat '<path>'" > destination` as a fallback.
- Store in category subfolders: `User/`, `System/`, `Vendor/`, `APEX/`.
- Write a `manifest.tsv` with category, package, size and source path.
- Mirror to a second host and verify checksums.

## 6. Reducing workstation VM memory

Symptom: Proxmox host memory nearly exhausted because four VMs had almost all
RAM assigned and ballooning was not reclaiming it.

Procedure:

```sh
qm set <VM_ID> --memory 6144
qm shutdown <VM_ID>
sleep 3
qm start <VM_ID>
```

Verify guest memory, swap usage, and dependent services after restart. Do not
reduce production firewall or storage VM memory without explicit approval.
