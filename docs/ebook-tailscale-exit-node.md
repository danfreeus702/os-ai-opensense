# Tailscale Exit Node on AlmaLinux

**Daniel Freeman (danfreeus702)**
**Version 1.0 — complete**

*Companion to the open-source OPNsense AI project: https://github.com/danfreeus702/os-ai-opensense*

---

## What an exit node does

A Tailscale exit node is a machine that routes your other devices' internet
traffic through itself. From the websites you visit, the traffic appears to
come from the exit node's public IP address instead of your laptop's.

Use cases:

- a stable IP for allow-lists,
- a known country/region for services,
- testing how a site behaves from a different network,
- giving a travel laptop a "home" network location.

This guide builds a small AlmaLinux 9 virtual machine as an always-on exit
node. No public inbound administration ports are needed — you manage it over
Tailscale itself.

---

## Chapter 1 — What you need

- AlmaLinux 9 (minimal install is fine), VM or small dedicated machine.
- A public IP address and outbound internet access.
- A Tailscale account with admin access to the tailnet.
- Root or sudo on the AlmaLinux host.
- Ports: UDP 41641 outbound/inbound for the WireGuard-based tailnet.

Example names used below:

```text
Hostname:     alma-exit-node
Tailscale IP: 100.x.y.z          (assigned by Tailscale)
LAN to advertise: 192.168.10.0/24 (optional; only if you need it)
```

Never paste real auth keys into documentation or chat. Use short-lived
pre-auth keys and rotate them after use.

---

## Chapter 2 — Install Tailscale

Update the system and install Tailscale using the official installer:

```sh
sudo dnf update -y
curl -fsSL https://tailscale.com/install.sh | sh
sudo systemctl enable --now tailscaled
systemctl status tailscaled --no-pager
```

If you prefer the package repository, follow Tailscale's AlmaLinux/RHEL
instructions and pin the version you deploy.

---

## Chapter 3 — Enable IP forwarding

An exit node must forward packets between the tailnet interface and the
internet interface. Create a sysctl file:

```sh
sudo tee /etc/sysctl.d/99-tailscale.conf >/dev/null <<'EOF'
net.ipv4.ip_forward = 1
net.ipv6.conf.all.forwarding = 1
EOF
sudo sysctl --system
```

Verify:

```sh
sysctl net.ipv4.ip_forward net.ipv6.conf.all.forwarding
```

Both should read `1`. This change survives reboot because it is in
`/etc/sysctl.d`.

---

## Chapter 4 — Configure the firewall

With firewalld, enable masquerading so traffic from tailnet clients is
translated to the VM's public IP:

```sh
sudo firewall-cmd --permanent --add-masquerade
sudo firewall-cmd --permanent --add-port=41641/udp
sudo firewall-cmd --reload
sudo firewall-cmd --list-all
```

If you use nftables directly, the equivalent is a masquerade rule on the
outbound interface for traffic from the Tailscale subnet.

Do not expose SSH to the public internet. SSH over Tailscale is enough.

---

## Chapter 5 — Advertise the exit node

Log in and advertise the exit node. Add routes only if you actually need to
reach the LAN behind this machine:

```sh
sudo tailscale up \
  --advertise-exit-node \
  --advertise-routes=192.168.10.0/24 \
  --accept-dns=false
```

Then open the Tailscale admin console:

1. Find `alma-exit-node` in the machine list.
2. Open its menu and approve the **exit node** capability.
3. Approve the advertised routes if you enabled them.
4. Consider disabling key expiry for an always-on infrastructure node, or
   set a reminder before it expires.

Check the result from the VM:

```sh
tailscale status
tailscale exit-node list
```

---

## Chapter 6 — Use the exit node from a client

On a laptop or phone, choose the exit node:

```sh
tailscale up --exit-node=100.x.y.z
```

If you also need to reach devices on the client's own LAN while using the exit
node:

```sh
tailscale up --exit-node=100.x.y.z --exit-node-allow-lan-access
```

DNS is the usual trap. By default a client may keep using its own resolver.
To keep the client's existing DNS configuration, add:

```sh
tailscale up --exit-node=100.x.y.z --accept-dns=false
```

In the Tailscale client UI the equivalents are **Use exit node** and
**Accept DNS**. Match the choice to your DNS policy — an exit node that
changes your resolver can defeat a carefully configured no-leak setup.

To stop using it:

```sh
tailscale up --exit-node=
```

---

## Chapter 7 — Verify the path

From the client:

```sh
curl -4 https://ifconfig.me
curl -4 https://icanhazip.com
traceroute 8.8.8.8
```

The public IP reported should be the exit node's. On the exit node, watch
traffic:

```sh
tailscale status
ss -tunp | grep tailscale
```

If the IP does not change:

- confirm the exit node is approved in the admin console,
- confirm the client is actually using it (`tailscale status` shows the node),
- check that IP forwarding and masquerading are still enabled after a reboot.

---

## Chapter 8 — Operations and security

### ACLs

Use Tailscale ACLs to control which devices may use the exit node. An exit
node is a powerful resource — treat it like a shared internet connection.

### Key rotation

Pre-auth keys expire. Generate them with a short lifetime, use once, then
revoke them in the admin console. Never store a key in a public repo or a
chat message.

### Updates

```sh
sudo dnf update -y
sudo tailscale update
```

After updates, re-run the verification commands. Reboot once and confirm that
forwarding and the tailnet come back automatically.

### Logging

Tailscale logs are available with:

```sh
journalctl -u tailscaled -f
```

Keep the VM time synchronized (`chronyd` or `systemd-timesyncd`). Incorrect
time breaks certificate validation and makes log correlation painful.

---

## Chapter 9 — Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| Exit node not listed | Not approved in admin console | Machine menu → Exit node |
| Connected but no internet | IP forwarding or masquerade missing | `sysctl`, `firewall-cmd --list-all` |
| Different public IP | Client not actually using node | `tailscale status` |
| LAN devices unreachable | Routes not approved or `--exit-node-allow-lan-access` missing | Admin console + client flags |
| DNS suddenly wrong | Client accepted exit-node DNS | `--accept-dns=false` or UI toggle |
| Works until reboot | sysctl file or firewall rule not permanent | `/etc/sysctl.d`, `--permanent` |

---

## Chapter 10 — Decommissioning

When you no longer need the node:

```sh
sudo tailscale down
sudo tailscale logout
```

Remove the machine from the admin console, revoke any pre-auth key, and
disable the VM. If the machine had advertised routes, remove those too.

---

*End of guide. Corrections welcome via https://github.com/danfreeus702/os-ai-opensense*
