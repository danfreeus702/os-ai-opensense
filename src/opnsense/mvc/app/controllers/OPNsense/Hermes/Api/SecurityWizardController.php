<?php
namespace OPNsense\Hermes\Api;

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
            'levels' => \OPNsense\Hermes\SecurityWizard::levels(),
            'comparison' => \OPNsense\Hermes\SecurityWizard::comparison(),
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

        $plan = \OPNsense\Hermes\SecurityWizard::buildPlan($level, $options);
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
        $plan = \OPNsense\Hermes\SecurityWizard::buildPlan($level, [
            'tailscale_exit' => $this->request->getPost('tailscale_exit', 'alnum', '0') === '1',
        ]);

        $results = [];
        foreach ($plan['steps'] as $step) {
            $results[] = $this->runConfigd($step['action']);
        }

        $failed = array_filter($results, static function ($r) {
            return !$r['ok'];
        });

        return [
            'status' => count($failed) === 0 ? 'ok' : 'partial',
            'level' => $level,
            'results' => $results,
            'message' => count($failed) === 0
                ? 'Security level ' . $level . ' applied successfully.'
                : count($failed) . ' step(s) failed — review the results.',
        ];
    }

    /** Last applied level (stored in config.xml under hermes/security). */
    public function statusAction()
    {
        $config = \OPNsense\Base\Config::load('OPNsense/Hermes');
        $general = $config->get('general');
        $applied = isset($general->security_level) ? (string)$general->security_level : '0';
        return ['status' => 'ok', 'applied_level' => $applied];
    }

    /** Execute a configd action safely; never interpolate untrusted strings. */
    private function runConfigd($action)
    {
        $allowed = [
            'unbound.enable', 'unbound.blocklist', 'unbound.doh',
            'unbound.dnssec', 'unbound.dot', 'firewall.rule', 'tailscale.exitnode',
        ];
        if (!in_array($action, $allowed, true)) {
            return ['action' => $action, 'ok' => false, 'error' => 'Action not allowed'];
        }

        // configd actions are defined in actions_hermes.conf
        $cmd = '/usr/local/sbin/configctl hermes ' . escapeshellarg($action) . ' 2>&1';
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
