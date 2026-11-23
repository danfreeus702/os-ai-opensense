# Firewall VM Stress Test (RFC 2544-style)

## Purpose

Validate that a test firewall VM can sustain common network loads without
packet loss, service degradation, or excessive host CPU use, and compare the
method and results against recognized industry standards.

## Standards referenced

| Standard | Scope | How it applies |
|---|---|---|
| RFC 2544 | Throughput, latency, frame loss, back-to-back/burst | Primary benchmark methodology |
| RFC 6349 | TCP throughput over IP paths | Bulk-transfer methodology |
| ITU-T Y.1564 | Ethernet service activation | Availability/loss/jitter context |
| NIST SP 800-41 Rev.1 | Firewall policy and testing guidance | Security-baseline checks |
| NIST SP 800-115 | Technical security testing | Adversarial validation |
| CIS Controls v8 / ISO 27001 / NIST CSF 2.0 | Hardening posture | “Battle-ready” comparison |

A full qualified RFC 2544 run requires a traffic generator and a responder on
the device under test. This run is RFC 2544-*style*: it exercises latency, loss,
burst, connection rate and HTTP load without modifying the firewall VM.

## Topology (sanitized)

- Proxmox host: `<PVE_HOST_IP>`
- Device under test: VM `<VM_ID>` on isolated bridge `<TEST_BRIDGE>`, mgmt IP `<DUT_IP>`
- Traffic generator: LXC `<CT_ID>` on `<TEST_BRIDGE>`, IP `<GEN_IP>/24`
- Production firewall `<PROD_FW_IP>` is **not** involved and remains read-only.

The generator CT is intentionally persistent for future testing. It has no
default route and only one NIC on the test bridge.

## Preconditions

- VM `<VM_ID>` running with guest agent enabled.
- Confirm guest-facing IP from guest agent, not from Proxmox `ipconfig0`:

```sh
qm guest cmd <VM_ID> network-get-interfaces
```

- Confirm TCP reachability from the generator:

```sh
pct exec <CT_ID> -- nmap -Pn -sT -T4 -p 22,80 <DUT_IP>
```

## Creating the persistent generator CT

```sh
PW=$(openssl rand -base64 18)
pct create <CT_ID> <TEMPLATE> \
  --hostname stress-gen \
  --storage <STORAGE> \
  --rootfs <STORAGE>:8 \
  --memory 512 \
  --cores 2 \
  --net0 name=eth0,bridge=<TEST_BRIDGE>,ip=<GEN_IP>/24 \
  --net1 name=eth1,bridge=vmbr0,ip=dhcp \
  --nameserver <PROD_FW_IP> \
  --unprivileged 1 \
  --features keyctl=1,nesting=1 \
  --password "$PW"

pct start <CT_ID>
```

The temporary `net1` NIC is used only to install packages. Install tools, then
remove it so the CT is isolated:

```sh
pct exec <CT_ID> -- bash -c \
  'apt-get update -qq && DEBIAN_FRONTEND=noninteractive \
   apt-get install -y -qq iperf3 hping3 nmap stress-ng tcpdump apache2-utils'

pct set <CT_ID> -delete net1
pct reboot <CT_ID>
```

Do not record `$PW` anywhere.

## Test procedure

### 1. TCP SYN latency and loss

```sh
pct exec <CT_ID> -- hping3 -S -p 22 -c 100 -i u10000 <DUT_IP>
```

### 2. HTTP request load

```sh
pct exec <CT_ID> -- ab -n 300 -c 20 http://<DUT_IP>/
```

### 3. Sustained HTTP load

```sh
pct exec <CT_ID> -- ab -t 20 -c 30 -n 100000 http://<DUT_IP>/
```

### 4. Connection / SYN rate

```sh
pct exec <CT_ID> -- hping3 -S -p 22 -c 3000 -i u1000 <DUT_IP>
pct exec <CT_ID> -- hping3 -S -p 22 -c 16000 -i u500 <DUT_IP>
```

### 5. Burst / back-to-back behavior

```sh
pct exec <CT_ID> -- hping3 -S -p 22 -c 2000 -i u200 <DUT_IP>
```

### 6. Host-side CPU sampling

The Proxmox API CPU counter may round to zero on short samples. Sample the QEMU
process directly:

```sh
PID=$(pgrep -f "kvm -id <VM_ID>" | head -1)
for i in $(seq 1 12); do
  ps -p "$PID" -o %cpu=,rss=,etime=
  sleep 1
done
```

### 7. Post-test service check

```sh
pct exec <CT_ID> -- nmap -Pn -sT -T4 -p 22,80 <DUT_IP>
```

## Recorded results

| Test | Result |
|---|---|
| TCP SYN RTT, 100 packets @100 pps | 0% loss; min/avg/max 0.1 / 0.7 / 1.7 ms |
| HTTP, 20 concurrent / 300 requests | 293 req/s, 0 failures, 68 ms mean |
| Sustained HTTP, 30 concurrent / 20 s | 325 req/s, 0 failures, 92 ms mean, ~1.15 MB/s |
| SYN load, 3,000 packets @1,000 pps | 0% loss |
| Sustained SYN, 16,000 packets @2,000 pps | 0% loss, 1.0 ms avg RTT |
| Burst, 2,000 packets @5,000 pps | Handled, no drop indicated |
| VM CPU, sustained load | avg 8.1%, max 8.6% |
| Post-test services | SSH and HTTP still available |

## Findings

- ICMP is filtered but TCP management ports respond normally.
- Proxmox-level `ipconfig0` was stale relative to the guest’s actual interface;
  always trust the guest agent when validating test topology.
- Management HTTP was exposed while HTTPS was filtered — worth hardening before
  calling the image “battle ready”.
- CPU and packet-handling headroom were substantial at these load levels.

## Limitations

- No `iperf3` responder was installed on the firewall VM, so pure Layer-3
  throughput and true back-to-back frame counts were not measured.
- Results reflect the isolated test bridge and modest vCPU/memory allocation.
- This is a smoke/stress validation, not a formal certification or penetration test.

## Formal iperf3 throughput test (follow-up)

`iperf3-3.22.pkg` was fetched from the OPNsense repository on a host with
internet access, transferred through the generator CT, and installed locally on
the firewall VM with `pkg add`. The DUT has no DNS/default route, so repository
install directly on the DUT was not possible.

The firewall only permits inbound SSH/HTTP to itself, so inbound port 5201 was
blocked. No firewall rules were changed. Instead, the generator CT ran the
`iperf3` server and the DUT ran the client; reverse mode (`-R`) exercised the
opposite data direction.

| Test | Result |
|---|---|
| TCP, 1 stream, 10 s | **4.21 Gbit/s** sender, 0 retransmits |
| TCP, 4 streams, 10 s | aggregate **4.31 Gbit/s**, 180 retransmits |
| Reverse direction, 10 s | **2.19 Gbit/s**, 479 retransmits |
| UDP 100 Mbit/s, DUT → CT | 100 Mbit/s, **0% loss**, 0.015 ms jitter |
| UDP 100 Mbit/s, reverse | 98.1 Mbit/s, **1.9% loss**, 0.061 ms jitter |
| UDP 500 Mbit/s, DUT → CT | 498 Mbit/s, **0.34% loss**, 0.001 ms jitter |
| VM CPU during all tests | avg **8.5%**, max **9.5%** |

Interpretation: single-stream throughput saturated the virtual NIC around
4.2 Gbit/s with no retransmits. Multi-stream and reverse-direction tests showed
some TCP retransmission and one degraded stream, which is typical of virtual
NIC queuing rather than a firewall failure. UDP loss was negligible except a
brief startup burst in the reverse 100 Mbit/s test. CPU headroom remained large.

To test true inbound port 5201 later, add a temporary firewall pass rule on the
DUT LAN interface, run `iperf3 -s` on the DUT, then remove the rule afterward.

## REA static binary scan (follow-up)

REA's pcap inspection was unavailable locally because TShark/capinfos were not
installed for the analyzer. Instead, two FreeBSD ELF64 binaries from the DUT were
staged through the generator CT and scanned statically.

| Binary | Size | Hardening summary |
|---|---:|---|
| `iperf3` | 7,488 B | ET_EXEC (PIE off), NX on, partial RELRO, CET off, stripped, RUNPATH `/usr/local/lib:/usr/local/lib` |
| `lighttpd` | 358,008 B | ET_EXEC (PIE off), NX on, partial RELRO, stack protector on, CET off, stripped, RUNPATH `/usr/local/lib` |

Dependencies:

- `lighttpd`: 5 needed libraries, 183 imports, 0 issues — `libpcre2-8.so.0`,
  `libdl.so.1`, `libnettle.so.8`, `libthr.so.3`, `libc.so.7`.
- `iperf3`: 6 needed libraries, 25 imports, 0 issues — `libiperf.so.0`,
  `libssl.so.17`, `libcrypto.so.17`, `libthr.so.3`, `libm.so.5`, `libc.so.7`.

Findings: no malformed headers or dependency problems. Hardening is typical
FreeBSD packaging rather than a hardened build: no PIE, no CET, partial RELRO,
and FORTIFY is unconfirmed. The duplicated RUNPATH on `iperf3` is cosmetic.
This is a static header/dependency check, not exploitability proof or a CVE scan.

Reusable REA tools for this kind of work include `inspect_binary_hardening`,
`inspect_elf_dependencies`, `inspect_go_binary`, `search_strings`,
`inspect_apk_signature`, and `inspect_pcap_capture` (when TShark is available).
