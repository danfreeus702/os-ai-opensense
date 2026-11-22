# Networking for Beginners: A Hands-On Guide to How Networks Really Work

**Daniel Freeman (danfreeus702)**
**Version 1.0 — complete**

*Companion to the open-source OPNsense AI project: https://github.com/danfreeus702/os-ai-opensense*

---

## How to use this book

You do not need a degree, a rack of servers, or expensive gear. You need one
computer, a home router, and curiosity. Every chapter builds on the previous
one, and the commands at the end can be tried on almost any laptop.

Read the first eight chapters in order. After that, use it as a reference:

- **Chapters 1–3** explain the mental model: devices, addresses and subnets.
- **Chapters 4–7** explain how data actually moves: switches, routers, DNS and DHCP.
- **Chapters 8–9** cover safety and Wi-Fi.
- **Chapters 10–12** are your troubleshooting toolkit and glossary.

A note on examples: private addresses such as `192.168.1.10` and
`10.0.0.5` are used throughout. They are reserved for home and office
networks and cannot be routed on the public internet. Public addresses in
examples are illustrative only.

---

## Chapter 1 — What a network actually is

A network is a set of devices that can exchange information. That is the whole
idea. Everything else — routers, DNS, firewalls, Wi-Fi — exists to make that
exchange reliable, secure and easy to manage.

Think of it as a postal system:

| Postal system | Network |
|---|---|
| House address | IP address |
| Street | Network segment / subnet |
| Post office | Router / switch |
| Address book | DNS |
| Delivery van | Packet |
| House number on the door | MAC address |

Each device has **two kinds of identity**:

1. A **MAC address** (Media Access Control) — a hardware-like label used on the
   local network. Example: `a4:5e:60:12:34:56`.
2. An **IP address** — a logical address used to find a device across networks.
   Example: `192.168.1.20`.

Local delivery uses the MAC address. Long-distance delivery uses the IP
address. Chapter 4 and Chapter 5 show exactly how those hand off.

### The three pieces of gear you already own

- **Modem** — translates your ISP's signal (fibre, cable, DSL) into a network
  connection.
- **Router** — connects your local network to the internet and hands out
  addresses.
- **Switch / access point** — gives more devices a socket or a Wi-Fi radio.

In most homes these are one box. Understanding the roles separately makes
troubleshooting much easier: when the internet dies, you can ask "is the modem
down, is the router confused, or is Wi-Fi the problem?"

### Traffic comes in two flavours

- **Unicast** — one device to one device (web browsing, a video call).
- **Broadcast** — one device to everyone on the local segment (used by ARP and
  DHCP when a device does not yet know where to send something).

Broadcasts stay inside a local network. A router is the boundary that stops
them spreading across the internet.

---

## Chapter 2 — The two models you actually need

People talk about the **OSI model** (seven layers) and the **TCP/IP model**
(four or five layers). You only need to remember the TCP/IP set and where the
common protocols live:

| Layer | What it does | Examples |
|---|---|---|
| Application | The program and its data | HTTP, HTTPS, DNS, SMTP, SSH |
| Transport | End-to-end delivery, ports, reliability | TCP, UDP |
| Internet | Logical addresses and routing | IP, ICMP |
| Link | Local network hardware | Ethernet, Wi-Fi, ARP |

Why does this matter? Because when something breaks, you can name the layer:

- Website loads slowly but DNS resolves → **transport/application** problem.
- `ping 8.8.8.8` works but `ping example.com` fails → **DNS** problem (layer 5).
- `ping` fails to everything → **link or internet** problem (layers 1–3).

Encapsulation is the word for wrapping data at each layer. Your browser creates
an HTTP request; TCP adds a port number and sequence information; IP adds
source and destination addresses; Ethernet adds MAC addresses for the next
hop. Each device on the path reads only the layer it needs, then passes the
packet onward.

### TCP vs UDP in one paragraph

**TCP** is a phone call: it establishes a connection, numbers packets, checks
they arrived, and re-sends missing ones. Web pages, email and file transfers
use TCP. **UDP** is a postcard: no connection setup, no retries, lower delay.
DNS lookups, video streaming and online games often use UDP. If a video frame
is late, showing an older frame is better than waiting for it.

---

## Chapter 3 — IP addresses, subnets and CIDR

An IPv4 address is four numbers from 0 to 255, for example `192.168.1.20`.
It is really a 32-bit number written in a human-friendly form. Part of it
identifies the **network**, and part identifies the **host** (the device).

The split is written with a prefix length: `192.168.1.20/24`. The `/24` means
the first 24 bits are the network portion, leaving 8 bits for hosts:

```text
Network:  192.168.1.0
Hosts:    192.168.1.1 – 192.168.1.254
Broadcast:192.168.1.255
```

Common prefix lengths worth knowing:

| Prefix | Hosts | Typical use |
|---|---|---|
| `/30` | 2 | Point-to-point links |
| `/29` | 6 | Tiny networks |
| `/28` | 14 | Small lab segments |
| `/27` | 30 | Small office |
| `/26` | 62 | Home lab |
| `/24` | 254 | Home / small office |
| `/16` | 65,534 | Large private network |

### Private address ranges

These ranges are reserved for local use and never appear on the public
internet:

```text
10.0.0.0        – 10.255.255.255     (10/8)
172.16.0.0      – 172.31.255.255     (172.16/12)
192.168.0.0     – 192.168.255.255    (192.168/16)
169.254.0.0     – 169.254.255.255    (link-local, "no DHCP")
```

If you see `169.254.x.x` on a device, it failed to get an address from DHCP and
gave itself one. That is a strong clue: check the DHCP server and the cable or
Wi-Fi connection.

### A quick subnet check

For `/24`, the first three octets are the network. For anything else, the
boundary may fall in the middle of an octet. You do not have to do binary
math in your head — use a subnet calculator — but you should know what the
answer means: two devices can talk directly only when they are in the same
subnet. Otherwise they must go through a router.

### IPv6 in one minute

IPv6 uses 128-bit addresses written in hexadecimal, such as
`2001:db8::1`. The huge address space removes NAT, and devices can configure
themselves using router advertisements. You do not need to master IPv6 to
start, but you should know it exists and that many networks run both
protocols at once (dual stack).

---

## Chapter 4 — MAC addresses, switches and ARP

A **switch** connects devices on the same local network. It learns which MAC
address is on which physical port by watching incoming frames, then sends
traffic only to the right port. That is why a switch is smarter than an old
hub, which repeated everything to everyone.

But applications use IP addresses, not MAC addresses. The bridge between them
is **ARP** (Address Resolution Protocol):

1. Your computer wants to send to `192.168.1.1` and sees it is on the local
   subnet.
2. It broadcasts: "Who has 192.168.1.1? Tell 192.168.1.20."
3. The device with that IP replies: "192.168.1.1 is at `aa:bb:cc:dd:ee:ff`."
4. Your computer caches that answer in its **ARP table** and sends the packet.

You can inspect the table:

```sh
arp -a          # Linux, macOS, Windows
```

ARP only works on the local network. Across a router, each hop resolves the
next hop's MAC address separately. That is why a packet's IP addresses stay
the same end-to-end, but its MAC addresses change at every hop.

### Why this matters in real life

- An IP conflict means two devices claim the same IP; ARP tables flip between
  two MAC addresses and connections randomly drop.
- A full or flaky switch port shows up as slow or intermittent traffic.
- "Can't reach the gateway but can reach local devices" often means the
  gateway's ARP entry is stale or the gateway is down.

---

## Chapter 5 — Routing: how packets leave your network

A **router** connects different networks. Your laptop at `192.168.1.20/24`
cannot reach `8.8.8.8` directly because that address is not in its subnet.
It sends the packet to its **default gateway**, usually your home router at
`192.168.1.1`, which forwards it.

View the routing table:

```sh
ip route              # Linux
route -n              # macOS / older Linux
netstat -rn           # Windows
```

A typical home routing table has three entries:

```text
192.168.1.0/24  via 0.0.0.0  dev eth0    # local network
169.254.0.0/16  ...                      # link-local
0.0.0.0/0       via 192.168.1.1          # default route = "everything else"
```

The `0.0.0.0/0` route is the default route. "If no other rule matches, send it
here."

### NAT: why your laptop has a private address

Your ISP gives your router one public IP. Your laptop has a private one.
**NAT** (Network Address Translation) rewrites the source address of outbound
packets to the router's public IP and keeps a table of active connections so
replies can find their way back to the right device.

NAT is not a firewall, but it does mean unsolicited inbound connections from
the internet have no obvious destination inside your network. A real firewall
adds explicit rules.

### Tracing the path

```sh
traceroute 8.8.8.8    # Linux/macOS
tracert 8.8.8.8       # Windows
```

Each line is a router (a "hop") that handled your packet. The time shown is the
round trip to that hop. A sudden jump in latency often identifies where a
slowdown begins.

---

## Chapter 6 — DNS: turning names into addresses

Humans prefer `example.com`; computers prefer `93.184.216.34`. **DNS** is the
distributed phone book that maps one to the other.

When you type a name:

1. Your computer checks its local cache and hosts file.
2. It asks the configured **recursive resolver** (usually your router or your
   ISP) — "What is the IP for example.com?"
3. The resolver walks the hierarchy: root servers → `.com` servers → the
   authoritative server for `example.com`.
4. The answer comes back with a **TTL** (time to live) and is cached.

Common record types:

| Record | Purpose |
|---|---|
| `A` | Name → IPv4 address |
| `AAAA` | Name → IPv6 address |
| `CNAME` | Name → another name (alias) |
| `MX` | Mail server for the domain |
| `TXT` | Free-form text (SPF, verification tokens) |
| `NS` | Authoritative name server |

Lookup tools:

```sh
nslookup example.com         # Linux, macOS, Windows
dig example.com A            # Linux/macOS, more detail
dig example.com MX +short
```

### DNS problems are common and easy to spot

- Website works by IP but not by name → DNS resolution problem.
- Some sites work and others do not → their authoritative server or your
  resolver's cache may be broken.
- Random redirects or ads → possible malicious DNS; check which resolver your
  devices are actually using.

**Plain DNS is unencrypted.**Anyone on the path can see and modify your
queries. Modern options are DNS-over-TLS (DoT) and DNS-over-HTTPS (DoH). On a
network appliance, DoT is often easier to audit because it uses a dedicated
port (853) and can be verified with a hostname. Many issues in home labs come
from devices ignoring the router's DNS settings — for example VPN clients that
keep their own resolver. Checking "what resolver is this device actually
using?" is a high-value habit.

---

## Chapter 7 — DHCP and the devices that make a home network work

**DHCP** hands out IP addresses, subnet masks, gateways and DNS servers. The
exchange is often remembered as **DORA**:

1. **Discover** — client broadcasts "I need an address."
2. **Offer** — DHCP server offers one.
3. **Request** — client asks for that offer.
4. **Acknowledge** — server confirms and records the lease.

Leases expire; renewing halfway through is normal. If a device disappears from
the network and comes back with the same address, that is DHCP remembering it.

### A typical home network

```text
Internet
   |
 Modem
   |
 Router / firewall  ---- DHCP server, DNS forwarder, NAT
   |
 Switch
   |-- Desktop (cable)
   |-- Printer (cable)
   |
 Access point ---- Laptop, phone, TV (Wi-Fi)
```

The **access point** is just a switch with a radio. It bridges Wi-Fi clients
onto the wired network. The **firewall** is usually part of the router and
decides which inbound and outbound connections are allowed.

### Static vs dynamic addresses

Use **DHCP** for laptops, phones and most devices. Use a **static address**
(or a DHCP reservation) for things other devices need to find reliably:
printers, servers, cameras, network storage. A reservation is usually the
best of both worlds: the device still uses DHCP, but the server always gives
it the same address.

---

## Chapter 8 — Ports, protocols and firewalls

An IP address identifies a device; a **port** identifies a service on that
device. Ports range from 0 to 65,535.

| Port | Protocol | Service |
|---|---|---|
| 20/21 | TCP | FTP |
| 22 | TCP | SSH |
| 53 | UDP/TCP | DNS |
| 80 | TCP | HTTP |
| 123 | UDP | NTP (time) |
| 443 | TCP | HTTPS |
| 3306 | TCP | MySQL |
| 3389 | TCP | RDP |
| 8080 | TCP | Alternate web / proxy |

Check listening ports:

```sh
ss -tuln            # Linux
netstat -ano        # Windows
lsof -i -P -n       # macOS
```

### What a firewall actually decides

A **stateful firewall** tracks connections. It allows replies to connections
that originated inside, and blocks unsolicited inbound traffic by default.
Rules usually say: allow protocol X from source Y to destination Z on port P.

Three beginner rules:

1. **Default deny inbound** is a good starting point.
2. Expose only what you need, and only to the networks that need it.
3. Prefer a VPN for remote access instead of opening administration ports to
   the whole internet.

A port being "open" is not automatically a vulnerability — it means a service
is listening. The risk comes from what that service is, how it is configured,
and whether it is patched.

---

## Chapter 9 — Wi-Fi essentials

Wi-Fi has two useful bands:

| Band | Pros | Cons |
|---|---|---|
| 2.4 GHz | Longer range, better through walls | Slower, crowded, only 3 non-overlapping 20 MHz channels (1, 6, 11) |
| 5 GHz | Faster, less interference, many channels | Shorter range, weaker through walls |
| 6 GHz | Very fast, clean spectrum (Wi-Fi 6E/7) | Shortest range, needs compatible gear |

If a device is close to the router, use 5 or 6 GHz. If it is far away or behind
walls, 2.4 GHz may be more stable even though it is slower. On 2.4 GHz, pick
channel 1, 6 or 11 — picking channel 3 overlaps with both 1 and 6 and causes
interference.

### Security basics

- Use **WPA2-AES** or **WPA3**; never WEP or WPA-TKIP.
- Use a long, unique passphrase.
- Keep the router firmware updated.
- Give guests a separate network if your router supports it.
- Turn off WPS if you do not use it.

### Placement beats power

A router in a metal cabinet or at the far corner of the house will perform
badly no matter how expensive it is. Put it central, elevated and away from
microwaves, cordless phones and thick concrete. Mesh systems help large homes,
but wired backhaul is much better than wireless backhaul when you can run a
cable.

---

## Chapter 10 — Troubleshooting toolkit

Work from the bottom up. At each step, ask "does this layer work?"

### 1. Link — is the device connected?

```sh
ip link show                # Linux
ipconfig /all               # Windows
ifconfig                    # macOS (legacy but still present)
```

Look for an interface that is UP with a valid address. A `169.254.x.x`
address means DHCP failed.

### 2. IP — does it have the right address?

```sh
ip addr show
ip route
```

Check the address, prefix length and default gateway. A wrong subnet mask is a
classic cause of "can't reach the internet but can reach local devices."

### 3. Local connectivity — can it reach the gateway?

```sh
ping 192.168.1.1
arp -a
```

If this fails, the problem is on the local network or the gateway is down.

### 4. Internet — can it reach the outside world by IP?

```sh
ping 8.8.8.8
traceroute 8.8.8.8
```

If IP works but names do not, move to DNS.

### 5. DNS — do names resolve?

```sh
nslookup example.com
dig example.com
```

Try a second resolver to see whether the problem is yours or theirs.

### 6. Service — is the port reachable?

```sh
curl -I https://example.com
nc -vz example.com 443
```

`curl -I` shows HTTP headers. `nc -vz` tests a TCP port without sending data.

### 7. Process — what is listening locally?

```sh
ss -tuln
lsof -i -P -n
```

A simple habit: after each change, run one command and record the result. Half
of troubleshooting is knowing what changed.

---

## Chapter 11 — Build a small home lab

You do not need enterprise hardware. A useful first lab is:

```text
Main PC / laptop
   |
Home router (DHCP, DNS, firewall)
   |
Small switch
   |-- Lab machine 1 (192.168.10.50)
   |-- Lab machine 2 (192.168.10.51)
   |
Access point
```

What to practise:

1. Give the lab machines static addresses or DHCP reservations.
2. Ping between them, then ping the router, then the internet.
3. Install a local DNS resolver and point the lab machines at it.
4. Block a domain, then verify from each device that the block works.
5. Take a configuration backup before every change and rehearse restoring it.

Two rules learned the hard way:

- **Change one thing at a time.** If you change DNS, firewall and Wi-Fi at
  once, you will not know which one fixed or broke it.
- **Document everything.** A short note with date, change and result is worth
  more than memory.

If you later add an appliance firewall such as OPNsense, put it between the
lab network and the internet and use the same workflow: baseline, change,
verify, rollback.

---

## Chapter 12 — Glossary and next steps

**ARP** — maps an IPv4 address to a MAC address on the local network.
**CIDR** — the `/24` notation for a network prefix length.
**Default gateway** — the router a device uses for off-subnet traffic.
**DHCP** — automatically hands out IP configuration.
**DNS** — translates names to addresses.
**Firewall** — rules that allow or block traffic.
**Host** — any device with an IP address.
**IP address** — a logical address used across networks.
**LAN** — Local Area Network, the network inside your home or office.
**MAC address** — hardware-level address used on the local link.
**NAT** — rewrites private addresses to a public one.
**Packet** — a small chunk of data with addressing information.
**Port** — a number identifying a service on a device.
**Route** — a rule telling a device where to send traffic.
**Subnet** — a range of IP addresses sharing a prefix.
**TTL** — how long a DNS answer or packet may live.

### Where to go next

1. Learn one appliance firewall well (OPNsense, pfSense or similar).
2. Learn the basics of VLANs to separate lab, guest and IoT devices.
3. Read real packet captures with Wireshark — seeing a TCP handshake once
   teaches more than a dozen diagrams.
4. Keep a lab notebook. Every professional engineer has one.

Networking is learned by doing. Break things in a lab, put them back, and
write down what you learned. That is the whole method.

---

*End of guide. Questions and corrections are welcome via the project's GitHub
repository: https://github.com/danfreeus702/os-ai-opensense*
