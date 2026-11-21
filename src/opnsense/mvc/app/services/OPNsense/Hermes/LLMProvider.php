<?php
namespace OPNsense\Hermes;

/**
 * Cloud LLM provider layer for the Hermes OPNsense plugin.
 *
 * All providers speak the OpenAI-compatible /chat/completions API.
 * No local/Ollama backend is used — the plugin sends a small, sanitised
 * context snapshot to a cloud model and returns the answer to the GUI.
 *
 * API keys are read from a root-only file (default /conf/hermes.key), never
 * from config.xml, which is widely copied into backups.
 */
class LLMProvider
{
    /** Provider presets: endpoint + model, with an optional fallback. */
    public const PRESETS = [
        'openrouter' => [
            'label'       => 'OpenRouter (recommended)',
            'endpoint'    => 'https://openrouter.ai/api/v1',
            'model'       => 'nvidia/nemotron-3.5-lightning:free',
            'fallback_endpoint' => 'https://integrate.api.nvidia.com/v1',
            'fallback_model'    => 'deepseek-ai/deepseek-v4.1-flash',
        ],
        'nvidia' => [
            'label'       => 'NVIDIA NIM',
            'endpoint'    => 'https://integrate.api.nvidia.com/v1',
            'model'       => 'deepseek-ai/deepseek-v4.1-flash',
            'fallback_endpoint' => 'https://openrouter.ai/api/v1',
            'fallback_model'    => 'nvidia/nemotron-3.5-lightning:free',
        ],
    ];

    /** Default settings, used when config fields are empty. */
    public const DEFAULTS = [
        'provider'    => 'openrouter',
        'endpoint'    => '',
        'model'       => '',
        'api_key_file' => '/conf/hermes.key',
        'timeout'     => 45,
        'max_tokens'  => 2048,
        'temperature' => 0.4,
        'send_context' => '1',
    ];

    /**
     * Resolve settings from the plugin model, falling back to defaults/preset.
     *
     * @param object|null $general The <general> node from the Hermes model
     * @return array
     */
    public static function resolve($general = null)
    {
        $get = static function ($name, $default = '') use ($general) {
            if ($general === null || !isset($general->$name)) {
                return $default;
            }
            $value = (string)$general->$name;
            return $value === '' ? $default : $value;
        };

        $provider = $get('provider', self::DEFAULTS['provider']);
        $preset = self::PRESETS[$provider] ?? self::PRESETS['openrouter'];

        return [
            'provider'     => $provider,
            'label'        => $preset['label'],
            'endpoint'     => $get('api_endpoint', $preset['endpoint']),
            'model'        => $get('model', $preset['model']),
            'fallback_endpoint' => $preset['fallback_endpoint'],
            'fallback_model'    => $preset['fallback_model'],
            'api_key_file' => $get('api_key_file', self::DEFAULTS['api_key_file']),
            'timeout'      => (int)$get('timeout', self::DEFAULTS['timeout']),
            'max_tokens'   => (int)$get('max_tokens', self::DEFAULTS['max_tokens']),
            'temperature'  => (float)$get('temperature', self::DEFAULTS['temperature']),
            'send_context' => $get('send_context', self::DEFAULTS['send_context']),
        ];
    }

    /**
     * Read the API key from a root-only file. Returns '' when absent.
     * The file should contain a single line, e.g. sk-or-v1-... or nvapi-...
     */
    public static function readApiKey($path)
    {
        if ($path === '' || !file_exists($path)) {
            return '';
        }
        $key = trim(file_get_contents($path));
        // tolerate a KEY=value line
        if (strpos($key, '=') !== false) {
            [$name, $key] = explode('=', $key, 2);
            $key = trim($key);
        }
        return $key;
    }

    /**
     * Send a chat request using the OpenAI-compatible message schema.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array  $settings Resolved settings from resolve()
     * @param bool   $useFallback Try the secondary provider on failure
     * @return string The assistant reply
     * @throws \Exception with a safe, key-free message
     */
    public static function chat($systemPrompt, $userPrompt, array $settings, $useFallback = true)
    {
        $key = self::readApiKey($settings['api_key_file']);
        if ($key === '') {
            throw new \Exception('No API key configured. Place the provider key in '
                . $settings['api_key_file'] . ' (root-only, mode 600).');
        }

        $payload = json_encode([
            'model' => $settings['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => false,
            'temperature' => $settings['temperature'],
            'max_tokens' => $settings['max_tokens'],
        ], JSON_UNESCAPED_SLASHES);

        try {
            return self::post($settings['endpoint'], $payload, $key, $settings['timeout'], 'primary');
        } catch (\Exception $e) {
            if (!$useFallback || empty($settings['fallback_endpoint'])) {
                throw $e;
            }
            // Do not leak the primary key or provider error body to the GUI.
            return self::post(
                $settings['fallback_endpoint'],
                $payload,
                $key,
                $settings['timeout'],
                'fallback'
            );
        }
    }

    /** Perform one HTTP POST and extract choices[0].message.content. */
    private static function post($endpoint, $payload, $key, $timeout, $label)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($endpoint, '/') . '/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 — omit it.

        if ($error) {
            throw new \Exception("{$label} provider connection error: {$error}");
        }
        if ($httpCode === 429 || $httpCode >= 500) {
            throw new \Exception("{$label} provider temporarily unavailable (HTTP {$httpCode})");
        }
        if ($httpCode !== 200) {
            $body = json_decode($response, true);
            $msg = $body['error']['message'] ?? 'HTTP ' . $httpCode;
            throw new \Exception("{$label} provider error: " . substr($msg, 0, 160));
        }

        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }
        if (isset($result['error']['message'])) {
            throw new \Exception("{$label} provider error: " . substr($result['error']['message'], 0, 160));
        }
        throw new \Exception("{$label} provider returned an unexpected response format");
    }

    /** Human-readable provider description for the GUI. */
    public static function describe(array $settings)
    {
        return $settings['label'] . ' / ' . $settings['model'];
    }
}
