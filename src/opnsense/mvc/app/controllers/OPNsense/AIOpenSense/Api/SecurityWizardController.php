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
            'results' => $results,
            'message' => count($failed) === 0
                ? 'Security level ' . $level . ' applied successfully.'
                : count($failed) . ' step(s) failed — review the results.',
        ];
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
        return [
            'action' => $action,
            'ok' => $rc === 0,
            'output' => implode("\n", array_slice($output, 0, 20)),
        ];
    }
}
