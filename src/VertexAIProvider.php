<?php

declare(strict_types=1);

namespace AffiliateCMS\AI\Providers;

class VertexAIProvider implements ProviderInterface
{
    public function getId(): string
    {
        return 'vertex-ai';
    }

    public function getName(): string
    {
        return 'Google Cloud Vertex AI';
    }

    public function getModels(): array
    {
        return [
            ['id' => 'gemini-3.5-flash',   'name' => 'Gemini 3.5 Flash',   'input_cost' => 1.50,  'output_cost' => 9.00],
            ['id' => 'gemini-3.5-pro',     'name' => 'Gemini 3.5 Pro',     'input_cost' => 7.00,  'output_cost' => 21.00],
            ['id' => 'gemini-3.1-flash',   'name' => 'Gemini 3.1 Flash',   'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-3.1-pro',     'name' => 'Gemini 3.1 Pro',     'input_cost' => 1.25,  'output_cost' => 5.00],
            ['id' => 'gemini-3.0-flash',   'name' => 'Gemini 3.0 Flash',   'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-3.0-pro',     'name' => 'Gemini 3.0 Pro',     'input_cost' => 1.25,  'output_cost' => 5.00],
            ['id' => 'gemini-2.5-flash',   'name' => 'Gemini 2.5 Flash',   'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-2.5-pro',     'name' => 'Gemini 2.5 Pro',     'input_cost' => 1.25,  'output_cost' => 5.00],
            ['id' => 'gemini-2.0-flash',   'name' => 'Gemini 2.0 Flash',   'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-1.5-flash',   'name' => 'Gemini 1.5 Flash',   'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-1.5-pro',     'name' => 'Gemini 1.5 Pro',     'input_cost' => 1.25,  'output_cost' => 5.00],
        ];
    }

    public function chat(string $apiKey, string $model, array $messages, array $options = []): ProviderResponse
    {
        $projectId = (string) get_option('acms_ai_vertex_ai_project_id', '');
        $region = (string) get_option('acms_ai_vertex_ai_region', 'us-central1');
        $saJson = (string) get_option('acms_ai_vertex_ai_sa_json', '');

        if (empty($projectId)) {
            return ProviderResponse::error('Vertex AI Project ID is not configured.');
        }

        // Determine if we use Service Account token or API Key
        $accessToken = '';
        if (!empty($saJson)) {
            $accessToken = $this->getAccessTokenFromSA($saJson);
            if (empty($accessToken)) {
                return ProviderResponse::error('Failed to generate OAuth2 token from Service Account JSON.');
            }
        }

        // Vertex AI Gemini endpoint
        $url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$region}/publishers/google/models/{$model}:generateContent";

        if (empty($saJson) && !empty($apiKey)) {
            // Use GCP API Key
            $url .= "?key={$apiKey}";
        }

        // Convert messages to Gemini format
        $systemInstruction = '';
        $contents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction .= $msg['content'] . "\n";
            } else {
                $contents[] = [
                    'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'    => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 4096,
            ],
        ];

        if (!empty($systemInstruction)) {
            $body['systemInstruction'] = [
                'parts' => [['text' => trim($systemInstruction)]],
            ];
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $response = wp_remote_post($url, [
            'timeout' => $options['timeout'] ?? 120,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);

        return $this->parseResponse($response, $model);
    }

    public function validateApiKey(string $apiKey): bool
    {
        $projectId = (string) get_option('acms_ai_vertex_ai_project_id', '');
        $region = (string) get_option('acms_ai_vertex_ai_region', 'us-central1');
        $saJson = (string) get_option('acms_ai_vertex_ai_sa_json', '');

        if (empty($projectId)) {
            return false;
        }

        $accessToken = '';
        if (!empty($saJson)) {
            $accessToken = $this->getAccessTokenFromSA($saJson);
            if (empty($accessToken)) {
                return false;
            }
        }

        // Simple validation using a lightweight list models or generateContent with empty input
        $model = 'gemini-1.5-flash';
        $url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$region}/publishers/google/models/{$model}:generateContent";

        if (empty($saJson) && !empty($apiKey)) {
            $url .= "?key={$apiKey}";
        }

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'ping']]]
            ],
            'generationConfig' => ['maxOutputTokens' => 1]
        ];

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            error_log('Vertex AI Validation WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Vertex AI Validation HTTP Code ' . $code . ': ' . wp_remote_retrieve_body($response));
            return false;
        }

        return true;
    }

    private function parseResponse($response, string $model): ProviderResponse
    {
        if (is_wp_error($response)) {
            return ProviderResponse::error('HTTP Error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return ProviderResponse::error("HTTP {$code}: Invalid response");
        }

        if (isset($body['error'])) {
            return ProviderResponse::error($body['error']['message'] ?? 'Unknown Vertex AI error');
        }

        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $tokensIn  = (int) ($body['usageMetadata']['promptTokenCount'] ?? 0);
        $tokensOut = (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0);

        // Calculate cost based on Vertex AI pricing
        $cost = $this->calculateCost($model, $tokensIn, $tokensOut);

        return ProviderResponse::success($content, $tokensIn, $tokensOut, $cost);
    }

    private function calculateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $pricing = [
            'gemini-3.5-flash' => [1.50, 9.00],
            'gemini-3.5-pro'   => [7.00, 21.00],
            'gemini-3.1-flash' => [0.075, 0.30],
            'gemini-3.1-pro'   => [1.25, 5.00],
            'gemini-3.0-flash' => [0.075, 0.30],
            'gemini-3.0-pro'   => [1.25, 5.00],
            'gemini-2.5-flash' => [0.075, 0.30],
            'gemini-2.5-pro'   => [1.25, 5.00],
            'gemini-2.0-flash' => [0.075, 0.30],
            'gemini-1.5-flash' => [0.075, 0.30],
            'gemini-1.5-pro'   => [1.25, 5.00],
        ];

        [$inCost, $outCost] = $pricing[$model] ?? [0.075, 0.30];

        return ($tokensIn / 1_000_000) * $inCost + ($tokensOut / 1_000_000) * $outCost;
    }

    private function getAccessTokenFromSA(string $saJson): ?string
    {
        $data = json_decode($saJson, true);
        if (!is_array($data)) {
            return null;
        }

        $privateKey = $data['private_key'] ?? '';
        $clientEmail = $data['client_email'] ?? '';
        if (empty($privateKey) || empty($clientEmail)) {
            return null;
        }

        $cacheKey = 'acms_ai_vertex_token_' . md5($clientEmail);
        $token = get_transient($cacheKey);
        if ($token) {
            return $token;
        }

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claim = json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlClaim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));

        $signature = '';
        $success = openssl_sign(
            $base64UrlHeader . "." . $base64UrlClaim,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );

        if (!$success) {
            return null;
        }

        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwt = $base64UrlHeader . "." . $base64UrlClaim . "." . $base64UrlSignature;

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return null;
        }

        $accessToken = $body['access_token'];
        $expiresIn = (int) ($body['expires_in'] ?? 3500);

        set_transient($cacheKey, $accessToken, $expiresIn - 60);

        return $accessToken;
    }
}
