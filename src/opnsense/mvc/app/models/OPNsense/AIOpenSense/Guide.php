<?php
namespace OPNsense\AIOpenSense;

/**
 * Guide model — defines available configuration wizards.
 * Each guide has multiple steps; AI assists at each step.
 */
class Guide
{
    /**
     * Get all available guides.
     */
    public static function getGuides()
    {
        return [
            [
                'id' => 'firewall',
                'name' => 'Firewall Rules Setup',
                'description' => 'Step-by-step guide to configure essential firewall rules with AI assistance.',
                'icon' => 'fire',
                'steps' => [
                    [
                        'id' => 'overview',
                        'title' => 'Overview',
                        'description' => 'This guide will help you set up essential firewall rules for your OPNsense firewall. AI will assist at each step based on your current configuration.',
                    ],
                    [
                        'id' => 'wan_rules',
                        'title' => 'WAN Inbound Rules',
                        'description' => 'Configure rules for inbound traffic from the WAN interface. AI will suggest rules based on common services.',
                        'fields' => [
                            ['name' => 'allow_ssh', 'label' => 'Allow SSH from WAN?', 'type' => 'checkbox', 'default' => '0'],
                            ['name' => 'allow_winrm', 'label' => 'Allow WinRM from WAN?', 'type' => 'checkbox', 'default' => '0'],
                        ],
                    ],
                    [
                        'id' => 'lan_rules',
                        'title' => 'LAN Outbound Rules',
                        'description' => 'Configure rules for traffic from LAN to WAN. AI will analyze your current rules and suggest additions.',
                        'fields' => [
                            ['name' => 'block_icmp', 'label' => 'Block ICMP from LAN?', 'type' => 'checkbox', 'default' => '0'],
                            ['name' => 'log_all', 'label' => 'Log all LAN traffic?', 'type' => 'checkbox', 'default' => '0'],
                        ],
                    ],
                    [
                        'id' => 'nat',
                        'title' => 'NAT Configuration',
                        'description' => 'Review and configure NAT (port forwarding) rules. AI can suggest common port forwards.',
                        'fields' => [
                            ['name' => 'nat_enabled', 'label' => 'Enable automatic outbound NAT?', 'type' => 'checkbox', 'default' => '1'],
                            ['name' => 'ports_to_forward', 'label' => 'Ports to forward (comma-separated, e.g. 80,443,8080)', 'type' => 'text', 'default' => ''],
                        ],
                    ],
                    [
                        'id' => 'summary',
                        'title' => 'Summary',
                        'description' => 'Review the configuration recommendations. Apply them through the OPNsense GUI or use the generated rules below.',
                    ],
                ],
            ],
            [
                'id' => 'unbound',
                'name' => 'Unbound DNS over TLS',
                'description' => 'Configure Unbound DNS with DNS over TLS encryption for secure DNS queries.',
                'icon' => 'dns',
                'steps' => [
                    [
                        'id' => 'overview',
                        'title' => 'Overview',
                        'description' => 'This guide configures Unbound DNS with DNS over TLS. AI will check your current DNS setup and suggest optimal settings.',
                    ],
                    [
                        'id' => 'enable_unbound',
                        'title' => 'Enable Unbound',
                        'description' => 'Enable the Unbound DNS service. AI will verify it is not conflicting with other DNS services.',
                        'fields' => [
                            ['name' => 'unbound_enabled', 'label' => 'Enable Unbound DNS?', 'type' => 'checkbox', 'default' => '1'],
                        ],
                    ],
                    [
                        'id' => 'dnstls',
                        'title' => 'DNS over TLS Upstream',
                        'description' => 'Configure DNS over TLS upstream servers. AI will suggest secure DNS providers.',
                        'fields' => [
                            ['name' => 'doh_provider', 'label' => 'DNS over TLS provider', 'type' => 'select', 'options' => ['Cloudflare (1.1.1.1)', 'Quad9 (9.9.9.9)', 'Google (8.8.8.8)', 'Custom'], 'default' => 'Cloudflare (1.1.1.1)'],
                            ['name' => 'custom_host', 'label' => 'Custom DNS host (if selected above)', 'type' => 'text', 'default' => ''],
                            ['name' => 'custom_port', 'label' => 'Custom DNS port', 'type' => 'text', 'default' => '853'],
                            ['name' => 'verify_cert', 'label' => 'Verify TLS certificate?', 'type' => 'checkbox', 'default' => '1'],
                        ],
                    ],
                    [
                        'id' => 'local_dns',
                        'title' => 'Local DNS Settings',
                        'description' => 'Configure local DNS settings for your network.',
                        'fields' => [
                            ['name' => 'dnssec', 'label' => 'Enable DNSSEC validation?', 'type' => 'checkbox', 'default' => '1'],
                            ['name' => 'dns_forward', 'label' => 'Forward local domain queries?', 'type' => 'checkbox', 'default' => '0'],
                            ['name' => 'local_domain', 'label' => 'Local domain name', 'type' => 'text', 'default' => 'localdomain'],
                        ],
                    ],
                    [
                        'id' => 'summary',
                        'title' => 'Summary',
                        'description' => 'Review the recommended Unbound DNS over TLS configuration.',
                    ],
                ],
            ],
            [
                'id' => 'adblock',
                'name' => 'Ad Blocking Setup',
                'description' => 'Configure ad blocking using Unbound blocklists or the AdBlock plugin with AI recommendations.',
                'icon' => 'shield',
                'steps' => [
                    [
                        'id' => 'overview',
                        'title' => 'Overview',
                        'description' => 'This guide helps you set up ad blocking on OPNsense. AI will check which method is best for your setup.',
                    ],
                    [
                        'id' => 'method',
                        'title' => 'Choose Method',
                        'description' => 'Select your ad blocking method. AI will recommend based on your current setup.',
                        'fields' => [
                            ['name' => 'method', 'label' => 'Ad blocking method', 'type' => 'select', 'options' => ['Unbound Blocklists (no plugin)', 'AdBlock Plugin', 'pfBlockerNG'], 'default' => 'Unbound Blocklists (no plugin)'],
                            ['name' => 'blocking_level', 'label' => 'Blocking level', 'type' => 'select', 'options' => ['Low (major trackers only)', 'Medium (trackers + ads)', 'High (all ads + malware)'], 'default' => 'Medium (trackers + ads)'],
                        ],
                    ],
                    [
                        'id' => 'sources',
                        'title' => 'Blocklist Sources',
                        'description' => 'Select blocklist sources. AI will recommend based on your chosen blocking level.',
                        'fields' => [
                            ['name' => 'sources', 'label' => 'Blocklist sources (comma-separated URLs or names)', 'type' => 'text', 'default' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts'],
                        ],
                    ],
                    [
                        'id' => 'exceptions',
                        'title' => 'Whitelist Exceptions',
                        'description' => 'Add domains that should never be blocked.',
                        'fields' => [
                            ['name' => 'whitelist', 'label' => 'Whitelisted domains (comma-separated)', 'type' => 'text', 'default' => ''],
                        ],
                    ],
                    [
                        'id' => 'summary',
                        'title' => 'Summary',
                        'description' => 'Review the ad blocking configuration and apply it.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a specific guide by ID.
     */
    public static function getGuide($id)
    {
        $guides = self::getGuides();
        foreach ($guides as $guide) {
            if ($guide['id'] === $id) {
                return $guide;
            }
        }
        return null;
    }

    /**
     * Get AI suggestion for a guide step.
     * This is called via API to get context-aware guidance.
     */
    public static function getStepSuggestion($guideId, $stepId, $fields = [])
    {
        // AI suggestions are generated via GuideApiController using the cloud LLM provider
        return [
            'status' => 'pending',
            'message' => 'AI suggestion will be generated based on your OPNsense configuration.',
        ];
    }
}
