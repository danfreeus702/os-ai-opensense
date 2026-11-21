<?php
namespace OPNsense\AIOpenSense\Api;

use OPNsense\Base\ApiControllerBase;

/**
 * Security Wizard API — plan and apply the 3-tier hardened configuration.
 */
class SecurityWizardController extends ApiControllerBase
{
    /** List the available levels and comparison matrix. */
    public function levelsAction()
    {
        return [
            'status' => 'ok',
            'levels' => \OPNsense\AIOpenSense\SecurityWizard::levels(),
            'comparison' => \OPNsense\AIOpenSense\SecurityWizard::comparison(),
        ];
    }

    /** Build a reviewable plan. Does not change any configuration. */
    public function planAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }
        $level = (int)$this->request->getPost('level', 'int', 1);
        $tailscale = $this->request->getPost('tailscale_exit', 'alnum', '0') === '1';
        $options = ['tailscale_exit' => $tailscale];

        $plan = \OPNsense\AIOpenSense\SecurityWizard::buildPlan($level, $options);
        return ['status' => 'ok', 'plan' => $plan];
    }

    /**
     * Apply a confirmed plan.
     *
     * SECURITY: this endpoint requires an explicit `confirm=yes` and uses
     * OPNsense's configd to make changes, so all actions are logged and
     * reversible through the standard GUI. It never writes config.xml directly.
     */
    public function applyAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }
        if ($this->request->getPost('confirm', 'alnum', 'no') !== 'yes') {
            return ['status' => 'error', 'message' => 'Explicit confirmation required'];
        }

        $level = (int)$this->request->getPost('level', 'int', 0);
        $plan = \OPNsense\AIOpenSense\SecurityWizard::buildPlan($level, [
            'tailscale_exit' => $this->request->getPost('tailscale_exit', 'alnum', '0') === '1',
        ]);

        // Always take a rollback point before touching the configuration.
        $snapshot = $this->runConfigd('snapshot');
        if (!$snapshot['ok']) {
            return [
                'status' => 'error',
                'message' => 'Could not save a rollback snapshot; nothing was changed.',
                'results' => [$snapshot],
            ];
        }

        $results = [];
        foreach ($plan['steps'] as $step) {
            $results[] = $this->runConfigd($step['action']);
        }

        $failed = array_filter($results, static function ($r) {
            return !$r['ok'];
        });

        // Persist for statusAction() via configd (writes config.xml as root)
        @file_put_contents('/tmp/aiopensense-applied-level', (string)$level);
        $this->runConfigd('record_level');

        return [
            'status' => count($failed) === 0 ? 'ok' : 'partial',
            'level' => $level,
            'snapshot' => $snapshot['ok'] ? 'Rollback point saved before changes.' : '',
            'results' => $results,
            'message' => count($failed) === 0
                ? 'Security level ' . $level . ' applied successfully.'
                : count($failed) . ' step(s) failed — review the results.',
        ];
    }

    /** Restore the snapshot taken before the last apply. */
    public function undoAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }
        if ($this->request->getPost('confirm', 'alnum', 'no') !== 'yes') {
            return ['status' => 'error', 'message' => 'Explicit confirmation required'];
        }
        $result = $this->runConfigd('undo');
        return [
            'status' => $result['ok'] ? 'ok' : 'error',
            'message' => $result['ok'] ? 'Last change undone.' : 'Undo failed.',
            'output' => $result['output'],
        ];
    }

    /**
     * Plain-language diff of the changes a level would make, with the exact
     * GUI path for each one. Read-only: never modifies configuration.
     */
    public function previewAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }
        $level = (int)$this->request->getPost('level', 'int', 1);
        $plan = \OPNsense\AIOpenSense\SecurityWizard::buildPlan($level, [
            'tailscale_exit' => $this->request->getPost('tailscale_exit', 'alnum', '0') === '1',
        ]);
        $current = $this->currentValues();
        $catalog = $this->changeCatalog();

        $changes = [];
        foreach ($plan['steps'] as $step) {
            $meta = $catalog[$step['action']] ?? [
                'plain' => $step['detail'],
                'after' => 'Enabled',
                'gui' => 'See the plan entry',
                'doc' => 'https://docs.opnsense.org/',
            ];
            $changes[] = [
                'title' => $step['title'],
                'plain' => $meta['plain'],
                'before' => $current[$step['action']] ?? 'Not configured',
                'after' => $meta['after'],
                'gui_path' => $meta['gui'],
                'doc_url' => $meta['doc'],
            ];
        }
        return ['status' => 'ok', 'level' => $level, 'changes' => $changes];
    }

    /** Human-readable description of each configd action. */
    private function changeCatalog()
    {
        $doc = 'https://docs.opnsense.org/manual/';
        return [
            'unbound-enable' => [
                'plain' => 'Turn on the DNS resolver and a small local DNS cache so repeated lookups are faster.',
                'after' => 'On, 4 MB cache, prefetch on',
                'gui' => 'Services > Unbound DNS > General',
                'doc' => $doc . 'dns.html',
            ],
            'blocklist' => [
                'plain' => 'Block known advertising and tracking domains for every device on your network.',
                'after' => 'Two public blocklists active',
                'gui' => 'Services > Unbound DNS > Blocklists',
                'doc' => $doc . 'dns.html',
            ],
            'clamav' => [
                'plain' => 'Scan files for viruses before they reach your devices.',
                'after' => 'On, scheduled updates, 100 MB scan limit',
                'gui' => 'Services > ClamAV > Scan',
                'doc' => $doc . 'clamav.html',
            ],
            'doh' => [
                'plain' => 'Encrypt your DNS queries so your ISP cannot see which sites you look up.',
                'after' => 'Upstream 1.1.1.1 over TLS',
                'gui' => 'Services > Unbound DNS > Query Forwarding',
                'doc' => $doc . 'dns.html',
            ],
            'crowdsec' => [
                'plain' => 'Block IP addresses known to attack other firewalls around the world.',
                'after' => 'Agent + firewall bouncer on',
                'gui' => 'Services > CrowdSec > Agents',
                'doc' => $doc . 'crowdsec.html',
            ],
            'dnssec' => [
                'plain' => 'Verify that DNS answers are genuine and have not been tampered with.',
                'after' => 'DNSSEC validation on',
                'gui' => 'Services > Unbound DNS > Advanced',
                'doc' => $doc . 'dns.html',
            ],
            'dot' => [
                'plain' => 'Add a second encrypted DNS provider for resilience.',
                'after' => 'Cloudflare + Quad9 over TLS',
                'gui' => 'Services > Unbound DNS > DNS over TLS',
                'doc' => $doc . 'dns.html',
            ],
            'firewall' => [
                'plain' => 'Allow only the traffic these protections need.',
                'after' => 'Required allow rules present',
                'gui' => 'Firewall > Rules > LAN',
                'doc' => $doc . 'firewall.html',
            ],
            'firewall-dns' => ['plain' => 'Allow devices to ask the firewall for DNS.', 'after' => 'TCP/UDP 53 allowed', 'gui' => 'Firewall > Rules > LAN', 'doc' => $doc . 'firewall.html'],
            'firewall-doh' => ['plain' => 'Allow encrypted DNS on port 443.', 'after' => 'TCP 443 allowed', 'gui' => 'Firewall > Rules > LAN', 'doc' => $doc . 'firewall.html'],
            'firewall-dot' => ['plain' => 'Allow DNS-over-TLS on port 853.', 'after' => 'TCP 853 allowed', 'gui' => 'Firewall > Rules > LAN', 'doc' => $doc . 'firewall.html'],
            'tailscale' => ['plain' => 'Share your internet connection through Tailscale.', 'after' => 'Exit node advertised', 'gui' => 'VPN > Tailscale > Settings', 'doc' => $doc . 'tailscale.html'],
            'record_level' => ['plain' => 'Remember which protection level is active.', 'after' => 'Level stored', 'gui' => 'OPNsense AI > Security Wizard', 'doc' => $doc . 'index.html'],
        ];
    }

    /** Current on/off state read from config.xml for the diff view. */
    private function currentValues()
    {
        $xml = @simplexml_load_file('/conf/config.xml');
        $out = [];
        if ($xml === false) {
            return $out;
        }
        $val = function ($path) use ($xml) {
            $node = $xml;
            foreach (explode('/', $path) as $part) {
                if ($node === null || !isset($node->$part)) {
                    return null;
                }
                $node = $node->$part;
            }
            return (string)$node;
        };
        $out['unbound-enable'] = $val('OPNsense/unboundplus/general/enabled') === '1' ? 'On' : 'Off';
        $out['blocklist'] = $val('OPNsense/unboundplus/dnsbl/enabled') === '1' ? 'Active' : 'Not configured';
        $out['clamav'] = $val('OPNsense/clamav/general/enabled') === '1' ? 'On' : 'Off';
        $out['crowdsec'] = $val('OPNsense/crowdsec/general/agent_enabled') === '1' ? 'On' : 'Off';
        $out['dnssec'] = $val('OPNsense/unboundplus/general/dnssec') === '1' ? 'On' : 'Off';
        $out['dot'] = $val('OPNsense/unboundplus/dots') !== null ? 'Configured' : 'Not configured';
        $out['doh'] = $val('OPNsense/unboundplus/dots') !== null ? 'Configured' : 'Not configured';
        $out['tailscale'] = $val('OPNsense/tailscale/settings/advertiseExitNode') === '1' ? 'On' : 'Off';
        $out['record_level'] = $val('OPNsense/aiopensense/security_level') ?: 'Not set';
        $rules = $xml->OPNsense->Firewall->Filter->rule ?? null;
        $count = 0;
        if ($rules !== null) {
            foreach ($rules as $rule) {
                if (strpos((string)$rule->description, 'AIOpenSense') !== false) {
                    $count++;
                }
            }
        }
        foreach (['firewall', 'firewall-dns', 'firewall-doh', 'firewall-dot'] as $key) {
            $out[$key] = $count . ' wizard rule(s) present';
        }
        return $out;
    }

    /** Last applied level — read config.xml directly (works for wwwonly). */
    public function statusAction()
    {
        $level = '0';
        $xml = @simplexml_load_file('/conf/config.xml');
        if ($xml !== false && isset($xml->OPNsense->aiopensense->security_level)) {
            $level = (string)$xml->OPNsense->aiopensense->security_level;
        }
        return ['status' => 'ok', 'applied_level' => $level];
    }

    /** Execute a configd action safely; never interpolate untrusted strings. */
    private function runConfigd($action)
    {
        $allowed = [
            'snapshot', 'undo',
            'unbound-enable', 'blocklist', 'doh',
            'dnssec', 'dot', 'firewall', 'firewall-dns', 'firewall-doh', 'firewall-dot',
            'tailscale', 'record_level',
            'clamav', 'crowdsec',
        ];
        if (!in_array($action, $allowed, true)) {
            return ['action' => $action, 'ok' => false, 'error' => 'Action not allowed'];
        }

        // configd actions are defined in actions_aiopensense.conf
        $cmd = '/usr/local/sbin/configctl aiopensense ' . escapeshellarg($action) . ' 2>&1';
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);
        $text = implode("\n", array_slice($output, 0, 20));
        // configd may return rc=0 even when the action failed (e.g. "Error (126)").
        $failed = $rc !== 0 || preg_match('/^\s*(Error|Action not allowed)/i', $text) === 1;
        return [
            'action' => $action,
            'ok' => !$failed,
            'output' => $text,
        ];
    }
}
