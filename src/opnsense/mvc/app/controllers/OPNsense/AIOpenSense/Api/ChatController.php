<?php
namespace OPNsense\AIOpenSense\Api;

use OPNsense\Base\ApiControllerBase;

// OPNsense does not autoload app/services/ — load plugin service classes explicitly.
require_once __DIR__ . '/../../../../services/OPNsense/AIOpenSense/FirewallRulesService.php';
require_once __DIR__ . '/../../../../services/OPNsense/AIOpenSense/LLMProvider.php';

class ChatController extends ApiControllerBase
{
    /**
     * Send a message to OPNsense AI and get a response.
     * POST /api/aiopensense/chat/query
     * Supports natural-language firewall rule creation via the FirewallRulesService.
     */
    public function queryAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $message = trim((string)$this->request->getPost('message'));
        if (empty($message)) {
            return ['status' => 'error', 'message' => 'Empty message'];
        }

        try {
            // Parse message into structured firewall rules using the service
            $rulesService = new \OPNsense\AIOpenSense\FirewallRulesService();
            $context = $this->getOpnsenseContext();
            $parsed = $rulesService->parseToRules($message, $context);
            $validation = $rulesService->validateRules(
                $parsed['rules'] ?? [],
                $parsed['nat'] ?? [],
                $parsed['aliases'] ?? []
            );

            // Get AI response with the parsed configuration embedded
            $aiResponse = $this->sendToLLM($message, $context, $parsed);

            return [
                'status' => 'ok',
                'reply' => $aiResponse,
                // Always offer the official docs search for this question.
                'documentation_url' => 'https://docs.opnsense.org/search.html?q=' . rawurlencode($message),
                'parsed_rules' => $parsed['rules'] ?? [],
                'parsed_nat' => $parsed['nat'] ?? [],
                'parsed_aliases' => $parsed['aliases'] ?? [],
                'explanation' => $parsed['explanation'] ?? '',
                'warnings' => $parsed['warnings'] ?? [],
                'validation' => $validation,
                'used_context' => $context,
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get OPNsense context for the AI to use.
     */
    private function getOpnsenseContext()
    {
        $context = [];

        // System info
        $context['hostname'] = php_uname('n');
        $context['os'] = 'OPNsense';
        $context['timestamp'] = date('Y-m-d H:i:s');
        $context['hardware'] = $this->getHardwareInfo();

        // Gather from config.xml
        if (file_exists('/conf/config.xml')) {
            $config = simplexml_load_file('/conf/config.xml');
            if ($config) {
                // General settings
                $general = $config->system->general;
                if (isset($general->hostname)) {
                    $context['hostname'] = (string)$general->hostname;
                }

                // Firewall rules count
                $rules = $config->filter->rule;
                $context['firewall_rules_count'] = count($rules);

                // NAT rules
                $nat = $config->nat;
                $context['nat_rules_count'] = isset($nat->rule) ?
                    count(is_array($nat->rule) ? $nat->rule : [$nat->rule]) : 0;

                // Interfaces
                $ifaces = [];
                $interfaces = $config->interfaces;
                if (isset($interfaces->opt1)) {
                    $ifaces['opt1'] = [
                        'descr' => (string)$interfaces->opt1->descr,
                        'if' => (string)$interfaces->opt1->if
                    ];
                }
                if (isset($interfaces->opt2)) {
                    $ifaces['opt2'] = [
                        'descr' => (string)$interfaces->opt2->descr,
                        'if' => (string)$interfaces->opt2->if
                    ];
                }
                $context['additional_interfaces'] = $ifaces;

                // Unbound DNS
                $unbound = $config->unbound;
                if ($unbound) {
                    $context['unbound_enabled'] = (string)$unbound->enable ?? '0';
                    $context['unbound_dnstlsserver'] = [];
                    if (isset($unbound->dnstls->dnstlsserver)) {
                        $dnstls = $unbound->dnstls->dnstlsserver;
                        $dnstlsArray = is_array($dnstls) ? $dnstls : [$dnstls];
                        foreach ($dnstlsArray as $server) {
                            $context['unbound_dnstlsserver'][] = [
                                'hostname' => (string)$server->hostname,
                                'port' => (string)$server->port,
                                'verify' => (string)$server->verifycertificate
                            ];
                        }
                    }
                }

                // Gateway
                $gateways = $config->gateways->gateway;
                $gwArray = is_array($gateways) ? $gateways : [$gateways];
                foreach ($gwArray as $gw) {
                    if ((string)$gw->interface === 'lan' || (string)$gw->interface === 'wan') {
                        $context['gateway_' . $gw->interface] = [
                            'name' => (string)$gw->name,
                            'ip' => (string)$gw->gateway
                        ];
                    }
                }
            }
        }

        // Gather installed plugins
        $context['installed_plugins'] = $this->getInstalledPlugins();

        return $context;
    }

    /**
     * Hardware/network specs used to tailor recommendations to this appliance.
     * All values come from local read-only commands; no user input is involved.
     */
    private function getHardwareInfo()
    {
        $specs = [];

        $cpu = trim((string)shell_exec('/sbin/sysctl -n hw.model 2>/dev/null'));
        if ($cpu !== '') {
            $specs['cpu_model'] = $cpu;
        }
        $cores = trim((string)shell_exec('/sbin/sysctl -n hw.ncpu 2>/dev/null'));
        if ($cores !== '') {
            $specs['cpu_cores'] = $cores;
        }
        $mem = (int)trim((string)shell_exec('/sbin/sysctl -n hw.physmem 2>/dev/null'));
        if ($mem > 0) {
            $specs['memory_gb'] = round($mem / 1073741824, 1);
        }
        $version = trim((string)shell_exec('/usr/local/sbin/opnsense-version 2>/dev/null'));
        if ($version !== '') {
            $specs['version'] = $version;
        }
        $disk = explode("\n", trim((string)shell_exec('/bin/df -h / 2>/dev/null')));
        if (count($disk) > 1) {
            $specs['root_disk'] = trim(end($disk));
        }
        $links = trim((string)shell_exec('/sbin/ifconfig -a 2>/dev/null | /usr/bin/grep -E "^[a-z]+[0-9]*:|status:" | head -40'));
        if ($links !== '') {
            $specs['interfaces'] = preg_replace('/\s+/', ' ', $links);
        }

        return $specs;
    }

    private function getInstalledPlugins()
    {
        $plugins = [];
        $dir = '/usr/local/opnsense/mvc/app/models/OPNsense';
        if (is_dir($dir)) {
            $entries = scandir($dir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (is_dir("$dir/$entry") && file_exists("$dir/$entry/Menu/Menu.xml")) {
                    $plugins[] = $entry;
                }
            }
        }
        return $plugins;
    }

    private function sendToLLM($message, $context = null, $parsed = null)
    {
        $model = new \OPNsense\AIOpenSense\AIOpenSense();
        $general = $model->general;
        $settings = \OPNsense\AIOpenSense\LLMProvider::resolve($general);

        $docsDirective = 'Use the official OPNsense documentation at https://docs.opnsense.org/ as your primary source of truth. '
            . 'Base configuration answers on it, give the exact GUI menu path, and link the relevant documentation page when known. '
            . 'If the documentation does not cover the question, say so instead of guessing. '
            . 'Always make the best recommendation for this specific network and hardware: use the supplied '
            . 'CPU, memory, disk, interface/link-speed and firewall context, and prefer settings that fit the '
            . 'reported capacity. State assumptions when specs are unavailable.';

        $systemPrompt = isset($general->system_prompt) && (string)$general->system_prompt !== ''
            ? (string)$general->system_prompt
            : 'You are AIOpenSense, an AI assistant for OPNsense firewall administration.';

        // Existing installations have a saved prompt from before docs grounding was
        // added. Always point queries at the official documentation, even then.
        if (stripos($systemPrompt, 'docs.opnsense.org') === false) {
            $systemPrompt .= "\n\n" . $docsDirective;
        }

        // Build context — use provided or fetch fresh. Users can disable this
        // in settings when firewall metadata should not leave the appliance.
        $opnsenseContext = $context ?? $this->getOpnsenseContext();
        $contextStr = $settings['send_context'] === '0'
            ? '(context disabled by administrator)'
            : $this->contextToString($opnsenseContext);

        // Build parsed-configuration summary for the AI
        $parsedSummary = '';
        if ($parsed) {
            $rules = $parsed['rules'] ?? [];
            $nat = $parsed['nat'] ?? [];
            $aliases = $parsed['aliases'] ?? [];
            $warnings = $parsed['warnings'] ?? [];
            $explanation = $parsed['explanation'] ?? '';

            if (!empty($rules)) {
                $parsedSummary .= "\n--- Parsed Firewall Rules ---\n";
                foreach ($rules as $r) {
                    $parsedSummary .= "  Action: {$r['action']}, Interface: {$r['interface']}, Protocol: {$r['protocol']}, "
                        . "Src: {$r['source']}, Dst: {$r['destination']}, DstPort: {$r['dst_port']}, Desc: {$r['descr']}, Log: " . ($r['log'] ?? 'false') . "\n";
                }
            }
            if (!empty($nat)) {
                $parsedSummary .= "\n--- Parsed NAT Rules ---\n";
                foreach ($nat as $n) {
                    $parsedSummary .= "  Proto: {$n['proto']}, ExtPort: {$n['dst_port']}, Target: {$n['target']}:{$n['target_port']}, Desc: {$n['descr']}\n";
                }
            }
            if (!empty($aliases)) {
                $parsedSummary .= "\n--- Parsed Aliases ---\n";
                foreach ($aliases as $a) {
                    $parsedSummary .= "  Name: {$a['name']}, Type: {$a['type']}, Content: {$a['content']}\n";
                }
            }
            if (!empty($warnings)) {
                $parsedSummary .= "\n--- Warnings ---\n" . implode("\n", $warnings) . "\n";
            }
            if (!empty($explanation)) {
                $parsedSummary .= "\n--- Explanation ---\n{$explanation}\n";
            }
        }

        $userPrompt = "--- Current OPNsense State ---\n{$contextStr}\n--- End OPNsense State ---\n\n"
            . "User question: {$message}\n"
            . ($parsedSummary ?: "\n(No structured configuration parsed for this request.)\n");

        return \OPNsense\AIOpenSense\LLMProvider::chat($systemPrompt, $userPrompt, $settings);
    }

    private function contextToString($context)
    {
        $lines = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $lines[] = "{$key}:";
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $lines[] = "  {$k}: " . json_encode($v);
                    } else {
                        $lines[] = "  {$k}: {$v}";
                    }
                }
            } else {
                $lines[] = "{$key}: {$value}";
            }
        }
        return implode("\n", $lines);
    }
}
