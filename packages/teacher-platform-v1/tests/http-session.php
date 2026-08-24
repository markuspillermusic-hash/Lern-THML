<?php
declare(strict_types=1);

require '/websites/_protected/teacher-platform-v1/app/bootstrap.php';

use ReligionPlatform\Auth;
use ReligionPlatform\Database;

$db = Database::connection();
$now = time();
if (Auth::userCount() === 0) {
    $db->exec("INSERT INTO organisations(name,slug,kind,status,monthly_request_limit,created_at,updated_at) VALUES('Integrationsschule','integration','school','active',2000,$now,$now)");
    $organisationId = (int)$db->lastInsertId();
    $accounts = [
        ['integration', 'integration@example.invalid', 'Integration Admin', 'admin', 'IntegrationTest123A'],
        ['other', 'other@example.invalid', 'Andere Lehrkraft', 'teacher', 'IntegrationTest456B'],
    ];
    foreach ($accounts as [$username, $email, $name, $role, $password]) {
        $statement = $db->prepare('INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $statement->execute([$username, $email, $name, password_hash($password, PASSWORD_ARGON2ID), $role, 'active', bin2hex(random_bytes(16)), $now, $now]);
        $userId = (int)$db->lastInsertId();
        $membership = $db->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES(?,?,?,?,?)');
        $membership->execute([$userId, $organisationId, $role === 'admin' ? 'owner' : 'teacher', 1, $now]);
    }
}

$other = ($_GET['as'] ?? '') === 'other';
Auth::login($other ? 'other' : 'integration', $other ? 'IntegrationTest456B' : 'IntegrationTest123A');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'account' => $other ? 'other' : 'owner']);
