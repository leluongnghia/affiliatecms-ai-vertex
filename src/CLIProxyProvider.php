<?php

declare(strict_types=1);

namespace AffiliateCMS\AI\Providers;

class CLIProxyProvider implements ProviderInterface
{
    public function getId(): string
    {
        return 'cliproxy';
    }

    public function getName(): string
    {
        return 'CLIProxyAPI';
    }

    public function getModels(): array
    {
        $models = [
            ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (Anthropic)', 'input_cost' => 3.00, 'output_cost' => 15.00],
            ['id' => 'claude-opus-4-6-thinking', 'name' => 'Claude Opus 4.6 Thinking', 'input_cost' => 15.00, 'output_cost' => 75.00],
            ['id' => 'gemini-3.1-pro-preview', 'name' => 'Gemini 3.1 Pro Preview (Google)', 'input_cost' => 1.25, 'output_cost' => 5.00],
            ['id' => 'gemini-3.5-flash', 'name' => 'Gemini 3.5 Flash (Google)', 'input_cost' => 0.35, 'output_cost' => 1.05],
            ['id' => 'grok-4.5', 'name' => 'Grok 4.5 (xAI)', 'input_cost' => 2.00, 'output_cost' => 10.00],
            ['id' => 'grok-3-mini', 'name' => 'Grok 3 Mini (xAI)', 'input_cost' => 0.20, 'output_cost' => 1.00],
            ['id' => 'gpt-5.5', 'name' => 'GPT-5.5 (OpenAI)', 'input_cost' => 5.00, 'output_cost' => 15.00],
        ];

        $customModelsOption = get_option('acms_ai_cliproxy_custom_models', []);
        if (!is_array($customModelsOption)) {
            $customModelsOption = preg_split('/[\r\n,]+/', (string) $customModelsOption);
        }

        foreach ($customModelsOption as $line) {
            $customModel = trim((string) $line);
            if (empty($customModel)) {
                continue;
            }

            $exists = false;
            foreach ($models as $m) {
                if ($m['id'] === $customModel) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $inputCost = 3.00;
                $outputCost = 15.00;
                if (str_contains($customModel, 'opus')) {
                    $inputCost = 15.00;
                    $outputCost = 75.00;
                } elseif (str_contains($customModel, 'haiku')) {
                    $inputCost = 0.80;
                    $outputCost = 4.00;
                }

                $models[] = [
                    'id'          => $customModel,
                    'name'        => $customModel . ' (Custom)',
                    'input_cost'  => $inputCost,
                    'output_cost' => $outputCost,
                ];
            }
        }

        return $models;
    }

    public function chat(string $apiKey, string $model, array $messages, array $options = []): ProviderResponse
    {
        // Use OpenAI completions format as specified in the docs for all models
        $url = 'https://api.azevent.vn/v1/chat/completions';

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => 8192,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ];

        $response = wp_remote_post($url, [
            'timeout' => $options['timeout'] ?? 120,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);

        return $this->parseResponse($response, $model);
    }

    public function validateApiKey(string $apiKey): bool
    {
        $response = wp_remote_get('https://api.azevent.vn/v1/models', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    private function parseResponse($response, string $model): ProviderResponse
    {
        if (is_wp_error($response)) {
            return ProviderResponse::error('HTTP Error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $bodyText = wp_remote_retrieve_body($response);
        $body = json_decode($bodyText, true);

        if ($code !== 200 || !is_array($body)) {
            $msg = $body['error']['message'] ?? $body['error'] ?? "HTTP {$code}";
            return ProviderResponse::error((string) $msg);
        }

        $content = '';
        $tokensIn = 0;
        $tokensOut = 0;
        $genId = $body['id'] ?? null;

        if (isset($body['choices'])) {
            $content = $body['choices'][0]['message']['content'] ?? '';
            $tokensIn = (int) ($body['usage']['prompt_tokens'] ?? 0);
            $tokensOut = (int) ($body['usage']['completion_tokens'] ?? 0);
        } else {
            return ProviderResponse::error('Unknown response structure: ' . $bodyText);
        }

        $cost = $this->calculateCost($model, $tokensIn, $tokensOut);

        return ProviderResponse::success($content, $tokensIn, $tokensOut, $cost, $genId);
    }

    private function calculateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $pricing = [];
        foreach ($this->getModels() as $m) {
            $pricing[$m['id']] = [$m['input_cost'], $m['output_cost']];
        }

        [$inCost, $outCost] = $pricing[$model] ?? [0.50, 1.50];

        return ($tokensIn / 1_000_000) * $inCost + ($tokensOut / 1_000_000) * $outCost;
    }
}
