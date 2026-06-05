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
            ['id' => 'gemini-3.5-flash', 'name' => 'Gemini 3.5 Flash', 'input_cost' => 1.50,  'output_cost' => 9.00],
            ['id' => 'gemini-3.1-pro',   'name' => 'Gemini 3.1 Pro',   'input_cost' => 1.25,  'output_cost' => 5.00],
            ['id' => 'gemini-3.1-flash', 'name' => 'Gemini 3.1 Flash', 'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-3.0-flash', 'name' => 'Gemini 3.0 Flash', 'input_cost' => 0.075, 'output_cost' => 0.30],
            ['id' => 'gemini-2.5-pro',   'name' => 'Gemini 2.5 Pro',   'input_cost' => 1.25,  'output_cost' => 5.00],
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'input_cost' => 0.075, 'output_cost' => 0.30],
        ];
    }

    public function chat(string $apiKey, string $model, array $messages, array $options = []): ProviderResponse
    {
        $projectId = (string) get_option('acms_ai_vertex_ai_project_id', '');
        $region = (string) get_option('acms_ai_vertex_ai_region', 'us-central1');
        $saJson = (string) get_option('acms_ai_vertex_ai_sa_json', '');

        $mappedModel = $this->mapModelId($model);
        $debug_log = "[" . date('Y-m-d H:i:s') . "] chat() called - model: {$model}, mapped: {$mappedModel}, project: {$projectId}, region: {$region}\n";
        @file_put_contents(dirname(__DIR__) . '/vertex_ai_debug.log', $debug_log, FILE_APPEND);
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            @file_put_contents($upload_dir['basedir'] . '/vertex_ai_debug.log', $debug_log, FILE_APPEND);
        }

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

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
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
                'temperature'      => $options['temperature'] ?? 0.7,
                'maxOutputTokens'  => $options['max_tokens'] ?? 4096,
                // Force pure JSON output — prevents Gemini from adding preamble
                // text before/after the JSON block, which causes parse failures.
                'response_mime_type' => 'application/json',
            ],
        ];
        if (!empty($systemInstruction)) {
            $body['systemInstruction'] = [
                'parts' => [['text' => trim($systemInstruction)]],
            ];
        }

        // Try configured region first, then fall back to global endpoint
        $regionsToTry = array_unique([$region, 'global']);
        foreach ($regionsToTry as $tryRegion) {
            $host = $tryRegion === 'global' ? 'aiplatform.googleapis.com' : "{$tryRegion}-aiplatform.googleapis.com";
            $url  = "https://{$host}/v1/projects/{$projectId}/locations/{$tryRegion}/publishers/google/models/{$mappedModel}:generateContent";
            if (empty($saJson) && !empty($apiKey)) {
                $url .= "?key={$apiKey}";
            }

            $this->logDiagnosticError("chat() trying region [{$tryRegion}] model [{$mappedModel}]");

            $response = wp_remote_post($url, [
                'timeout' => $options['timeout'] ?? 120,
                'headers' => $headers,
                'body'    => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 404 && $tryRegion !== 'global') {
                // Model not available in this region, try global
                $this->logDiagnosticError("chat() model [{$mappedModel}] not in region [{$tryRegion}], retrying with global");
                continue;
            }

            return $this->parseResponse($response, $model);
        }

        return ProviderResponse::error('Model not available in any region. Please check your Google Cloud project.');
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

        $testModels = [
            'gemini-3.5-flash',
            'gemini-3.1-pro',
            'gemini-3.1-flash',
            'gemini-3.0-flash',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
        ];

        $body = [
            'contents'         => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
            'generationConfig' => ['maxOutputTokens' => 1],
        ];
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        // Test ALL models (do not stop at first success) to log availability of each
        $regionsToTry = array_unique([$region, 'global']);
        $anySuccess   = false;
        $lastError    = '';

        foreach ($testModels as $model) {
            $mappedModel    = $this->mapModelId($model);
            $modelSucceeded = false;

            foreach ($regionsToTry as $tryRegion) {
                if ($modelSucceeded) break; // model found, skip other regions for this model

                $host = $tryRegion === 'global' ? 'aiplatform.googleapis.com' : "{$tryRegion}-aiplatform.googleapis.com";
                $url  = "https://{$host}/v1/projects/{$projectId}/locations/{$tryRegion}/publishers/google/models/{$mappedModel}:generateContent";
                if (empty($saJson) && !empty($apiKey)) {
                    $url .= "?key={$apiKey}";
                }

                $response = wp_remote_post($url, [
                    'timeout' => 15,
                    'headers' => $headers,
                    'body'    => wp_json_encode($body),
                ]);

                if (is_wp_error($response)) {
                    $lastError = 'WP_Error: ' . $response->get_error_message();
                    $this->logDiagnosticError("Testing model {$model} @ {$tryRegion} failed: " . $lastError);
                    continue;
                }

                $code         = wp_remote_retrieve_response_code($response);
                $responseBody = wp_remote_retrieve_body($response);

                if ($code === 200) {
                    $this->logDiagnosticError("SUCCESS model: {$model} @ region: {$tryRegion}");
                    $modelSucceeded = true;
                    $anySuccess     = true;
                } else {
                    $lastError = "HTTP Code {$code} [{$tryRegion}]: {$responseBody}";
                    $this->logDiagnosticError("FAILED model: {$model} @ {$tryRegion}: HTTP {$code}");
                }
            }
        }

        if ($anySuccess) {
            return true;
        }
        $this->logDiagnosticError("Vertex AI Validation failed for all tested models and regions. Last error: " . $lastError);
        return false;
    }

    private function parseResponse($response, string $model): ProviderResponse
    {
        if (is_wp_error($response)) {
            $errMsg = 'HTTP Error: ' . $response->get_error_message();
            $this->logDiagnosticError($errMsg);
            return ProviderResponse::error($errMsg);
        }

        $code = wp_remote_retrieve_response_code($response);
        $bodyRaw = wp_remote_retrieve_body($response);
        $body = json_decode($bodyRaw, true);

        if (!is_array($body)) {
            $errMsg = "HTTP {$code}: Invalid response. Raw: " . mb_substr($bodyRaw, 0, 1000);
            $this->logDiagnosticError($errMsg);
            return ProviderResponse::error("HTTP {$code}: Invalid response");
        }

        if (isset($body['error'])) {
            $errMsg = 'Vertex AI Error: ' . ($body['error']['message'] ?? 'Unknown Vertex AI error');
            $this->logDiagnosticError($errMsg . ' | Full JSON: ' . wp_json_encode($body['error']));
            return ProviderResponse::error($body['error']['message'] ?? 'Unknown Vertex AI error');
        }

        if ($code !== 200) {
            $errMsg = "HTTP {$code} response: " . mb_substr($bodyRaw, 0, 1000);
            $this->logDiagnosticError($errMsg);
            return ProviderResponse::error("HTTP {$code}: Error code returned");
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
            'gemini-3.5-flash' => [1.50,  9.00],
            'gemini-3.1-pro'   => [1.25,  5.00],
            'gemini-3.1-flash' => [0.075, 0.30],
            'gemini-3.0-flash' => [0.075, 0.30],
            'gemini-2.5-pro'   => [1.25,  5.00],
            'gemini-2.5-flash' => [0.075, 0.30],
        ];

        [$inCost, $outCost] = $pricing[$model] ?? [0.075, 0.30];

        return ($tokensIn / 1_000_000) * $inCost + ($tokensOut / 1_000_000) * $outCost;
    }

    private function getAccessTokenFromSA(string $saJson): ?string
    {
        $data = json_decode($saJson, true);
        if (!is_array($data)) {
            $this->logDiagnosticError('Vertex AI Token Error: Failed to parse Service Account JSON. Error: ' . json_last_error_msg());
            return null;
        }

        $privateKey = $data['private_key'] ?? '';
        $clientEmail = $data['client_email'] ?? '';
        if (empty($privateKey) || empty($clientEmail)) {
            $this->logDiagnosticError('Vertex AI Token Error: Missing private_key or client_email in Service Account JSON.');
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
            $this->logDiagnosticError('Vertex AI Token Error: openssl_sign failed. OpenSSL Error: ' . openssl_error_string());
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
            $this->logDiagnosticError('Vertex AI Token Error: HTTP request failed. Error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $bodyText = wp_remote_retrieve_body($response);
        $body = json_decode($bodyText, true);

        if ($code !== 200) {
            $this->logDiagnosticError('Vertex AI Token Error: Google OAuth returned HTTP ' . $code . '. Response: ' . $bodyText);
            return null;
        }

        if (!is_array($body) || empty($body['access_token'])) {
            $this->logDiagnosticError('Vertex AI Token Error: Invalid body structure or missing access_token. Body: ' . $bodyText);
            return null;
        }

        $accessToken = $body['access_token'];
        $expiresIn = (int) ($body['expires_in'] ?? 3500);

        set_transient($cacheKey, $accessToken, $expiresIn - 60);

        return $accessToken;
    }

    private function logDiagnosticError(string $message): void
    {
        error_log($message);
        $file = dirname(__DIR__) . '/vertex_ai_models.log';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
        @file_put_contents($file, $logEntry, FILE_APPEND);
    }

    private function mapModelId(string $modelId): string
    {
        $mapping = [
            'gemini-3.5-flash' => 'gemini-3.5-flash',
            'gemini-3.1-pro'   => 'gemini-3.1-pro-preview',
            'gemini-3.1-flash' => 'gemini-3.1-flash-lite',
            'gemini-3.0-flash' => 'gemini-3-flash-preview',
            'gemini-2.5-pro'   => 'gemini-2.5-pro',
            'gemini-2.5-flash' => 'gemini-2.5-flash',
        ];
        
        return $mapping[$modelId] ?? $modelId;
    }
}
