# No-Leak DNS for Homelabs

**Daniel Freeman (danfreeus702)**
**Version 1.0 — complete**

*Companion to the open-source OPNsense AI project: https://github.com/danfreeus702/os-ai-opensense*

---

## Why this guide exists

You set a DNS server on your router, yet a laptop, phone or VPN client keeps
asking someone else. That is a DNS leak: a device bypasses the resolver you
chose. The consequence is not just privacy. It means your split-DNS rules,
blocklists and internal names stop being reliable.

This guide shows how to make **one resolver authoritative**, stop the leaks,
and prove the result. It is written for a home lab, but the method applies to
any small network.

---

## Chapter 1 — How DNS leaks happen

A device can get its DNS settings from several places, and later settings win:

1. **DHCP** — the router hands out a resolver address.
2. **Local override** — a user typed a resolver into the OS network settings.
3. **VPN client** — many VPNs push their own resolver, sometimes globally.
4. **Browser DoH** — Firefox or Chrome can bypass the OS resolver entirely.
5. **Containers** — Docker may use its own embedded DNS and forward elsewhere.
6. **OS features** — multicast DNS, LLMNR or NetBIOS name resolution can make
   traffic appear to "leak" even when unicast DNS is correct.

The fix is not to chase each device forever. The fix is to decide that only one
resolver is allowed, enforce that at the network boundary, then repair the few
clients that still bypass it.

---

## Chapter 2 — Map what is happening now

Before changing anything, collect facts.

On Linux:

```sh
cat /etc/resolv.conf
resolvectl status
dig example.com +short
```

On macOS:

```sh
scutil --dns | head -40
dig example.com +short
```

On Windows (PowerShell):

```powershell
Get-DnsClientServerAddress
Resolve-DnsName example.com
```

On your firewall or router, export the current configuration and note:

- the DHCP DNS option,
- any DNS forwarder or resolver service,
- firewall rules that allow TCP/UDP 53 or 853,
- whether a VPN is advertising DNS settings.

Write these down. You will need them if you roll back.

---

## Chapter 3 — Choose the one resolver

Pick a single address that every device should use. In this guide it is a
local resolver at `192.168.10.140`. Substitute your own address.

Decide the policy in plain language:

> Only 192.168.10.140 may answer DNS queries for LAN clients. All other
> outbound DNS is blocked. The resolver itself may talk to upstream servers
> over encrypted DNS.

That last sentence matters. If you block everything, the resolver cannot
resolve anything. Allow the resolver, block everyone else.

### Encrypted upstream

Plain DNS is unencrypted. Prefer DNS-over-TLS (DoT) from the resolver to its
upstream, using a verified hostname on port 853. On many firewall platforms
DoT is easier to audit than DNS-over-HTTPS because it uses a dedicated port.
If your platform only supports plain upstream DNS, that is still better than
leaking every client to arbitrary resolvers — just be aware of the tradeoff.

---

## Chapter 4 — Enforce at the network boundary

Two rules do most of the work:

1. Allow TCP/UDP 53 from LAN to the chosen resolver.
2. Block TCP/UDP 53 and 853 from everything else to the internet.

Example with nftables on a Linux router:

```sh
nft add table inet dns-policy
nft add chain inet dns-policy forward '{ type filter hook forward priority 0; }'
nft add rule inet dns-policy forward ip daddr 192.168.10.140 udp dport 53 accept
nft add rule inet dns-policy forward ip daddr 192.168.10.140 tcp dport 53 accept
nft add rule inet dns-policy forward udp dport 53 drop
nft add rule inet dns-policy forward tcp dport 53 drop
nft add rule inet dns-policy forward udp dport 853 drop
nft add rule inet dns-policy forward tcp dport 853 drop
```

On OPNsense the same idea is implemented as firewall rules on the LAN
interface: a pass rule to the resolver, then reject rules for port 53 and 853
to any other destination. Put the pass rule first.

### Optional: redirect instead of block

A transparent NAT redirect sends wayward queries to your resolver:

```sh
iptables -t nat -A PREROUTING -i lan0 -p udp --dport 53 \
  -j DNAT --to-destination 192.168.10.140:53
```

Redirecting is friendlier for guests, but it hides misconfiguration. For a lab
where you control every device, blocking is clearer — the broken device
announces itself.

---

## Chapter 5 — Repair the clients

### Tailscale

A tailnet can advertise its own DNS. If you want the device to keep using your
LAN resolver, disable that:

```sh
tailscale up --accept-dns=false
```

Check the effective setting with `tailscale status --json` or the admin
console. For an exit node, this is the classic cause of "DNS still goes to the
old resolver".

### Browsers

Firefox has its own DNS-over-HTTPS setting. Chrome may use "Secure DNS"
depending on the OS and provider. Turn these off if the OS resolver must be
authoritative, or point them explicitly at your resolver where supported.

### Containers

Docker runs an embedded resolver and forwards to the host's configured
servers. Set DNS explicitly:

```sh
docker run --dns 192.168.10.140 ...
```

For Compose:

```yaml
services:
  app:
    image: example/app
    dns:
      - 192.168.10.140
```

### Static clients

Any device with a hand-typed DNS address must be changed. Search your notes,
not just the router — the most common leak is a server someone configured
once and forgot.

---

## Chapter 6 — Verify the result

Verification is not optional. A rule that looks right in a GUI may not match
the traffic.

Query through the chosen resolver:

```sh
dig @192.168.10.140 example.com +short
```

Look for traffic to other resolvers:

```sh
tcpdump -ni lan0 'port 53 or port 853'
```

Run a public leak test in a browser, and check that only your expected
resolver appears. Then test from a VPN client and a container — those are the
usual survivors.

A quick checklist:

```text
[ ] DHCP hands out only 192.168.10.140
[ ] Firewall blocks 53/853 to other destinations
[ ] Resolver itself can reach upstream (DoT if available)
[ ] Tailscale accept-dns=false where required
[ ] Browser DoH disabled or pointed at the resolver
[ ] Containers use --dns / dns: setting
[ ] Leak test shows only the expected resolver
```

---

## Chapter 7 — Rollback and maintenance

Take a configuration snapshot before applying rules. If your platform has a
one-click undo, use it. Otherwise export the config file and keep a dated copy
outside the firewall.

Change one thing at a time. If you change the resolver, the firewall and the
VPN client in one sitting, a failure is hard to attribute.

After any firmware update or VPN client upgrade, repeat the verification in
Chapter 6. Vendors love to reintroduce "convenient" DNS defaults.

---

## Chapter 8 — Quick reference

```text
53/udp, 53/tcp   plain DNS
853/tcp          DNS-over-TLS
443/tcp          DNS-over-HTTPS (hard to block by port alone)
```

If you must block DoH, use a firewall rule with TLS inspection, an allow-list
of approved DoH endpoints, or policy-based management on managed devices.
Blocking port 443 is not an option.

**Remember:** the goal is not "no DNS traffic". The goal is "all DNS traffic
goes through the resolver I chose, and I can prove it."

---

*End of guide. Corrections welcome via https://github.com/danfreeus702/os-ai-opensense*
