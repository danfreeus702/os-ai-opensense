<?php
namespace OPNsense\AIOpenSense\Api;

use OPNsense\Base\ApiControllerBase;

// OPNsense does not autoload app/services/ — load plugin service classes explicitly.
require_once __DIR__ . '/../../../../services/OPNsense/AIOpenSense/LLMProvider.php';

class GuideApiController extends ApiControllerBase
{
    /**
     * Get list of available guides.
     * GET /api/aiopensense/guide/list
     */
    public function listAction()
    {
        $guides = \OPNsense\AIOpenSense\Guide::getGuides();
        return ['status' => 'ok', 'guides' => $guides];
    }

    /**
     * Get a specific guide with AI-suggested step content.
     * GET /api/aiopensense/guide/get/:id
     */
    public function getAction()
    {
        $id = trim((string)$this->request->getParam('id'));
        if (empty($id)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        $guide = \OPNsense\AIOpenSense\Guide::getGuide($id);
        if (!$guide) {
            return ['status' => 'error', 'message' => 'Guide not found'];
        }

        // Get AI suggestions for each step
        $suggestedSteps = $this->augmentStepsWithAI($guide);

        return ['status' => 'ok', 'guide' => $guide, 'steps' => $suggestedSteps];
    }

    /**
     * Get AI suggestion for a specific step.
     * POST /api/aiopensense/guide/suggest/:guideId/:stepId
     */
    public function suggestAction()
    {
        $guideId = trim((string)$this->request->getParam('guideId'));
        $stepId = trim((string)$this->request->getParam('stepId'));

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
     * POST /api/aiopensense/guide/save/:guideId
     */
    public function saveAction()
    {
        $guideId = trim((string)$this->request->getParam('guideId'));
        if (empty($guideId)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        $data = trim((string)$this->request->getPost('data'));
        if (empty($data)) {
            return ['status' => 'error', 'message' => 'Data required'];
        }

        $data = json_decode($data, true);
        if (!$data) {
            return ['status' => 'error', 'message' => 'Invalid JSON data'];
        }

        // Save to config
        try {
            $existing = $this->readProgress();
            $existing[$guideId] = $data;
            $this->writeProgress($existing);
            return ['status' => 'ok', 'message' => 'Progress saved'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get saved guide progress.
     * GET /api/aiopensense/guide/progress/:guideId
     */
    public function progressAction()
    {
        $guideId = trim((string)$this->request->getParam('guideId'));
        if (empty($guideId)) {
            return ['status' => 'error', 'message' => 'Guide ID required'];
        }

        try {
            $progress = $this->readProgress();
            $data = isset($progress[$guideId]) ? $progress[$guideId] : [];
            return ['status' => 'ok', 'progress' => $data];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Guide progress lives in a root-only JSON file, not config.xml. */
    private function progressFile()
    {
        return '/conf/aiopensense-guide-progress.json';
    }

    private function readProgress()
    {
        $file = $this->progressFile();
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function writeProgress(array $data)
    {
        $file = $this->progressFile();
        file_put_contents($file, json_encode($data), LOCK_EX);
        chmod($file, 0600);
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
        $model = new \OPNsense\AIOpenSense\AIOpenSense();
        $general = $model->general;

        $settings = \OPNsense\AIOpenSense\LLMProvider::resolve($general);
        $systemPrompt = isset($general->system_prompt) && (string)$general->system_prompt !== '' ? (string)$general->system_prompt :
            'You are AIOpenSense, an AI assistant for OPNsense firewall administration.';

        $opnsenseContext = $this->getOpnsenseContext();
        $contextStr = $this->contextToString($opnsenseContext);

        $guide = \OPNsense\AIOpenSense\Guide::getGuide($guideId);
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

        try {
            $reply = \OPNsense\AIOpenSense\LLMProvider::chat($systemPrompt, $prompt, $settings);
            return ['suggestion' => $reply, 'explanation' => ''];
        } catch (\Exception $e) {
            return ['suggestion' => 'AI unavailable: ' . $e->getMessage(), 'explanation' => ''];
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
