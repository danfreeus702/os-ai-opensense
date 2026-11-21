#!/usr/bin/env python3
"""
AIOpenSense Security Wizard — OPNsense configuration actions.

Structures below were derived verbatim from the PRODUCTION OPNsense 26.7.9
config at 192.168.10.140 (copied read-only for analysis), not guessed.

Key finding: OPNsense 26.7 does NOT use <unbound>. DNS lives in
<OPNsense><unboundplus> and <dnsmasq>. The Unbound model's <dots><dot><type>
only accepts "dot" and "forward" — there is NO native DoH type. Encrypted
upstreams are DoT (server 1.1.1.1, port 853, verify cloudflare-dns.com).

Actions: enable_unbound, blocklist, doh, dnssec, dot, firewall, tailscale
"""

import os
import sys
import shutil
import logging
import subprocess
import xml.etree.ElementTree as ET
from datetime import datetime

LOG = "/var/log/aiopensense-security.log"
CONF = "/conf/config.xml"

logging.basicConfig(filename=LOG, level=logging.INFO,
                    format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("aiopensense-security")

# Exact values observed in production config.xml
PROD_DNSBL_TYPE = "atf,ag,sb,hgz005,hgz010,hgz019"
PROD_DNSBL_TTL = "72000"
PROD_DOT = {"server": "1.1.1.1", "port": "853", "verify": "cloudflare-dns.com",
            "description": "Cloudflare", "type": "dot"}
PROD_LOCAL_FORWARD = {"domain": "internal", "server": "127.0.0.1", "port": "53053",
                      "type": "forward", "description": "Forward default domain to Dnsmasq DHCP"}

# Exact ClamAV values from production config.xml (OPNsense 26.7.9)
PROD_CLAMAV = {
    "enabled": "1", "fc_enabled": "1", "enabletcp": "1", "maxthreads": "10",
    "maxqueue": "100", "idletimeout": "30", "maxdirrecursion": "20",
    "followdirsym": "0", "followfilesym": "0", "disablecache": "0",
    "scanpe": "1", "scanelf": "1", "detectbroken": "0", "scanole2": "1",
    "ole2blockmarcros": "0", "scanpdf": "1", "scanswf": "1",
    "scanxmldocs": "1", "scanhwp3": "1", "scanmailfiles": "1",
    "scanhtml": "1", "scanarchive": "1", "arcblockenc": "0",
    "maxscansize": "100M", "maxfilesize": "25M", "maxrecursion": "16",
    "maxfiles": "10000", "logverbose": "0", "fc_logverbose": "0",
    "fc_databasemirror": "database.clamav.net", "fc_timeout": "60",
    "fc_malwareexpert": "0", "fc_blurl": "0", "fc_jurlbla": "0", "fc_bofhland": "0",
}

# Exact CrowdSec values from production config.xml
PROD_CROWDSEC = {
    "agent_enabled": "1", "lapi_enabled": "1", "firewall_bouncer_enabled": "1",
    "lapi_manual_configuration": "0", "lapi_listen_address": "127.0.0.1",
    "lapi_listen_port": "8080", "rules_enabled": "1", "rules_log": "0",
    "rules_tag": "", "enroll_key": "", "crowdsec_firewall_verbose": "0",
}


def ok(msg):
    log.info("OK: %s", msg)
    print(msg)
    return 0


def fail(msg):
    log.error("FAIL: %s", msg)
    print(msg, file=sys.stderr)
    return 1


def load():
    try:
        return ET.parse(CONF)
    except Exception as e:
        return fail("cannot parse %s: %s" % (CONF, e))


def save(tree):
    """Write atomically, preserving the exact mode/owner of config.xml.

    OPNsense's web GUI runs as wwwonly and must be able to READ config.xml.
    Production uses 0644 root:wheel. A plain ElementTree write creates a new
    file with umask-derived mode (0640), which breaks the GUI with
    'flock(): Argument #1 ($stream) must be of type resource, false given'.
    Always copy the original stat before replacing.
    """
    st = os.stat(CONF)
    tmp = CONF + ".aiopensense-tmp"
    tree.write(tmp, encoding="utf-8", xml_declaration=True)
    os.chmod(tmp, st.st_mode & 0o7777)
    os.chown(tmp, st.st_uid, st.st_gid)
    os.replace(tmp, CONF)
    log.info("config.xml written atomically (mode %o uid %d gid %d)" %
             (st.st_mode & 0o777, st.st_uid, st.st_gid))


def ensure(parent, tag):
    node = parent.find(tag)
    if node is None:
        node = ET.SubElement(parent, tag)
    return node


def opnsense(tree):
    root = tree.getroot()
    o = root.find("OPNsense")
    if o is None:
        o = ET.SubElement(root, "OPNsense")
    return o


def unboundplus(tree):
    return ensure(opnsense(tree), "unboundplus")


def action_enable_unbound():
    tree = load()
    if isinstance(tree, int):
        return tree
    u = ensure(unboundplus(tree), "general")
    ensure(u, "enabled").text = "1"
    ensure(u, "port").text = "53"
    adv = ensure(unboundplus(tree), "advanced")
    ensure(adv, "prefetch").text = "1"
    # production uses msgcachesize empty (default); set explicit 4 MB cache
    ensure(adv, "msgcachesize").text = "4"
    save(tree)
    return ok("Unbound enabled: port 53, cache 4 MB, prefetch on")


def action_blocklist():
    tree = load()
    if isinstance(tree, int):
        return tree
    dnsbl = ensure(unboundplus(tree), "dnsbl")
    bl = ensure(dnsbl, "blocklist")
    ensure(bl, "enabled").text = "1"
    ensure(bl, "type").text = PROD_DNSBL_TYPE
    ensure(bl, "nxdomain").text = "0"
    ensure(bl, "cache_ttl").text = PROD_DNSBL_TTL
    ensure(bl, "description").text = "AIOpenSense DNSBL"
    save(tree)
    return ok("DNS blocklist enabled (type=%s, ttl=%s)" % (PROD_DNSBL_TYPE, PROD_DNSBL_TTL))


def action_dnssec():
    tree = load()
    if isinstance(tree, int):
        return tree
    general = ensure(unboundplus(tree), "general")
    ensure(general, "dnssec").text = "1"
    save(tree)
    return ok("DNSSEC validation enabled in unboundplus/general")


def add_dot_entry(tree, spec, description):
    dots = ensure(unboundplus(tree), "dots")
    # idempotent: do not duplicate an existing server+port
    for existing in dots.findall("dot"):
        if ((existing.findtext("server") or "") == spec["server"] and
                (existing.findtext("port") or "") == spec["port"]):
            return False
    dot = ET.SubElement(dots, "dot")
    for k in ("enabled", "type", "domain", "server", "port", "verify",
              "forward_tcp_upstream", "forward_first", "description"):
        ET.SubElement(dot, k)
    dot.find("enabled").text = "1"
    dot.find("type").text = spec["type"]
    dot.find("server").text = spec["server"]
    dot.find("port").text = spec["port"]
    dot.find("verify").text = spec.get("verify", "")
    dot.find("forward_tcp_upstream").text = "0"
    dot.find("forward_first").text = "0"
    dot.find("description").text = description
    return True


def action_dot():
    tree = load()
    if isinstance(tree, int):
        return tree
    added = add_dot_entry(tree, PROD_DOT, PROD_DOT["description"])
    if added:
        # keep production's local-domain forward to dnsmasq
        add_dot_entry(tree, PROD_LOCAL_FORWARD, PROD_LOCAL_FORWARD["description"])
        save(tree)
    return ok("DoT upstream %s:%s (verify=%s)%s" % (
        PROD_DOT["server"], PROD_DOT["port"], PROD_DOT["verify"],
        "" if added else " — already present"))


def action_doh():
    """
    OPNsense 26.7 Unbound has no DoH type (only dot/forward). We implement the
    encrypted-upstream tier with DoT — the platform's supported mechanism — and
    say so explicitly rather than pretending DoH was configured.
    """
    return action_dot()


def _firewall_rule(proto, port, description):
    """Add one idempotent LAN pass rule. Never duplicates by description."""
    tree = load()
    if isinstance(tree, int):
        return tree
    fw = ensure(ensure(ensure(opnsense(tree), "Firewall"), "Filter"), "rules")
    for existing in fw.findall("rule"):
        if (existing.findtext("description") or "") == description:
            return ok("already present: " + description)
    seq = 0
    for r in fw.findall("rule"):
        try:
            seq = max(seq, int(r.findtext("sequence") or 0))
        except ValueError:
            pass
    rule = ET.SubElement(fw, "rule")
    fields = {
        "enabled": "1", "statetype": "keep", "state-policy": "", "sequence": str(seq + 10),
        "action": "pass", "quick": "1", "interfacenot": "0", "interface": "lan",
        "received-on-not": "0", "received-on": "", "direction": "in", "ipprotocol": "inet",
        "protocol": proto, "icmptype": "", "icmp6type": "",
        "source_net": "lan", "source_not": "0", "source_port": "",
        "destination_net": "any", "destination_not": "0", "destination_port": port,
        "divert-to": "", "gateway": "", "replyto": "", "disablereplyto": "0",
        "log": "0", "allowopts": "0", "nosync": "0", "nopfsync": "0",
        "statetimeout": "", "udp-first": "", "udp-multiple": "", "udp-single": "",
        "max-src-nodes": "", "max-src-states": "", "max-src-conn": "", "max": "",
        "max-src-conn-rate": "", "max-src-conn-rates": "", "max-pkt-rate-number": "",
        "max-pkt-rate-seconds": "", "overload": "", "adaptivestart": "", "adaptiveend": "",
        "prio": "", "set-prio": "", "set-prio-low": "", "tag": "", "tagged": "",
        "tcpflags1": "", "tcpflags2": "", "tcpflags_any": "0", "categories": "",
        "sched": "", "tos": "", "shaper1": "", "shaper2": "",
        "description": description, "audit": "",
    }
    for k, v in fields.items():
        ET.SubElement(rule, k).text = v
    save(tree)
    return ok("added: " + description)


def action_firewall():
    """Legacy combined action — kept for compatibility, no longer used by wizard."""
    return action_firewall_dns()


def action_firewall_dns():
    a = _firewall_rule("tcp", "53", "AIOpenSense DNS TCP 53")
    b = _firewall_rule("udp", "53", "AIOpenSense DNS UDP 53")
    return a if a != b else a


def action_firewall_doh():
    return _firewall_rule("tcp", "443", "AIOpenSense DoH (TCP 443)")


def action_firewall_dot():
    return _firewall_rule("tcp", "853", "AIOpenSense DoT (TCP 853)")


def action_clamav():
    """Enable ClamAV with the exact production profile."""
    tree = load()
    if isinstance(tree, int):
        return tree
    general = ensure(ensure(opnsense(tree), "clamav"), "general")
    for k, v in PROD_CLAMAV.items():
        ensure(general, k).text = v
    save(tree)
    return ok("ClamAV enabled (freshclam, TCP scan, 100M/25M limits)")


def action_crowdsec():
    """Enable CrowdSec with the exact production profile."""
    tree = load()
    if isinstance(tree, int):
        return tree
    general = ensure(ensure(opnsense(tree), "crowdsec"), "general")
    for k, v in PROD_CROWDSEC.items():
        ensure(general, k).text = v
    save(tree)
    return ok("CrowdSec enabled (agent, LAPI 127.0.0.1:8080, firewall bouncer, rules)")


def action_record_level():
    """Persist the applied level in config.xml for the wizard status API."""
    level = '0'
    try:
        level = open('/tmp/aiopensense-applied-level').read().strip()
    except OSError:
        pass
    tree = load()
    if isinstance(tree, int):
        return tree
    root = tree.getroot()
    node = root.find('OPNsense/aiopensense')
    if node is None:
        node = ET.SubElement(opnsense(tree), 'aiopensense')
    ensure(node, 'security_level').text = str(level)
    save(tree)
    return ok("recorded applied level " + str(level))


def action_tailscale():
    """
    Production stores Tailscale at <OPNsense><tailscale><settings>:
      enabled, acceptDNS=0, advertiseExitNode=1, acceptSubnetRoutes=1, enableSSH=1
    """
    tree = load()
    if isinstance(tree, int):
        return tree
    settings = ensure(ensure(opnsense(tree), "tailscale"), "settings")
    ensure(settings, "enabled").text = "1"
    ensure(settings, "acceptDNS").text = "0"
    ensure(settings, "advertiseExitNode").text = "1"
    ensure(settings, "acceptSubnetRoutes").text = "1"
    ensure(settings, "enableSSH").text = "1"
    save(tree)
    return ok("Tailscale exit node enabled in config; approve the node in Tailscale admin console")


SNAPSHOT_DIR = "/conf/aiopensense/snapshots"
LAST_SNAPSHOT = "/conf/aiopensense/last-snapshot"


def action_snapshot():
    """Save a timestamped config.xml copy before any wizard apply."""
    os.makedirs(SNAPSHOT_DIR, mode=0o700, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    dst = os.path.join(SNAPSHOT_DIR, "config-%s.xml" % stamp)
    try:
        shutil.copy2(CONF, dst)
        os.chmod(dst, 0o600)
        with open(LAST_SNAPSHOT, "w") as handle:
            handle.write(dst)
        os.chmod(LAST_SNAPSHOT, 0o600)
        # Keep only the five most recent snapshots.
        snaps = sorted(
            (os.path.join(SNAPSHOT_DIR, f) for f in os.listdir(SNAPSHOT_DIR) if f.endswith(".xml")),
            reverse=True,
        )
        for old in snaps[5:]:
            os.unlink(old)
    except Exception as exc:
        return fail("snapshot failed: %s" % exc)
    return ok("config snapshot saved: %s" % dst)


def action_undo():
    """Restore the most recent snapshot after validating its XML."""
    try:
        with open(LAST_SNAPSHOT) as handle:
            src = handle.read().strip()
    except OSError:
        return fail("no snapshot available")
    if not src or not os.path.isfile(src):
        return fail("snapshot file missing")
    try:
        ET.parse(src)
        shutil.copy2(src, CONF)
        os.chmod(CONF, 0o644)
    except Exception as exc:
        return fail("undo failed: %s" % exc)
    try:
        subprocess.run(["/usr/local/sbin/configctl", "filter", "reload"],
                       timeout=30, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception as exc:
        log.warning("filter reload after undo failed: %s", exc)
    return ok("configuration restored from %s" % src)


ACTIONS = {
    "snapshot": action_snapshot,
    "undo": action_undo,
    "enable_unbound": action_enable_unbound,
    "blocklist": action_blocklist,
    "doh": action_doh,
    "dnssec": action_dnssec,
    "dot": action_dot,
    "firewall": action_firewall,
    "tailscale": action_tailscale,
    "record_level": action_record_level,
    "clamav": action_clamav,
    "crowdsec": action_crowdsec,
    "firewall_dns": action_firewall_dns,
    "firewall_doh": action_firewall_doh,
    "firewall_dot": action_firewall_dot,
}


def main():
    if len(sys.argv) < 2:
        print("usage: security.py <action>", file=sys.stderr)
        return 2
    name = sys.argv[1]
    func = ACTIONS.get(name)
    if not func:
        return fail("unknown action: " + name)
    log.info("invoked action=%s", name)
    return func()


if __name__ == "__main__":
    sys.exit(main())
