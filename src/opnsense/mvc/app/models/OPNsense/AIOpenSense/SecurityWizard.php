<?php
namespace OPNsense\AIOpenSense;

/**
 * Security Wizard — 3-tier hardened configuration for OPNsense.
 *
 * Level 1: Ad blocking + DNS caching + ClamAV antivirus
 * Level 2: Level 1 + encrypted DNS (DoT) + CrowdSec intrusion prevention
 * Level 3: Level 2 + DNSSEC + DNS-over-TLS + optional Tailscale exit node
 *
 * Each level produces a reviewable plan plus a set of configd actions that
 * apply the settings. Nothing is applied without explicit user confirmation.
 */
class SecurityWizard
{
    public const LEVELS = [
        1 => [
            'id' => 'level1',
            'name' => 'Level 1 — Ad Blocking, DNS Cache & Antivirus',
            'icon' => 'shield',
            'description' => 'Network-wide ad blocking via Unbound blocklists, DNS caching, and ClamAV antivirus scanning.',
            'services' => ['unbound', 'dnsbl', 'clamav'],
        ],
        2 => [
            'id' => 'level2',
            'name' => 'Level 2 — Encrypted DNS & Intrusion Prevention',
            'icon' => 'lock',
            'description' => 'Everything in Level 1, plus encrypted DNS (DoT) and CrowdSec collaborative intrusion prevention.',
            'services' => ['unbound', 'dnsbl', 'clamav', 'doh', 'crowdsec'],
        ],
        3 => [
            'id' => 'level3',
            'name' => 'Level 3 — Maximum Security',
            'icon' => 'eye-close',
            'description' => 'Everything in Level 2, plus DNSSEC validation, a second DoT upstream (Quad9), and optional Tailscale exit node.',
            'services' => ['unbound', 'dnsbl', 'clamav', 'doh', 'crowdsec', 'dnssec', 'dot', 'tailscale'],
        ],
    ];

    /** Default upstream resolvers per tier. */
    public const RESOLVERS = [
        1 => [['host' => '1.1.1.1', 'port' => 53, 'tls' => false]],
        2 => [['host' => 'cloudflare-dns.com', 'port' => 443, 'doh' => true]],
        3 => [
            ['host' => '1.1.1.1', 'port' => 853, 'tls' => true, 'name' => 'cloudflare-dns.com'],
            ['host' => '9.9.9.9', 'port' => 853, 'tls' => true, 'name' => 'dns.quad9.net'],
        ],
    ];

    /** Well-known ad/tracker blocklists. */
    public const BLOCKLISTS = [
        'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
        'https://big.oisd.nl/domainswild',
    ];

    /**
     * Build a reviewable plan for a level. Never applies anything.
     *
     * @param int $level 1..3
     * @param array $options Optional: tailscale_exit (bool), blocklists (array)
     * @return array
     */
    public static function buildPlan($level, array $options = [])
    {
        $level = max(1, min(3, (int)$level));
        $meta = self::LEVELS[$level];
        $steps = [];

        // All levels: Unbound enabled + cache + blocklists
        $steps[] = [
            'id' => 'unbound_enable',
            'title' => 'Enable Unbound DNS with local cache',
            'action' => 'unbound-enable',
            'detail' => 'Enable the Unbound resolver, set cache size to 4 MB, and enable prefetch.',
            'risk' => 'low',
        ];
        $steps[] = [
            'id' => 'dnsbl',
            'title' => 'Install ad/tracker blocklists',
            'action' => 'blocklist',
            'detail' => 'Add ' . count($options['blocklists'] ?? self::BLOCKLISTS) . ' blocklists to Unbound.',
            'risk' => 'low',
        ];
        $steps[] = [
            'id' => 'clamav',
            'title' => 'Enable ClamAV antivirus',
            'action' => 'clamav',
            'detail' => 'Enable ClamAV with freshclam updates, TCP scanning, archive/PE/ELF/PDF scanning, 100 MB max scan size and 25 MB max file size — matching the production profile.',
            'risk' => 'medium',
        ];

        if ($level >= 2) {
            $steps[] = [
                'id' => 'doh',
                'title' => 'Configure encrypted DNS upstream (DoT)',
                'action' => 'doh',
                'detail' => 'OPNsense 26.7 Unbound supports DoT only (no DoH type). Forward to 1.1.1.1:853 with TLS verification of cloudflare-dns.com — the same setup used in production.',
                'risk' => 'medium',
            ];
        }

        if ($level >= 2) {
            $steps[] = [
                'id' => 'crowdsec',
                'title' => 'Enable CrowdSec intrusion prevention',
                'action' => 'crowdsec',
                'detail' => 'Enable the CrowdSec agent, local API (127.0.0.1:8080), firewall bouncer and rule updates — matching the production profile.',
                'risk' => 'medium',
            ];
        }

        if ($level >= 3) {
            $steps[] = [
                'id' => 'dnssec',
                'title' => 'Enable DNSSEC validation',
                'action' => 'dnssec',
                'detail' => 'Validate DNS answers cryptographically. Requires correct system time (NTP).',
                'risk' => 'medium',
            ];
            $steps[] = [
                'id' => 'dot',
                'title' => 'Configure DNS-over-TLS upstreams',
                'action' => 'dot',
                'detail' => 'Add Cloudflare (1.1.1.1@853) and Quad9 (9.9.9.9@853) with TLS certificate verification.',
                'risk' => 'medium',
            ];
            if (!empty($options['tailscale_exit'])) {
                $steps[] = [
                    'id' => 'tailscale_exit',
                    'title' => 'Enable Tailscale exit node',
                    'action' => 'tailscale',
                    'detail' => 'Advertise this OPNsense box as a Tailscale exit node (0.0.0.0/0, ::/0).',
                    'risk' => 'high',
                ];
            }
        }

        // Firewall rules included with every level
        $steps[] = [
            'id' => 'firewall_dns',
            'title' => 'Allow DNS resolution (TCP/UDP 53)',
            'action' => 'firewall',
            'detail' => 'LAN → any, TCP/UDP 53, for local clients to reach Unbound.',
            'risk' => 'low',
        ];
        if ($level >= 2) {
            $steps[] = [
                'id' => 'firewall_doh',
                'title' => 'Allow DNS-over-HTTPS (TCP 443)',
                'action' => 'firewall',
                'detail' => 'LAN → any, TCP 443, for DoH upstream forwarding.',
                'risk' => 'low',
            ];
        }
        if ($level >= 3) {
            $steps[] = [
                'id' => 'firewall_dot',
                'title' => 'Allow DNS-over-TLS (TCP 853)',
                'action' => 'firewall',
                'detail' => 'LAN → any, TCP 853, for DoT upstream forwarding.',
                'risk' => 'low',
            ];
        }

        return [
            'level' => $level,
            'name' => $meta['name'],
            'icon' => $meta['icon'],
            'description' => $meta['description'],
            'steps' => $steps,
            'options' => $options,
            'requires_confirmation' => true,
            'note' => 'This plan is advisory until you click Apply. Review each step before confirming.',
        ];
    }

    /**
     * Return the three levels as wizard cards for the index page.
     */
    public static function levels()
    {
        return array_values(self::LEVELS);
    }

    /**
     * Human-readable comparison matrix for the UI.
     */
    public static function comparison()
    {
        $features = [
            'Ad blocking (DNSBL)' => 1,
            'DNS caching / prefetch' => 1,
            'ClamAV antivirus scanning' => 1,
            'CrowdSec intrusion prevention' => 2,
            'Encrypted upstream (DoT; no native DoH)' => 2,
            'DNSSEC validation' => 3,
            'DNS-over-TLS (DoT)' => 3,
            'Tailscale exit node (optional)' => 3,
        ];
        $rows = [];
        foreach ($features as $name => $minLevel) {
            $rows[] = [
                'feature' => $name,
                'level1' => $minLevel <= 1,
                'level2' => $minLevel <= 2,
                'level3' => $minLevel <= 3,
            ];
        }
        return $rows;
    }
}
