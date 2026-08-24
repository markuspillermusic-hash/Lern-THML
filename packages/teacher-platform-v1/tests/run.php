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
    'feedback_org_month_limit' => 2000,
];
file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
putenv('TEACHER_PLATFORM_CONFIG=' . $configFile);
require dirname(__DIR__) . '/app/bootstrap.php';

use ReligionPlatform\Auth;
use ReligionPlatform\Database;
use ReligionPlatform\FeedbackGateway;
use ReligionPlatform\FeedbackRegistry;
use ReligionPlatform\Maintenance;
use ReligionPlatform\Rooms;
use ReligionPlatform\Security;
use ReligionPlatform\Vault;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'ok - ', $message, PHP_EOL;
}

check(Auth::userCount() === 0, 'fresh database has no users');
$db = Database::connection();
$now = time();
$db->exec("INSERT INTO organisations(name,slug,kind,status,monthly_request_limit,created_at,updated_at) VALUES('Testschule','testschule','school','active',2000,$now,$now)");
$db->exec("INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES('test','test@example.invalid','Test', '" . password_hash('ValidPassword123', PASSWORD_ARGON2ID) . "','admin','active','v1',$now,$now)");
$userId = (int)$db->lastInsertId();
$db->exec("INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES($userId,1,'owner',1,$now)");
Vault::put('user', $userId, 'openai_api_key', 'unit-test-secret-value-123456789');
check(Vault::has('user', $userId, 'openai_api_key'), 'vault reports stored secret');
check(Vault::get('user', $userId, 'openai_api_key') === 'unit-test-secret-value-123456789', 'vault decrypts exact secret');
Vault::delete('user', $userId, 'openai_api_key');
check(!Vault::has('user', $userId, 'openai_api_key'), 'vault deletes secret');
$task = FeedbackRegistry::task('musik-10-spannung', 'spannung-zusammenfassen');
check(is_array($task) && $task['operator'] === 'zusammenfassen', 'example feedback registry loads');
check(FeedbackRegistry::task('musik-10-spannung', 'unknown') === null, 'unknown feedback task rejected');
check(is_array(FeedbackRegistry::task('musik-10-spannung', 'spannung-gestalten')), 'advanced example task loads');

$owner = $db->query("SELECT * FROM users WHERE id=$userId")->fetch();
$db->exec("INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES('other','other@example.invalid','Andere Lehrkraft', '" . password_hash('ValidPassword456', PASSWORD_ARGON2ID) . "','teacher','active','v2',$now,$now)");
$otherId = (int)$db->lastInsertId();
$db->exec("INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES($otherId,1,'teacher',1,$now)");
$other = $db->query("SELECT * FROM users WHERE id=$otherId")->fetch();

Rooms::mirror('ABC234', 'musik-10-spannung', $owner, [
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
Vault::put('organisation', 1, 'openai_api_key', 'unit-test-organisation-secret-123456789');
Rooms::setFeedback('ABC234', $owner, true);
$availability = FeedbackGateway::availability('ABC234', 'spannung-zusammenfassen');
check(!empty($availability['available']), 'organisation key enables registered task in owned room');
Vault::delete('organisation', 1, 'openai_api_key');
check(empty(FeedbackGateway::availability('ABC234', 'spannung-zusammenfassen')['available']), 'removing key disables feedback availability');
$ownershipBlocked = false;
try { Rooms::setFeedback('ABC234', $other, false); } catch (RuntimeException) { $ownershipBlocked = true; }
check($ownershipBlocked, 'teacher cannot change feedback for another account room');
Rooms::end('ABC234', $owner);
check(Rooms::find('ABC234') === null, 'ended room is no longer active');

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
