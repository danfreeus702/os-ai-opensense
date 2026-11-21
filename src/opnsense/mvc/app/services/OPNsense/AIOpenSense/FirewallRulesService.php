<?php
namespace OPNsense\AIOpenSense;

class FirewallRulesService
{
    /**
     * Parse natural language into OPNsense firewall rule entries.
     * Returns structured rules ready for GUI application.
     *
     * @param string $message Natural language request
     * @param array $context Current OPNsense config context
     * @return array {rules: [...], nat: [...], aliases: [...], explanation: string, warnings: [...]}
     */
    public function parseToRules($message, $context = [])
    {
        $result = [
            'rules' => [],
            'nat' => [],
            'aliases' => [],
            'explanation' => '',
            'warnings' => [],
            'raw_response' => '',
        ];

        // Try LLM-based parsing first
        $llmResult = $this->parseWithLLM($message, $context);
        if ($llmResult && isset($llmResult['rules']) && count($llmResult['rules']) > 0) {
            $result = array_merge($result, $llmResult);
            $result['source'] = 'ai';
            return $result;
        }

        // Fallback to heuristic parsing
        $result = array_merge($result, $this->parseHeuristic($message));
        $result['source'] = 'heuristic';
        return $result;
    }

    /**
     * LLM-based rule parsing.
     */
    private function parseWithLLM($message, $context)
    {
        $model = new \OPNsense\AIOpenSense\AIOpenSense();
        $general = $model->general;

        $settings = \OPNsense\AIOpenSense\LLMProvider::resolve($general);
        $systemPrompt = isset($general->system_prompt) && (string)$general->system_prompt !== ''
            ? (string)$general->system_prompt
            : 'You are AIOpenSense, an AI assistant for OPNsense firewall administration. Return only valid JSON.';

        $contextStr = $this->contextToText($context);

        $prompt = "{$systemPrompt}\n\n--- Current OPNsense State ---\n{$contextStr}\n--- End OPNsense State ---\n\n"
            . "Parse this user request into OPNsense firewall configuration.\n"
            . "Request: {$message}\n\n"
            . "Return ONLY a JSON object with this exact structure (no markdown, no explanation):\n"
            . "{\n"
            . "  \"rules\": [\n"
            . "    {\"action\":\"pass|block|reject\",\"interface\":\"wan|lan|opt1\","
            . "\"protocol\":\"tcp|udp|icmp|any\",\"source\":\"any|IP/mask|alias\","
            . "\"destination\":\"any|IP/mask|alias\",\"dst_port\":\"port|any\","
            . "\"descr\":\"description\",\"log\":false,\"stateless\":false}\n"
            . "  ],\n"
            . "  \"nat\": [\n"
            . "    {\"interface\":\"wan\",\"proto\":\"tcp|udp\",\"src\":\"any\","
            . "\"src_port\":\"any\",\"dst\":\"WAN address|\","
            . "\"dst_port\":\"port\",\"target\":\"IP\",\"target_port\":\"port\","
            . "\"descr\":\"description\"}\n"
            . "  ],\n"
            . "  \"aliases\": [\n"
            . "    {\"name\":\"aliasname\",\"type\":\"network|host|port|url\","
            . "\"content\":\"value\",\"descr\":\"description\"}\n"
            . "  ],\n"
            . "  \"explanation\": \"brief explanation in plain language\",\n"
            . "  \"warnings\": [\"warning text\"]\n"
            . "}\n\n"
            . "RULES:\n"
            . "- parse the request into the minimum set of firewall rules needed\n"
            . "- for port forwarding, include both the NAT rule AND the firewall pass rule\n"
            . "- if a new alias is needed, list it in aliases\n"
            . "- warnings should cover security concerns (e.g., 'allowing SSH from WAN is risky')\n"
            . "- if the request is ambiguous, make a reasonable assumption and note it in explanation\n"
            . "- return empty arrays if the request cannot be parsed";

        try {
            $content = \OPNsense\AIOpenSense\LLMProvider::chat($systemPrompt, $prompt, $settings);
        } catch (\Exception $e) {
            return null;
        }
        $data = json_decode(trim($content), true);
        if (!$data) return null;

        return [
            'rules' => $data['rules'] ?? [],
            'nat' => $data['nat'] ?? [],
            'aliases' => $data['aliases'] ?? [],
            'explanation' => $data['explanation'] ?? '',
            'warnings' => $data['warnings'] ?? [],
            'raw_response' => $content,
        ];
    }

    /**
     * Heuristic fallback parser — handles common patterns without LLM.
     */
    private function parseHeuristic($message)
    {
        $rules = [];
        $nat = [];
        $aliases = [];
        $explanation = '';
        $warnings = [];

        $lower = strtolower($message);

        // --- Port forward detection ---
        if (preg_match('/forward\s+(?:port\s+)?(\d+)\s+(?:to|on)\s+(\d+\.\d+\.\d+\.\d+)(?::(\d+))?/i', $message, $m)) {
            $extPort = $m[1];
            $internalIP = $m[2];
            $intPort = $m[3] ?? $extPort;

            $nat[] = [
                'interface' => 'wan',
                'proto' => 'tcp',
                'src' => 'any',
                'src_port' => 'any',
                'dst' => '',
                'dst_port' => $extPort,
                'target' => $internalIP,
                'target_port' => $intPort,
                'descr' => "Port forward {$extPort} → {$internalIP}:{$intPort}",
            ];

            $rules[] = [
                'action' => 'pass',
                'interface' => 'wan',
                'protocol' => 'tcp',
                'source' => 'any',
                'destination' => $internalIP,
                'dst_port' => $intPort,
                'descr' => "Allow forwarded {$extPort} to {$internalIP}",
                'log' => false,
                'stateless' => false,
            ];

            $explanation = "Port forward from WAN port {$extPort} to {$internalIP}:{$intPort}";
            $warnings[] = "This exposes port {$extPort} to the internet. Ensure the target service is secured.";
        }

        // --- Allow service detection ---
        if (preg_match('/allow\s+(ssh|https?|rdp|winrm|vnc|ftp|mysql|postgres|redis|http|dns)\s+(?:from\s+)?(?:the\s+)?(?:internet|wan|anywhere)?\s+(?:to\s+)?(\d+\.\d+\.\d+\.\d+)?/i', $message, $m)) {
            $serviceMap = [
                'ssh' => ['tcp', 22, 'SSH'],
                'https' => ['tcp', 443, 'HTTPS'],
                'http' => ['tcp', 80, 'HTTP'],
                'rdp' => ['tcp', 3389, 'RDP'],
                'winrm' => ['tcp', 5985, 'WinRM'],
                'vnc' => ['tcp', 5900, 'VNC'],
                'ftp' => ['tcp', 21, 'FTP'],
                'mysql' => ['tcp', 3306, 'MySQL'],
                'postgres' => ['tcp', 5432, 'PostgreSQL'],
                'redis' => ['tcp', 6379, 'Redis'],
                'dns' => ['udp', 53, 'DNS'],
            ];

            $svc = strtolower($m[1]);
            if (isset($serviceMap[$svc])) {
                list($proto, $port, $name) = $serviceMap[$svc];
                $targetIP = $m[2] ?? 'WAN address';

                $rules[] = [
                    'action' => 'pass',
                    'interface' => 'wan',
                    'protocol' => $proto,
                    'source' => 'any',
                    'destination' => $targetIP,
                    'dst_port' => (string)$port,
                    'descr' => "Allow {$name} from WAN",
                    'log' => false,
                    'stateless' => false,
                ];

                $explanation = "Allow {$name} (port {$port}) from WAN to {$targetIP}";
                if ($targetIP === 'WAN address' || strpos($lower, 'internet') !== false || strpos($lower, 'wan') !== false) {
                    $warnings[] = "Allowing {$name} from the internet exposes this service publicly.";
                }
            }
        }

        // --- Block all inbound except specific service ---
        if (preg_match('/block\s+all\s+(?:inbound|incoming|from\s+the\s+internet|from\s+wan)/i', $message)) {
            $rules[] = [
                'action' => 'block',
                'interface' => 'wan',
                'protocol' => 'any',
                'source' => 'any',
                'destination' => 'any',
                'descr' => 'Block all inbound from WAN',
                'log' => true,
                'stateless' => false,
            ];
            $explanation = "Block all inbound traffic from WAN. Add specific pass rules above this one for allowed services.";
            $warnings[] = "This rule blocks ALL inbound traffic. Make sure you have pass rules for any services you need to reach from the internet.";
        }

        // --- Alias detection ---
        if (preg_match('/create\s+an?\s+alias\s+(?:called|named|for)\s+(\w+)\s+(?:for|with|containing)\s+(\d+\.\d+\.\d+\.\d+\/\d+|[\w\.\-]+)/i', $message, $m)) {
            $aliasName = $m[1];
            $aliasContent = $m[2];
            $type = filter_var($aliasContent, FILTER_VALIDATE_IP) ? 'network' : 'host';
            if (strpos($aliasContent, '/') !== false) $type = 'network';

            $aliases[] = [
                'name' => $aliasName,
                'type' => $type,
                'content' => $aliasContent,
                'descr' => "Alias: {$aliasName}",
            ];
            $explanation = "Created alias '{$aliasName}' for {$aliasContent}";
        }

        if (empty($rules) && empty($nat) && empty($aliases)) {
            $explanation = "Could not parse this request with heuristic rules. Try rephrasing or use the AI model.";
        }

        return [
            'rules' => $rules,
            'nat' => $nat,
            'aliases' => $aliases,
            'explanation' => $explanation,
            'warnings' => $warnings,
            'raw_response' => '',
        ];
    }

    private function contextToText($context)
    {
        $lines = [];
        foreach ($context as $k => $v) {
            if (is_array($v)) {
                $lines[] = "$k:";
                foreach ($v as $kk => $vv) {
                    $lines[] = "  $kk: " . (is_array($vv) ? json_encode($vv) : $vv);
                }
            } else {
                $lines[] = "$k: $v";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Validate generated rules for common errors.
     */
    public function validateRules($rules, $nat = [], $aliases = [])
    {
        $warnings = [];
        $errors = [];

        foreach ($rules as $i => $rule) {
            if (!isset($rule['action'])) $errors[] = "Rule $i: missing action field";
            if (!in_array($rule['action'] ?? '', ['pass', 'block', 'reject'])) $errors[] = "Rule $i: invalid action";
            if (!isset($rule['interface'])) $errors[] = "Rule $i: missing interface";
            if (!isset($rule['protocol'])) $errors[] = "Rule $i: missing protocol";
            if (!isset($rule['destination'])) $errors[] = "Rule $i: missing destination";
        }

        foreach ($nat as $i => $n) {
            if (!isset($n['dst_port'])) $errors[] = "NAT $i: missing dst_port";
            if (!isset($n['target'])) $errors[] = "NAT $i: missing target";
            if (!isset($n['target_port'])) $errors[] = "NAT $i: missing target_port";
        }

        // Check for conflicting rules (pass + block on same interface/port)
        $passRules = [];
        $blockRules = [];
        foreach ($rules as $rule) {
            if (($rule['action'] ?? '') === 'pass') {
                $passRules[] = $rule;
            } elseif (($rule['action'] ?? '') === 'block') {
                $blockRules[] = $rule;
            }
        }

        // If there's a blanket block and passes, check ordering
        $hasBlanketBlock = false;
        foreach ($blockRules as $br) {
            if (($br['source'] ?? '') === 'any' && ($br['destination'] ?? '') === 'any' && ($br['protocol'] ?? '') === 'any') {
                $hasBlanketBlock = true;
                $warnings[] = "Blanket block rule detected. Ensure pass rules are placed BEFORE the block rule in the OPNsense rule order.";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
