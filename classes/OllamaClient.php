<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OllamaClient {

    private $baseUrl;
    private $model_llm;
    private $model_embed;
    private $timeout;

    public function __construct() {
        $this->baseUrl = rtrim(Configuration::get('INJELLIK_AI_OLLAMA_URL'), '/');
        $this->model_llm = Configuration::get('INJELLIK_AI_MODEL');
        $this->model_embed = Configuration::get('INJELLIK_AI_OLLAMA_EMBED_MODEL');
        $this->timeout = (int) Configuration::get('INJELLIK_AI_TIMEOUT');
    }

    /**
     * Call Ollama generate endpoint and assemble streaming NDJSON into a single string.
     * Returns generated text as string.
     */
    public function generate(string $prompt, array $params = []): string {
        $url = $this->baseUrl . '/api/generate';
        $payload = array_merge(['model' => $this->model_llm, 'prompt' => $prompt], $params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return '';
        }
        $text = '';
        $lines = explode("\n", trim($resp));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '')
                continue;
            $data = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE)
                continue;
            if (isset($data['response']))
                $text .= $data['response'];
            elseif (isset($data['generated_text']))
                $text .= $data['generated_text'];
            elseif (isset($data['text']))
                $text .= $data['text'];
        }
        return $text;
    }

    /**
     * Request embeddings via Ollama embed endpoint (model must support embeddings)
     * Returns array of floats or null.
     */
    public function embed(string $text): ?array {
        $url = $this->baseUrl . '/api/embed';
        $payload = ['model' => $this->model_embed, 'input' => $text];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp === false)
            return null;
        $json = json_decode($resp, true);
        if (!$json)
            return null;
        if (isset($json['embedding']))
            return $json['embedding'];
        if (isset($json['embeddings']))
            return $json['embeddings'][0] ?? null;
        if (isset($json['data'][0]['embedding']))
            return $json['data'][0]['embedding'] ?? null;
        return null;
    }
}
