<?php
namespace OPNsense\Hermes\Api;

use OPNsense\Base\ApiControllerBase;

class ChatController extends ApiControllerBase
{
    /**
     * Send a message to Hermes AI and get a response.
     * POST /api/hermes/chat/query
     * Supports natural-language firewall rule creation via the FirewallRulesService.
     */
    public function queryAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $message = $this->request->getPost('message', 'trim');
        if (empty($message)) {
            return ['status' => 'error', 'message' => 'Empty message'];
        }

        try {
            // Parse message into structured firewall rules using the service
            $rulesService = new \OPNsense\Hermes\FirewallRulesService();
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
        $config = \OPNsense\Base\Config::load('OPNsense/Hermes');
        $general = $config->get('general');
        $settings = \OPNsense\Hermes\LLMProvider::resolve($general);

        $systemPrompt = isset($general->system_prompt) && (string)$general->system_prompt !== ''
            ? (string)$general->system_prompt
            : 'You are Hermes, an AI assistant for OPNsense firewall administration.';

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

        return \OPNsense\Hermes\LLMProvider::chat($systemPrompt, $userPrompt, $settings);
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
