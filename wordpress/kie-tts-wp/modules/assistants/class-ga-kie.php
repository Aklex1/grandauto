<?php
/**
 * Клиент KIE для чат-моделей. Ключ берём тот же, что у микросервисов (kie_tts_api_key).
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA_Kie
{
    public const OPT_MODEL = 'ga_default_model';

    public static function api_key(): string
    {
        $key = (string) get_option('kie_tts_api_key', '');
        return trim($key ?: (string) get_option('ga_kie_api_key', ''));
    }

    public static function default_model(): string
    {
        return (string) get_option(self::OPT_MODEL, 'gemini-3-6-flash-openai');
    }

    /**
     * OpenAI-совместимый вызов чат-модели KIE.
     *
     * @param array $messages [['role' => 'system|user|assistant', 'content' => '...'], ...]
     * @return string|WP_Error текст ответа
     */
    /**
     * Сообщение пользователя с картинкой для vision-модели: content — массив
     * из текста и image_url (data:base64). Модель должна поддерживать зрение.
     */
    public static function image_message(string $text, string $data_url): array
    {
        return [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $text !== '' ? $text : 'Опиши и разбери, что на изображении.'],
                ['type' => 'image_url', 'image_url' => ['url' => $data_url]],
            ],
        ];
    }

    public static function chat(string $model, array $messages, float $temperature = 0.4)
    {
        $key = self::api_key();
        if (!$key) {
            return new WP_Error('ga_no_key', 'Не задан ключ KIE API.');
        }
        $model = trim($model ?: self::default_model(), '/');
        $url = 'https://api.kie.ai/' . $model . '/v1/chat/completions';

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        // KIE иногда кладёт ошибку в тело с HTTP 200 (например {"code":500,...}) —
        // без этой проверки такой ответ выглядел бы как «пустой ответ модели».
        $inner = is_array($body) ? (int) ($body['code'] ?? 0) : 0;
        if ($code >= 400 || $inner >= 400) {
            $msg = is_array($body) ? ($body['msg'] ?? $body['message'] ?? '') : '';
            return new WP_Error('ga_kie_http',
                sprintf('KIE: %s (код %d)', $msg ?: 'ошибка запроса', $inner ?: $code));
        }
        $text = $body['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            return new WP_Error('ga_kie_empty', 'Модель вернула пустой ответ. Попробуйте ещё раз.');
        }
        return trim($text);
    }
}
