<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class FeedbackGateway
{
    public static function availability(string $roomCode, string $taskId): array
    {
        $room = Rooms::find(strtoupper($roomCode));
        if (!$room) return ['available' => false, 'reason' => 'Der Klassenraum ist nicht aktiv.'];
        $task = FeedbackRegistry::task((string)$room['module_slug'], $taskId);
        if (!$task) return ['available' => false, 'reason' => 'Für diese Aufgabe ist kein geprüftes Feedbackraster hinterlegt.'];
        if (empty($room['ai_feedback_enabled'])) return ['available' => false, 'reason' => 'Die Lehrkraft hat KI-Feedback für diesen Raum nicht freigeschaltet.'];
        $owner = self::owner((int)$room['owner_user_id']);
        if (!$owner || !Vault::resolveOpenAiKey($owner, (int)($room['organisation_id'] ?? 0))) {
            return ['available' => false, 'reason' => 'Für diesen Raum ist kein API-Zugang verfügbar.'];
        }
        return [
            'available' => true,
            'reason' => '',
            'minimumChars' => (int)($task['minimum_chars'] ?? 180),
            'maximumChars' => (int)($task['maximum_chars'] ?? 9000),
            'operator' => (string)$task['operator'],
            'afb' => (string)$task['afb'],
        ];
    }

    public static function generate(string $roomCode, string $taskId, string $answer, string $clientId): array
    {
        $roomCode = strtoupper(Security::clean($roomCode, 6));
        $taskId = Security::clean($taskId, 100);
        $answer = Security::cleanMultiline($answer, 12000);
        $clientId = Security::clean($clientId, 100);
        if (!preg_match('/^[A-Z2-9]{6}$/', $roomCode)) throw new \RuntimeException('Der Raumcode ist ungültig.');
        $room = Rooms::find($roomCode);
        if (!$room || empty($room['ai_feedback_enabled'])) throw new \RuntimeException('KI-Feedback ist für diesen Raum nicht freigeschaltet.');
        $task = FeedbackRegistry::task((string)$room['module_slug'], $taskId);
        if (!$task) throw new \RuntimeException('Für diese Aufgabe ist kein geprüftes Feedbackraster hinterlegt.');
        $minimum = (int)($task['minimum_chars'] ?? 180);
        $maximum = (int)($task['maximum_chars'] ?? 9000);
        if (strlen($answer) < $minimum) throw new \RuntimeException('Die Antwort ist für belastbares Feedback noch zu kurz.');
        if (strlen($answer) > $maximum) throw new \RuntimeException('Die Antwort überschreitet die für diese Aufgabe vorgesehene Länge.');
        if ($clientId === '') throw new \RuntimeException('Der anonyme Browsernachweis fehlt. Bitte die Seite neu laden.');
        $owner = self::owner((int)$room['owner_user_id']);
        if (!$owner) throw new \RuntimeException('Die zugehörige Lehrkraft ist nicht aktiv.');
        $key = Vault::resolveOpenAiKey($owner, (int)($room['organisation_id'] ?? 0));
        if (!$key) throw new \RuntimeException('Für diesen Raum ist kein API-Zugang verfügbar.');
        $studentHash = hash_hmac('sha256', $roomCode . '|' . $clientId . '|' . Security::clientIpHash('feedback'), Vault::masterKey());
        self::enforceLimits($room, $studentHash, $taskId);
        $started = hrtime(true);
        try {
            $result = OpenAiFeedback::generate((string)$key['key'], $task, $answer, $studentHash);
            self::logUsage($room, $taskId, (string)$key['scope'], (string)$result['model'], 'ok', (int)$result['input_tokens'], (int)$result['output_tokens'], (int)$result['latency_ms'], $studentHash);
            return [
                'feedback' => $result['feedback'],
                'operator' => $task['operator'],
                'afb' => $task['afb'],
                'notice' => 'Formative Überarbeitungshilfe – keine Note und kein amtlicher Erwartungshorizont.',
            ];
        } catch (\Throwable $error) {
            $latency = (int)((hrtime(true) - $started) / 1_000_000);
            self::logUsage($room, $taskId, (string)$key['scope'], (string)Config::get('openai_model', 'gpt-5-mini'), 'error', 0, 0, $latency, $studentHash);
            throw $error;
        }
    }

    private static function enforceLimits(array $room, string $studentHash, string $taskId): void
    {
        $db = Database::connection();
        $sinceHour = time() - 3600;
        $student = $db->prepare('SELECT COUNT(*) FROM feedback_usage WHERE room_code=? AND task_id=? AND student_hash=? AND status="ok" AND created_at>?');
        $student->execute([$room['code'], $taskId, $studentHash, $sinceHour]);
        if ((int)$student->fetchColumn() >= 3) throw new \RuntimeException('Für diese Aufgabe wurden in diesem Browser bereits drei Rückmeldungen erzeugt. Überarbeite nun mit den vorhandenen Hinweisen.');
        $user = $db->prepare('SELECT COUNT(*) FROM feedback_usage WHERE owner_user_id=? AND created_at>?');
        $user->execute([(int)$room['owner_user_id'], $sinceHour]);
        if ((int)$user->fetchColumn() >= (int)Config::get('feedback_user_hour_limit', 12)) throw new \RuntimeException('Das stündliche Kontingent der Lehrkraft ist vorübergehend ausgeschöpft.');
        $roomUsage = $db->prepare('SELECT COUNT(*) FROM feedback_usage WHERE room_code=? AND status="ok" AND created_at>?');
        $roomUsage->execute([$room['code'], time() - 86400]);
        if ((int)$roomUsage->fetchColumn() >= (int)$room['ai_request_limit']) throw new \RuntimeException('Das Tageskontingent dieses Raums ist ausgeschöpft.');
        $orgId = (int)($room['organisation_id'] ?? 0);
        if ($orgId > 0) {
            $startMonth = strtotime(date('Y-m-01 00:00:00')) ?: time() - 2678400;
            $org = $db->prepare('SELECT COUNT(*) FROM feedback_usage WHERE organisation_id=? AND status="ok" AND created_at>?');
            $org->execute([$orgId, $startMonth]);
            $limit = $db->prepare('SELECT monthly_request_limit FROM organisations WHERE id=?');
            $limit->execute([$orgId]);
            if ((int)$org->fetchColumn() >= (int)($limit->fetchColumn() ?: Config::get('feedback_org_month_limit', 2000))) {
                throw new \RuntimeException('Das Monatskontingent der Organisation ist ausgeschöpft.');
            }
        }
    }

    private static function logUsage(array $room, string $taskId, string $scope, string $model, string $status, int $input, int $output, int $latency, string $studentHash): void
    {
        $statement = Database::connection()->prepare('INSERT INTO feedback_usage(room_code,module_slug,task_id,owner_user_id,organisation_id,key_scope,model,status,input_tokens,output_tokens,latency_ms,student_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$room['code'], $room['module_slug'], $taskId, $room['owner_user_id'], $room['organisation_id'], $scope, $model, $status, $input, $output, $latency, $studentHash, time()]);
        if ($status === 'ok') {
            $update = Database::connection()->prepare('UPDATE rooms SET ai_request_count=ai_request_count+1,updated_at=? WHERE code=?');
            $update->execute([time(), $room['code']]);
        }
    }

    private static function owner(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id=? AND status="active"');
        $statement->execute([$id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }
}
