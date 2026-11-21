<?php
namespace OPNsense\Hermes\Api;

use OPNsense\Base\ApiControllerBase;

class GuideApiController extends ApiControllerBase
{
    /**
     * Get list of available guides.
     * GET /api/hermes/guide/list
     */
    public function listAction()
    {
        $guides = \OPNsense\Hermes\Guide::getGuides();
        return ['status' => 'ok', 'guides' => $guides];
    }

    /**
     * Get a specific guide with AI-suggested step content.
     * GET /api/hermes/guide/get/:id
     */
    public function getAction()
    {
        $id = $this->request->getParam('id', 'trim');
        if (empty($id)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        $guide = \OPNsense\Hermes\Guide::getGuide($id);
        if (!$guide) {
            return ['status' => 'error', 'message' => 'Guide not found'];
        }

        // Get AI suggestions for each step
        $suggestedSteps = $this->augmentStepsWithAI($guide);

        return ['status' => 'ok', 'guide' => $guide, 'steps' => $suggestedSteps];
    }

    /**
     * Get AI suggestion for a specific step.
     * POST /api/hermes/guide/suggest/:guideId/:stepId
     */
    public function suggestAction()
    {
        $guideId = $this->request->getParam('guideId', 'trim');
        $stepId = $this->request->getParam('stepId', 'trim');

        if (empty($guideId) || empty($stepId)) {
            return ['status' => 'error', 'message' => 'Guide ID and Step ID required'];
        }

        try {
            $suggestion = $this->getAISuggestion($guideId, $stepId);
            return ['status' => 'ok', 'suggestion' => $suggestion];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Save guide progress (user's field values for each step).
     * POST /api/hermes/guide/save/:guideId
     */
    public function saveAction()
    {
        $guideId = $this->request->getParam('guideId', 'trim');
        if (empty($guideId)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        $data = $this->request->getPost('data', 'trim');
        if (empty($data)) {
            return ['status' => 'error', 'message' => 'Data required'];
        }

        $data = json_decode($data, true);
        if (!$data) {
            return ['status' => 'error', 'message' => 'Invalid JSON data'];
        }

        // Save to config
        try {
            $config = \OPNsense\Base\Config::load('OPNsense/Hermes');
            $existing = $config->get('guide_progress');
            $existing[$guideId] = $data;
            $config->set('guide_progress', $existing);
            $config->save();
            return ['status' => 'ok', 'message' => 'Progress saved'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get saved guide progress.
     * GET /api/hermes/guide/progress/:guideId
     */
    public function progressAction()
    {
        $guideId = $this->request->getParam('guideId', 'trim');
        if (empty($guideId)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        try {
            $config = \OPNsense\Base\Config::load('OPNsense/Hermes');
            $progress = $config->get('guide_progress');
            $data = isset($progress[$guideId]) ? $progress[$guideId] : [];
            return ['status' => 'ok', 'progress' => $data];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Augment guide steps with AI-suggested content.
     */
    private function augmentStepsWithAI($guide)
    {
        $steps = $guide['steps'];
        $suggestions = [];

        foreach ($steps as $step) {
            $suggestion = $this->getAISuggestion($guide['id'], $step['id']);
            $suggestions[] = array_merge($step, [
                'ai_suggestion' => $suggestion['suggestion'] ?? '',
                'ai_explanation' => $suggestion['explanation'] ?? '',
            ]);
        }

        return $suggestions;
    }

    /**
     * Get AI suggestion for a specific step.
     */
    private function getAISuggestion($guideId, $stepId)
    {
        $config = \OPNsense\Base\Config::load('OPNsense/Hermes');
        $general = $config->get('general');

        $host = isset($general->ollama_host) ? (string)$general->ollama_host : 'http://127.0.0.1:11434';
        $model = isset($general->ollama_model) ? (string)$general->ollama_model : 'llama3';
        $systemPrompt = isset($general->system_prompt) ? (string)$general->system_prompt :
            'You are Hermes, an AI assistant for OPNsense firewall administration.';

        $opnsenseContext = $this->getOpnsenseContext();
        $contextStr = $this->contextToString($opnsenseContext);

        $guide = \OPNsense\Hermes\Guide::getGuide($guideId);
        $step = null;
        if ($guide) {
            foreach ($guide['steps'] as $s) {
                if ($s['id'] === $stepId) {
                    $step = $s;
                    break;
                }
            }
        }

        $stepDesc = $step ? $step['description'] : '';
        $stepTitle = $step ? $step['title'] : $stepId;

        $prompt = "{$systemPrompt}\n\n--- Current OPNsense State ---\n{$contextStr}\n--- End OPNsense State ---\n\n"
            . "You are helping the user with the '{$guide['name']}' guide.\n"
            . "Current step: {$stepTitle}\n"
            . "Step description: {$stepDesc}\n\n"
            . "Provide:\n"
            . "1. A brief explanation of what this step does (2-3 sentences).\n"
            . "2. Specific configuration recommendations based on the current OPNsense state.\n"
            . "3. Any warnings or considerations.\n\n"
            . "Format your response with clear sections.";

        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => false,
            'options' => ['temperature' => 0.7, 'max_tokens' => 1500]
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($host, '/') . '/api/chat');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['suggestion' => 'AI unavailable: ' . $curlError, 'explanation' => ''];
        }
        if ($httpCode !== 200) {
            return ['suggestion' => 'AI error (HTTP ' . $httpCode . ')', 'explanation' => ''];
        }

        $result = json_decode($response, true);
        if (isset($result['message']['content'])) {
            return ['suggestion' => $result['message']['content'], 'explanation' => ''];
        }

        return ['suggestion' => 'No response from AI', 'explanation' => ''];
    }

    private function getOpnsenseContext()
    {
        $context = [];
        $context['hostname'] = php_uname('n');
        $context['os'] = 'OPNsense';
        $context['timestamp'] = date('Y-m-d H:i:s');

        if (file_exists('/conf/config.xml')) {
            $config = simplexml_load_file('/conf/config.xml');
            if ($config) {
                $general = $config->system->general;
                if (isset($general->hostname)) {
                    $context['hostname'] = (string)$general->hostname;
                }

                $rules = $config->filter->rule;
                $context['firewall_rules_count'] = count($rules);

                $nat = $config->nat;
                $context['nat_rules_count'] = isset($nat->rule) ?
                    count(is_array($nat->rule) ? $nat->rule : [$nat->rule]) : 0;

                $ifaces = [];
                $interfaces = $config->interfaces;
                foreach (['lan', 'wan', 'opt1', 'opt2', 'opt3', 'opt4'] as $ifName) {
                    if (isset($interfaces->$ifName)) {
                        $iface = $interfaces->$ifName;
                        $ifaces[$ifName] = [
                            'descr' => (string)$iface->descr,
                            'if' => (string)$iface->if,
                            'ipaddr' => (string)$iface->ipaddr ?? '',
                            'subnet' => (string)$iface->subnet ?? '',
                        ];
                    }
                }
                $context['interfaces'] = $ifaces;

                $unbound = $config->unbound;
                if ($unbound) {
                    $context['unbound_enabled'] = (string)$unbound->enable ?? '0';
                    $context['unbound_dnstls'] = [];
                    if (isset($unbound->dnstls->dnstlsserver)) {
                        $dnstls = $unbound->dnstls->dnstlsserver;
                        $dnstlsArray = is_array($dnstls) ? $dnstls : [$dnstls];
                        foreach ($dnstlsArray as $server) {
                            $context['unbound_dnstls'][] = [
                                'hostname' => (string)$server->hostname,
                                'port' => (string)$server->port,
                                'verify' => (string)$server->verifycertificate,
                            ];
                        }
                    }
                }

                $gateways = $config->gateways->gateway;
                $gwArray = is_array($gateways) ? $gateways : [$gateways];
                $context['gateways'] = [];
                foreach ($gwArray as $gw) {
                    $context['gateways'][(string)$gw->name] = [
                        'interface' => (string)$gw->interface,
                        'gateway' => (string)$gw->gateway,
                    ];
                }

                $aliases = $config->filter->alias;
                $context['aliases_count'] = is_array($aliases) ? count($aliases) : ($aliases ? 1 : 0);
            }
        }

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
