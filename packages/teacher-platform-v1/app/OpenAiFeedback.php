<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class OpenAiFeedback
{
    public static function generate(string $apiKey, array $task, string $answer, string $safetyIdentifier): array
    {
        $model = Settings::get('openai.model', (string)Config::get('openai_model', 'gpt-5-mini')) ?: 'gpt-5-mini';
        $criteria = implode("\n- ", array_map('strval', $task['criteria'] ?? []));
        $instructions = <<<PROMPT
Du gibst formatives, lernförderliches Feedback zu einer selbst erarbeiteten Schülerantwort im angegebenen Unterrichtsfach, Bildungsgang und Lehrplankontext.

Verbindliche Regeln:
- Verwende ausschließlich den nachfolgend registrierten Fach-, Material- und Lehrplankontext; ergänze kein vermeintliches Fachwissen, das der Aufgabe widerspricht.
- Prüfe materialnah, operatorengerecht und passend zum angegebenen Anforderungsbereich und Niveau.
- Behaupte keine amtliche Zertifizierung und erteile keine Note oder Punktzahl.
- Gib keine vollständige Musterlösung. Hilf bei der gezielten Überarbeitung.
- Beurteile keine Person, sondern ausschließlich die vorliegende Antwort.
- Zitiere den Schülertext höchstens in sehr kurzen Wortgruppen.
- Wenn sensible persönliche Angaben vorkommen, erwähne sie nicht erneut und rate knapp zur Entfernung.
- Antworte auf Deutsch und strikt im vorgegebenen JSON-Schema.
PROMPT;
        $input = "Aufgabe: {$task['title']}\nOperator: {$task['operator']}\nAnforderungsbereich: {$task['afb']}\nNiveau: {$task['level']}\nLehrplanbezug: {$task['curriculum']}\n\nMaterial- und Fachkontext:\n{$task['material']}\n\nPrüfkriterien:\n- {$criteria}\n\nSchülerantwort:\n{$answer}";
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'overall' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
                'operator_check' => ['type' => 'string'],
                'subject_check' => ['type' => 'string'],
                'revision_prompt' => ['type' => 'string'],
                'privacy_note' => ['type' => 'string'],
            ],
            'required' => ['overall', 'strengths', 'next_steps', 'operator_check', 'subject_check', 'revision_prompt', 'privacy_note'],
        ];
        $request = [
            'model' => $model,
            'store' => false,
            'instructions' => $instructions,
            'input' => [[
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => $input]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'learning_feedback',
                    'strict' => true,
                    'schema' => $schema,
                ],
                'verbosity' => 'medium',
            ],
            'max_output_tokens' => 900,
            'safety_identifier' => substr($safetyIdentifier, 0, 64),
        ];
        $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new \RuntimeException('Die Feedbackanfrage konnte nicht vorbereitet werden.');
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: learning-html-feedback/1.0',
                ],
                'content' => $body,
                'timeout' => (int)Config::get('openai_timeout_seconds', 45),
                'ignore_errors' => true,
            ],
        ]);
        $started = hrtime(true);
        $raw = @file_get_contents('https://api.openai.com/v1/responses', false, $context);
        $latency = (int)((hrtime(true) - $started) / 1_000_000);
        $status = self::httpStatus($http_response_header ?? []);
        if (!is_string($raw)) throw new \RuntimeException('Der KI-Dienst ist momentan nicht erreichbar.');
        $response = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($response)) {
            $message = is_array($response) ? Security::clean($response['error']['message'] ?? '', 240) : '';
            error_log('OpenAI feedback rejected (' . $status . '): ' . ($message !== '' ? $message : 'no structured message'));
            throw new \RuntimeException('Der KI-Dienst konnte die Rückmeldung gerade nicht erzeugen. Bitte später erneut versuchen oder die Lehrkraft informieren.');
        }
        $outputText = '';
        foreach (is_array($response['output'] ?? null) ? $response['output'] : [] as $item) {
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
                if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) $outputText .= $content['text'];
            }
        }
        $feedback = json_decode($outputText, true);
        if (!is_array($feedback)) throw new \RuntimeException('Das KI-Feedback hatte ein unerwartetes Format.');
        foreach (['overall', 'strengths', 'next_steps', 'operator_check', 'subject_check', 'revision_prompt', 'privacy_note'] as $required) {
            if (!array_key_exists($required, $feedback)) throw new \RuntimeException('Das KI-Feedback ist unvollständig.');
        }
        return [
            'feedback' => $feedback,
            'model' => (string)($response['model'] ?? $model),
            'input_tokens' => (int)($response['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int)($response['usage']['output_tokens'] ?? 0),
            'latency_ms' => $latency,
        ];
    }

    private static function httpStatus(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $match)) return (int)$match[1];
        }
        return 0;
    }
}
