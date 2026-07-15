<?php

declare(strict_types=1);

namespace AffiliateCMS\AI\Providers;

class CKeyProvider implements ProviderInterface
{
    public function getId(): string
    {
        return 'ckey';
    }

    public function getName(): string
    {
        return 'CKEY.VN';
    }

    public function getModels(): array
    {
        $models = [
            ['id' => 'thanhnhan9023/claude-opus-4.8', 'name' => 'Claude Opus 4.8 (thanhnhan9023)', 'input_cost' => 15.00, 'output_cost' => 75.00],
            ['id' => 'sypham98/claude-sonnet-5',      'name' => 'Claude Sonnet 5 (sypham98)',      'input_cost' => 3.00,  'output_cost' => 15.00],
        ];

        $customModelsOption = (string) get_option('acms_ai_ckey_custom_models', '');
        if (!empty($customModelsOption)) {
            $customLines = preg_split('/[\r\n,]+/', $customModelsOption);
            foreach ($customLines as $line) {
                $customModel = trim($line);
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
        }

        return $models;
    }

    public function chat(string $apiKey, string $model, array $messages, array $options = []): ProviderResponse
    {
        $isAnthropicMessages = (str_contains($model, '/') !== false && str_contains($model, 'claude') !== false);

        if ($isAnthropicMessages) {
            // Anthropic messages format
            $url = 'https://api.xah.io/v1/messages';

            $system = '';
            $anthropicMessages = [];

            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $system .= $msg['content'] . "\n";
                } else {
                    $anthropicMessages[] = [
                        'role'    => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                        'content' => $msg['content'],
                    ];
                }
            }

            $body = [
                'model'      => $model,
                'max_tokens' => 8192,
                'messages'   => $anthropicMessages,
            ];

            if ($system) {
                $body['system'] = trim($system);
            }

            if (isset($options['temperature'])) {
                $body['temperature'] = $options['temperature'];
            }

            $headers = [
                'Authorization'     => 'Bearer ' . $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ];
        } else {
            // OpenAI completions format
            $url = 'https://api.xah.io/v1/chat/completions';

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
        $response = wp_remote_get('https://api.xah.io/v1/models', [
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
            // OpenAI format parsing
            $content = $body['choices'][0]['message']['content'] ?? '';
            $tokensIn = (int) ($body['usage']['prompt_tokens'] ?? 0);
            $tokensOut = (int) ($body['usage']['completion_tokens'] ?? 0);
        } elseif (isset($body['content'])) {
            // Anthropic format parsing
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= $block['text'] ?? '';
                }
            }
            $tokensIn = (int) ($body['usage']['input_tokens'] ?? 0);
            $tokensOut = (int) ($body['usage']['output_tokens'] ?? 0);
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
