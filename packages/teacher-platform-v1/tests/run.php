<?php
declare(strict_types=1);

$temp = sys_get_temp_dir() . '/teacher-platform-test-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$keyFile = $temp . '/master.key';
file_put_contents($keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "\n");
$configFile = $temp . '/config.php';
$config = [
    'database' => $temp . '/test.sqlite',
    'data_dir' => $temp,
    'master_key_file' => $keyFile,
    'session_name' => 'teacher_platform_test',
    'legacy_auth_file' => $temp . '/legacy.php',
    'base_url' => 'https://example.invalid',
    'mail_mode' => 'disabled',
    'feedback_org_month_limit' => 2000,
];
file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
putenv('TEACHER_PLATFORM_CONFIG=' . $configFile);
require dirname(__DIR__) . '/app/bootstrap.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

use ReligionPlatform\Auth;
use ReligionPlatform\AccessRequests;
use ReligionPlatform\AiGrants;
use ReligionPlatform\Database;
use ReligionPlatform\FeedbackGateway;
use ReligionPlatform\FeedbackRegistry;
use ReligionPlatform\Maintenance;
use ReligionPlatform\Mfa;
use ReligionPlatform\PasswordResets;
use ReligionPlatform\Rooms;
use ReligionPlatform\Security;
use ReligionPlatform\SupportTickets;
use ReligionPlatform\Vault;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'ok - ', $message, PHP_EOL;
}

check(Auth::userCount() === 0, 'fresh database has no users');
check(Mfa::verifySecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59), 'TOTP verification matches RFC 6238 test vector reduced to six digits');
$db = Database::connection();
$now = time();
$db->exec("INSERT INTO organisations(name,slug,kind,status,monthly_request_limit,created_at,updated_at) VALUES('Testschule','testschule','school','active',2000,$now,$now)");
$db->exec("INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES('test','test@example.invalid','Test', '" . password_hash('ValidPassword123', PASSWORD_ARGON2ID) . "','admin','active','v1',$now,$now)");
$userId = (int)$db->lastInsertId();
$db->exec("INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES($userId,1,'owner',1,$now)");
Vault::put('user', $userId, 'openai_api_key', 'sk-test-secret-value-123456789');
check(Vault::has('user', $userId, 'openai_api_key'), 'vault reports stored secret');
check(Vault::get('user', $userId, 'openai_api_key') === 'sk-test-secret-value-123456789', 'vault decrypts exact secret');
Vault::delete('user', $userId, 'openai_api_key');
check(!Vault::has('user', $userId, 'openai_api_key'), 'vault deletes secret');
$task = FeedbackRegistry::task('kr12-12.1.1-wer-bin-ich', 'frankl-zusammenfassen');
check(is_array($task) && $task['operator'] === 'zusammenfassen', 'Q12 feedback registry loads');
check(FeedbackRegistry::task('kr12-12.1.1-wer-bin-ich', 'unknown') === null, 'unknown feedback task rejected');
check(is_array(FeedbackRegistry::task('kr12-12.1.1-wer-bin-ich', 'rechte-ea')), 'eA rights feedback task loads');

$owner = $db->query("SELECT * FROM users WHERE id=$userId")->fetch();
$db->exec("INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES('other','other@example.invalid','Andere Lehrkraft', '" . password_hash('ValidPassword456', PASSWORD_ARGON2ID) . "','teacher','active','v2',$now,$now)");
$otherId = (int)$db->lastInsertId();
$db->exec("INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES($otherId,1,'teacher',1,$now)");
$other = $db->query("SELECT * FROM users WHERE id=$otherId")->fetch();
check(Auth::changeUsername($other, 'renamed.teacher', 'ValidPassword456') === 'renamed.teacher', 'teacher can change their login name with the current password');
check((string)$db->query("SELECT username FROM users WHERE id=$otherId")->fetchColumn() === 'renamed.teacher', 'changed login name is stored on the same account');
$duplicateUsernameBlocked = false;
try { Auth::changeUsername($other, 'test', 'ValidPassword456'); } catch (RuntimeException) { $duplicateUsernameBlocked = true; }
check($duplicateUsernameBlocked, 'an existing login name cannot be assigned to another account');

$requestInsert = $db->prepare('INSERT INTO access_requests(name,email,school,subjects,bundesland,access_type,reason,status,ip_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,"pending",?,?,?)');
$requestInsert->execute(['Neue Lehrkraft','new@example.invalid','Testschule','Katholische Religionslehre','Bayern','own-school','Testanfrage mit ausreichend langem Begründungstext.',hash('sha256','ip'),$now,$now]);
$requestId = (int)$db->lastInsertId();
$db->exec("UPDATE access_requests SET platform_updates_opt_in=1,platform_updates_opted_at=$now WHERE id=$requestId");
$decision = AccessRequests::decide($requestId, 'approve', $owner, 'geprüft');
check(($decision['status'] ?? '') === 'approved', 'approved access request remains as tracked request');
check(empty($decision['invitation']['email_sent']), 'mail-disabled invitation remains visible as unsent');
check(count(AccessRequests::list('approved')) === 1, 'approved request appears as registration pending');
preg_match('/[?&]invite=([^&]+)/', (string)$decision['invitation']['url'], $inviteMatch);
$inviteToken = rawurldecode((string)($inviteMatch[1] ?? ''));
$newUserId = Auth::createFromInvitation($inviteToken, [
    'display_name' => 'Neue Lehrkraft',
    'username' => 'neue.lehrkraft',
    'password' => 'ValidPassword789',
]);
check($newUserId > 0, 'invitation creates teacher account');
check(count(AccessRequests::list('approved')) === 0 && count(AccessRequests::list('registered')) === 1, 'successful registration closes the intermediate state');
$newUserPreference = $db->query("SELECT * FROM users WHERE id=$newUserId")->fetch();
check((int)$newUserPreference['platform_updates_opt_in'] === 1, 'voluntary platform mail preference transfers to registered account');
AccessRequests::updatePlatformMailPreference($newUserPreference, false);
$withdrawnPreference = $db->query("SELECT platform_updates_opt_in,platform_updates_withdrawn_at FROM users WHERE id=$newUserId")->fetch();
check((int)$withdrawnPreference['platform_updates_opt_in'] === 0 && !empty($withdrawnPreference['platform_updates_withdrawn_at']), 'teacher can withdraw optional platform mail preference');

$requestInsert->execute(['Externe Lehrkraft','external@example.invalid','Andere Schule','Katholische Religionslehre','Bayern','external','Externe Testanfrage mit ausreichend langem Begründungstext.',hash('sha256','ip2'),$now,$now]);
$externalRequestId = (int)$db->lastInsertId();
$externalDecision = AccessRequests::decide($externalRequestId, 'approve', $owner, 'extern geprüft');
preg_match('/[?&]invite=([^&]+)/', (string)$externalDecision['invitation']['url'], $externalInviteMatch);
$externalUserId = Auth::createFromInvitation(rawurldecode((string)($externalInviteMatch[1] ?? '')), [
    'display_name' => 'Externe Lehrkraft',
    'username' => 'externe.lehrkraft',
    'password' => 'ExternalPassword789',
]);
$externalMembership = $db->prepare('SELECT COUNT(*) FROM organisation_memberships WHERE user_id=?');
$externalMembership->execute([$externalUserId]);
check((int)$externalMembership->fetchColumn() === 0, 'external account receives no school organisation membership');

$optionalReasonRequestId = AccessRequests::create([
    'form_token' => Security::formToken('access-request', $now - 5),
    'name' => 'Lehrkraft ohne Nachricht',
    'email' => 'optional@example.invalid',
    'school' => 'Testschule',
    'subjects' => 'Katholische Religionslehre',
    'bundesland' => 'Bayern',
    'access_type' => 'external',
    'reason' => '',
    'privacy_acknowledged' => '1',
]);
$optionalReason = $db->query("SELECT reason,platform_updates_opt_in FROM access_requests WHERE id=$optionalReasonRequestId")->fetch();
check($optionalReasonRequestId > 0 && $optionalReason['reason'] === '' && (int)$optionalReason['platform_updates_opt_in'] === 0, 'access request accepts an empty optional message without update-mail consent');

Rooms::mirror('ABC234', 'kr12-12.1.1-wer-bin-ich', $owner, [
    'code' => 'ABC234',
    'label' => 'Testkurs',
    'createdAt' => $now,
    'expiresAt' => $now + 86400,
    'aiFeedbackEnabled' => false,
]);
check(Rooms::find('ABC234') !== null, 'room mirror is active');
check(count(Rooms::listForUser($owner)) === 1, 'admin sees active room');
check(count(Rooms::listForUser($other)) === 0, 'teacher cannot list room owned by another account');
$blocked = false;
try { Rooms::setFeedback('ABC234', $owner, true); } catch (RuntimeException) { $blocked = true; }
check($blocked, 'feedback cannot be enabled without account or organisation key');
Vault::put('organisation', 1, 'openai_api_key', 'sk-test-organisation-secret-123456789');
Rooms::setFeedback('ABC234', $owner, true);
$availability = FeedbackGateway::availability('ABC234', 'frankl-zusammenfassen');
check(!empty($availability['available']), 'organisation key enables registered task in owned room');
$db->exec('UPDATE organisations SET monthly_request_limit=0 WHERE id=1');
check(empty(FeedbackGateway::availability('ABC234', 'frankl-zusammenfassen')['available']), 'organisation limit zero disables school-funded feedback');
$db->exec('UPDATE organisations SET monthly_request_limit=2000 WHERE id=1');
Vault::delete('organisation', 1, 'openai_api_key');
check(empty(FeedbackGateway::availability('ABC234', 'frankl-zusammenfassen')['available']), 'removing key disables feedback availability');

AiGrants::setSystemKey($owner, 'sk-test-sponsored-secret-123456789');
$grantId = AiGrants::create($owner, $userId, [
    'label' => 'Testförderung',
    'starts_at' => date('Y-m-d', $now - 86400),
    'expires_at' => date('Y-m-d', $now + 86400),
    'request_limit' => 2,
    'token_limit' => 2000,
    'allowed_modules' => 'kr12-12.1.1-wer-bin-ich',
]);
check($grantId > 0 && !empty(FeedbackGateway::availability('ABC234', 'frankl-zusammenfassen')['available']), 'sponsored grant enables feedback without exposing a shared key');
check(AiGrants::reserveRequest($grantId), 'first sponsored request can be reserved atomically');
check(AiGrants::reserveRequest($grantId), 'second sponsored request can be reserved atomically');
check(!AiGrants::reserveRequest($grantId), 'sponsored request limit is hard');
$ownershipBlocked = false;
try { Rooms::setFeedback('ABC234', $other, false); } catch (RuntimeException) { $ownershipBlocked = true; }
check($ownershipBlocked, 'teacher cannot change feedback for another account room');
Rooms::end('ABC234', $owner);
check(Rooms::find('ABC234') === null, 'ended room is no longer active');

$resetToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$resetInsert = $db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,requested_ip_hash,expires_at,created_at) VALUES(?,?,?,?,?)');
$resetInsert->execute([$otherId, hash('sha256', $resetToken), hash('sha256','reset-ip'), $now + 900, $now]);
PasswordResets::complete($resetToken, 'A sufficiently long test password', 'A sufficiently long test password');
$changedHash = (string)$db->query("SELECT password_hash FROM users WHERE id=$otherId")->fetchColumn();
check(password_verify('A sufficiently long test password', $changedHash), 'password reset replaces the Argon2id hash');
check(PasswordResets::validToken($resetToken) === null, 'password reset token is single-use');

$support = SupportTickets::create([
    'category' => 'room',
    'subject' => 'Testproblem im Kursraum',
    'body' => 'Eine ausreichend lange Beschreibung des reproduzierbaren Testproblems.',
], $other);
check(!empty($support['public_id']), 'authenticated teacher can create a support ticket');
$supportTicket = SupportTickets::get((int)$support['id'], $other);
check(count($supportTicket['messages']) === 1, 'support ticket keeps its initial message');
$supportBlocked = false;
try { SupportTickets::get((int)$support['id'], $owner); } catch (RuntimeException) { $supportBlocked = true; }
check(!$supportBlocked, 'administrator can inspect teacher support ticket');
SupportTickets::reply((int)$support['id'], $owner, 'Interne Diagnose für die Administration.', true);
$teacherTicket = SupportTickets::get((int)$support['id'], $other);
check(count($teacherTicket['messages']) === 1, 'internal support notes are hidden from teacher accounts');

$formToken = Security::formToken('test-purpose');
check(Security::verifyFormToken($formToken, 'test-purpose', 30, 0), 'unpadded signed form token verifies');
$rateSubject = hash('sha256', 'test-subject');
check(Security::rateLimit('test-bucket', $rateSubject, 60, 2), 'first rate-limited action allowed');
check(Security::rateLimit('test-bucket', $rateSubject, 60, 2), 'second rate-limited action allowed');
check(!Security::rateLimit('test-bucket', $rateSubject, 60, 2), 'rate limit blocks excess action');

$moduleRooms = $temp . '/modules/test-module/rooms';
mkdir($moduleRooms, 0700, true);
file_put_contents($moduleRooms . '/room-ABC234.json', json_encode(['expiresAt' => $now - 10]));
file_put_contents($moduleRooms . '/room-DEF567.json', json_encode(['expiresAt' => $now + 86400]));
$maintenance = Maintenance::run($now);
check(($maintenance['room_files'] ?? 0) === 1, 'maintenance removes expired room state');
check(!is_file($moduleRooms . '/room-ABC234.json') && is_file($moduleRooms . '/room-DEF567.json'), 'maintenance preserves active room state');

echo "all tests passed\n";
