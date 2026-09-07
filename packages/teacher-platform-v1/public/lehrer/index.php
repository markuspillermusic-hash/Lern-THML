<?php
declare(strict_types=1);

require_once getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/websites/_protected/teacher-platform-v1/app/bootstrap.php';

use ReligionPlatform\AccessRequests;
use ReligionPlatform\AiGrants;
use ReligionPlatform\Audit;
use ReligionPlatform\Auth;
use ReligionPlatform\Config;
use ReligionPlatform\Database;
use ReligionPlatform\Invitations;
use ReligionPlatform\Mailer;
use ReligionPlatform\Mfa;
use ReligionPlatform\Modules;
use ReligionPlatform\PasswordResets;
use ReligionPlatform\Rooms;
use ReligionPlatform\Security;
use ReligionPlatform\Settings;
use ReligionPlatform\SupportTickets;
use ReligionPlatform\Vault;

Security::headers("default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
Security::startSession();

$error = '';
$success = (string)($_SESSION['platform_flash_success'] ?? '');
$oneTime = (string)($_SESSION['platform_flash_once'] ?? '');
$recoveryOneTime = $_SESSION['platform_flash_recovery'] ?? [];
unset($_SESSION['platform_flash_success'], $_SESSION['platform_flash_once'], $_SESSION['platform_flash_recovery']);

function local_redirect(string $path = '/lehrer/'): never {
    $parts = parse_url($path);
    if (
        preg_match('/[\r\n]/', $path)
        || !is_array($parts)
        || isset($parts['scheme'], $parts['host'])
        || !str_starts_with($path, '/')
        || str_starts_with($path, '//')
    ) $path = '/lehrer/';
    header('Location: ' . $path);
    exit;
}

function flash_redirect(string $message, string $path = '/lehrer/'): never {
    $_SESSION['platform_flash_success'] = $message;
    local_redirect($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            throw new RuntimeException('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
        }
        $action = Security::clean($_POST['form_action'] ?? '', 60);
        if ($action === 'setup') {
            $userId = Auth::createInitialAdmin($_POST);
            Auth::login((string)$_POST['username'], (string)$_POST['password']);
            flash_redirect('Die zentrale Lehrerplattform ist eingerichtet. Bitte hinterlegen Sie als Nächstes den schulischen oder persönlichen API-Schlüssel.', '/lehrer/?view=keys');
        }
        if ($action === 'invite_accept') {
            $userId = Auth::createFromInvitation((string)($_POST['invite'] ?? ''), $_POST);
            Auth::login((string)$_POST['username'], (string)$_POST['password']);
            flash_redirect('Ihr Lehrerprofil wurde angelegt.');
        }
        if ($action === 'login') {
            Auth::login((string)($_POST['identity'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['mfa_code'] ?? ''));
            $next = (string)($_POST['next'] ?? '/lehrer/');
            local_redirect($next);
        }
        if ($action === 'password_reset_request') {
            PasswordResets::request((string)($_POST['identity'] ?? ''));
            flash_redirect('Falls ein aktives Konto zu den Angaben gehört, wurde ein einmaliger Wiederherstellungslink versendet.', '/lehrer/?forgot=sent');
        }
        if ($action === 'password_reset_complete') {
            PasswordResets::complete(
                (string)($_POST['reset_token'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirmation'] ?? '')
            );
            flash_redirect('Das Passwort wurde geändert. Sie können sich jetzt anmelden.', '/lehrer/');
        }

        $actor = Auth::requireUser(null);
        if ($action === 'logout') {
            Auth::logout();
            local_redirect('/lehrer/');
        }
        if ($action === 'save_personal_key') {
            $key = trim((string)($_POST['api_key'] ?? ''));
            if (!preg_match('/^sk-[A-Za-z0-9_-]{20,}$/', $key)) throw new RuntimeException('Der API-Schlüssel hat kein plausibles OpenAI-Format.');
            Vault::put('user', (int)$actor['id'], 'openai_api_key', $key);
            Audit::record((int)$actor['id'], 'secret.updated', 'user', (string)$actor['id'], ['kind' => 'openai_api_key']);
            flash_redirect('Der persönliche API-Schlüssel wurde verschlüsselt gespeichert.', '/lehrer/?view=keys');
        }
        if ($action === 'delete_personal_key') {
            Vault::delete('user', (int)$actor['id'], 'openai_api_key');
            Audit::record((int)$actor['id'], 'secret.deleted', 'user', (string)$actor['id'], ['kind' => 'openai_api_key']);
            flash_redirect('Der persönliche API-Schlüssel wurde gelöscht.', '/lehrer/?view=keys');
        }
        if ($action === 'change_password') {
            if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$actor['password_hash'])) throw new RuntimeException('Das bisherige Passwort stimmt nicht.');
            $new = (string)($_POST['new_password'] ?? '');
            if (!hash_equals($new, (string)($_POST['new_password_confirmation'] ?? ''))) throw new RuntimeException('Die beiden neuen Passwörter stimmen nicht überein.');
            Auth::validatePassword($new);
            $version = bin2hex(random_bytes(16));
            $statement = Database::connection()->prepare('UPDATE users SET password_hash=?,auth_version=?,updated_at=? WHERE id=?');
            $statement->execute([password_hash($new, PASSWORD_ARGON2ID), $version, time(), (int)$actor['id']]);
            $_SESSION['platform_auth_version'] = $version;
            Audit::record((int)$actor['id'], 'auth.password_changed', 'user', (string)$actor['id']);
            flash_redirect('Das Passwort wurde geändert.', '/lehrer/?view=profile');
        }
        if ($action === 'change_username') {
            $username = Auth::changeUsername(
                $actor,
                (string)($_POST['new_username'] ?? ''),
                (string)($_POST['current_password'] ?? '')
            );
            flash_redirect('Der Anmeldename wurde in „' . $username . '“ geändert.', '/lehrer/?view=profile');
        }
        if ($action === 'save_platform_mail_preference') {
            AccessRequests::updatePlatformMailPreference($actor, !empty($_POST['platform_updates_opt_in']));
            flash_redirect('Die Einstellung zu Änderungs-E-Mails wurde gespeichert.', '/lehrer/?view=profile');
        }
        if ($action === 'support_create') {
            $ticket = SupportTickets::create($_POST, $actor);
            flash_redirect('Ihre Supportanfrage ' . $ticket['public_id'] . ' wurde gespeichert.', '/lehrer/?view=support');
        }
        if ($action === 'support_reply') {
            SupportTickets::reply((int)($_POST['ticket_id'] ?? 0), $actor, (string)($_POST['body'] ?? ''), !empty($_POST['internal']));
            $target = Auth::isAdmin($actor) ? 'support-admin' : 'support';
            flash_redirect('Die Antwort wurde gespeichert.', '/lehrer/?view=' . $target . '&ticket=' . (int)($_POST['ticket_id'] ?? 0));
        }
        if ($action === 'begin_mfa') {
            if (!empty($actor['mfa_enabled_at'])) throw new RuntimeException('Die Zwei-Faktor-Anmeldung ist bereits aktiv.');
            $_SESSION['platform_pending_mfa_secret'] = Mfa::generateSecret();
            flash_redirect('Scannen oder übertragen Sie jetzt das TOTP-Geheimnis und bestätigen Sie einen aktuellen Code.', '/lehrer/?view=profile&mfa=setup');
        }
        if ($action === 'confirm_mfa') {
            $secret = (string)($_SESSION['platform_pending_mfa_secret'] ?? '');
            if ($secret === '') throw new RuntimeException('Die Einrichtung wurde nicht begonnen oder ist abgelaufen.');
            $enabled = Mfa::enable($actor, $secret, (string)($_POST['mfa_code'] ?? ''));
            $_SESSION['platform_auth_version'] = $enabled['version'];
            $_SESSION['platform_flash_recovery'] = $enabled['recovery_codes'];
            unset($_SESSION['platform_pending_mfa_secret']);
            flash_redirect('Die Zwei-Faktor-Anmeldung ist aktiv. Bewahren Sie die einmalig angezeigten Wiederherstellungscodes sicher auf.', '/lehrer/?view=profile');
        }
        if ($action === 'disable_mfa') {
            $version = Mfa::disable($actor, (string)($_POST['current_password'] ?? ''), (string)($_POST['mfa_code'] ?? ''));
            $_SESSION['platform_auth_version'] = $version;
            flash_redirect('Die Zwei-Faktor-Anmeldung wurde deaktiviert.', '/lehrer/?view=profile');
        }

        $adminActions = ['save_org_key','delete_org_key','save_mail_identity','save_smtp','test_mail','request_decision','resend_request_invite','manual_invite','set_user_status','set_user_org_scope','send_password_reset','reset_user_mfa','save_org','save_model','save_sponsored_key','delete_sponsored_key','create_ai_grant','revoke_ai_grant','support_update'];
        if (in_array($action, $adminActions, true)) $actor = Auth::requireAdmin();
        $orgId = Auth::defaultOrganisationId((int)$actor['id']);
        if ($action === 'save_org_key') {
            $key = trim((string)($_POST['api_key'] ?? ''));
            if ($orgId < 1 || !preg_match('/^sk-[A-Za-z0-9_-]{20,}$/', $key)) throw new RuntimeException('Organisation oder API-Schlüssel ist ungültig.');
            Vault::put('organisation', $orgId, 'openai_api_key', $key);
            Audit::record((int)$actor['id'], 'secret.updated', 'organisation', (string)$orgId, ['kind' => 'openai_api_key']);
            flash_redirect('Der Schulschlüssel wurde verschlüsselt gespeichert.', '/lehrer/?view=keys');
        }
        if ($action === 'delete_org_key') {
            Vault::delete('organisation', $orgId, 'openai_api_key');
            Audit::record((int)$actor['id'], 'secret.deleted', 'organisation', (string)$orgId, ['kind' => 'openai_api_key']);
            flash_redirect('Der Schulschlüssel wurde gelöscht.', '/lehrer/?view=keys');
        }
        if ($action === 'save_smtp') {
            Mailer::saveSmtp($_POST);
            Audit::record((int)$actor['id'], 'smtp.updated', 'system', 'mail');
            flash_redirect('Die SMTP-Einstellungen wurden verschlüsselt gespeichert.', '/lehrer/?view=admin');
        }
        if ($action === 'save_mail_identity') {
            Mailer::saveIdentity($_POST);
            Audit::record((int)$actor['id'], 'mail.identity_updated', 'system', 'mail');
            flash_redirect('Absender und Benachrichtigungsadresse wurden gespeichert.', '/lehrer/?view=admin');
        }
        if ($action === 'test_mail') {
            $to = Security::clean($_POST['test_to'] ?? '', 190);
            Mailer::send($to, 'Test · Religionsunterricht Lehrerplattform', "Der Mailversand der Lehrerplattform funktioniert.\n\nZeitpunkt: " . date('d.m.Y H:i'));
            flash_redirect('Die Testnachricht wurde versendet.', '/lehrer/?view=admin');
        }
        if ($action === 'request_decision') {
            $result = AccessRequests::decide((int)($_POST['request_id'] ?? 0), (string)($_POST['decision'] ?? ''), $actor, (string)($_POST['admin_note'] ?? ''));
            $invitation = $result['invitation'] ?? null;
            if (is_array($invitation) && empty($invitation['email_sent'])) {
                $_SESSION['platform_flash_once'] = (string)$invitation['url'];
            }
            $message = is_array($invitation)
                ? (!empty($invitation['email_sent']) ? 'Die Anfrage wurde genehmigt und die Einladung automatisch versendet.' : 'Die Anfrage wurde genehmigt. Der Mailversand ist fehlgeschlagen; der Link wird einmalig angezeigt.')
                : 'Die Anfrage wurde aktualisiert.';
            flash_redirect($message, '/lehrer/?view=requests&stage=' . rawurlencode($result['status'] === 'approved' ? 'approved' : $result['status']));
        }
        if ($action === 'resend_request_invite') {
            $invitation = AccessRequests::resendInvitation((int)($_POST['request_id'] ?? 0), $actor);
            if (empty($invitation['email_sent'])) $_SESSION['platform_flash_once'] = (string)$invitation['url'];
            flash_redirect(
                !empty($invitation['email_sent']) ? 'Eine neue Einladung wurde automatisch versendet; der frühere Link ist ungültig.' : 'Eine neue Einladung wurde erzeugt, der Mailversand ist jedoch fehlgeschlagen. Der Link wird einmalig angezeigt.',
                '/lehrer/?view=requests&stage=approved'
            );
        }
        if ($action === 'manual_invite') {
            $invitationOrg = ($_POST['organisation_scope'] ?? '') === 'school' ? $orgId : 0;
            $invitation = Invitations::create((string)$_POST['email'], (string)$_POST['display_name'], (string)($_POST['role'] ?? 'teacher'), $invitationOrg, (int)$actor['id']);
            if (empty($invitation['email_sent'])) $_SESSION['platform_flash_once'] = (string)$invitation['url'];
            flash_redirect(!empty($invitation['email_sent']) ? 'Die Einladung wurde automatisch versendet.' : 'Die Einladung wurde erzeugt. Der Mailversand ist fehlgeschlagen; der Link wird einmalig angezeigt.', '/lehrer/?view=admin');
        }
        if ($action === 'set_user_status') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $status = in_array(($_POST['status'] ?? ''), ['active','suspended'], true) ? $_POST['status'] : '';
            if ($targetId === (int)$actor['id'] || $status === '') throw new RuntimeException('Dieses Konto kann so nicht geändert werden.');
            \ReligionPlatform\Identity::setAccountStatus($actor,$status,teacherId:$targetId);
            flash_redirect('Der Kontostatus wurde geändert.', '/lehrer/?view=admin');
        }
        if ($action === 'set_user_org_scope') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $scope = (string)($_POST['organisation_scope'] ?? '');
            if ($targetId < 1 || $targetId === (int)$actor['id'] || !in_array($scope, ['independent','school'], true)) throw new RuntimeException('Diese Organisationszuordnung kann so nicht geändert werden.');
            if ($scope === 'school') {
                if ($orgId < 1) throw new RuntimeException('Die Schulorganisation fehlt.');
                $membership = Database::connection()->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES(?,? ,"teacher",1,?) ON CONFLICT(user_id,organisation_id) DO UPDATE SET is_default=1');
                $membership->execute([$targetId, $orgId, time()]);
            } else {
                $membership = Database::connection()->prepare('DELETE FROM organisation_memberships WHERE user_id=?');
                $membership->execute([$targetId]);
            }
            Audit::record((int)$actor['id'], 'user.organisation_scope_changed', 'user', (string)$targetId, ['scope' => $scope]);
            flash_redirect('Die Organisationszuordnung wurde geändert.', '/lehrer/?view=admin');
        }
        if ($action === 'send_password_reset') {
            PasswordResets::issueForAdmin((int)($_POST['user_id'] ?? 0), $actor);
            flash_redirect('Der persönliche Passwort-Reset wurde versendet.', '/lehrer/?view=admin');
        }
        if ($action === 'reset_user_mfa') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            if ($targetId === (int)$actor['id']) throw new RuntimeException('Die eigene Zwei-Faktor-Anmeldung ändern Sie im Profil.');
            Mfa::adminReset($actor, $targetId);
            flash_redirect('Die Zwei-Faktor-Anmeldung wurde für das Konto zurückgesetzt; bestehende Sitzungen sind beendet.', '/lehrer/?view=admin');
        }
        if ($action === 'save_org') {
            $name = Security::clean($_POST['organisation_name'] ?? '', 160);
            $limit = max(0, min(100000, (int)($_POST['monthly_request_limit'] ?? 2000)));
            if ($name === '' || $orgId < 1) throw new RuntimeException('Organisation ist ungültig.');
            $statement = Database::connection()->prepare('UPDATE organisations SET name=?,monthly_request_limit=?,updated_at=? WHERE id=?');
            $statement->execute([$name, $limit, time(), $orgId]);
            flash_redirect('Organisation und Monatskontingent wurden aktualisiert.', '/lehrer/?view=admin');
        }
        if ($action === 'save_model') {
            $model = Security::clean($_POST['openai_model'] ?? '', 80);
            if (!preg_match('/^[a-z0-9][a-z0-9.-]{2,79}$/', $model)) throw new RuntimeException('Der Modellname ist ungültig.');
            Settings::set('openai.model', $model);
            Audit::record((int)$actor['id'], 'openai.model_updated', 'system', 'openai', ['model' => $model]);
            flash_redirect('Das Feedbackmodell wurde aktualisiert.', '/lehrer/?view=admin');
        }
        if ($action === 'save_sponsored_key') {
            AiGrants::setSystemKey($actor, (string)($_POST['api_key'] ?? ''));
            flash_redirect('Der serverseitige Förderzugang wurde verschlüsselt gespeichert.', '/lehrer/?view=ai-admin');
        }
        if ($action === 'delete_sponsored_key') {
            AiGrants::deleteSystemKey($actor);
            flash_redirect('Der serverseitige Förderzugang wurde gelöscht. Bestehende Freigaben können ihn nun nicht mehr verwenden.', '/lehrer/?view=ai-admin');
        }
        if ($action === 'create_ai_grant') {
            AiGrants::create($actor, (int)($_POST['user_id'] ?? 0), $_POST);
            flash_redirect('Das Förderkontingent wurde freigegeben.', '/lehrer/?view=ai-admin');
        }
        if ($action === 'revoke_ai_grant') {
            AiGrants::revoke($actor, (int)($_POST['grant_id'] ?? 0));
            flash_redirect('Das Förderkontingent wurde widerrufen.', '/lehrer/?view=ai-admin');
        }
        if ($action === 'support_update') {
            SupportTickets::update((int)($_POST['ticket_id'] ?? 0), $actor, (string)($_POST['status'] ?? ''), (string)($_POST['priority'] ?? 'normal'));
            flash_redirect('Der Supportstatus wurde aktualisiert.', '/lehrer/?view=support-admin&ticket=' . (int)($_POST['ticket_id'] ?? 0));
        }
        throw new RuntimeException('Die angeforderte Aktion ist unbekannt.');
    } catch (RuntimeException $caught) {
        $error = $caught->getMessage();
    } catch (Throwable $caught) {
        error_log('teacher-platform portal: ' . $caught->getMessage());
        $error = 'Die Aktion konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut oder wenden Sie sich an die Administration.';
    }
}

$csrf = Security::csrf();
$userCount = Auth::userCount();
$inviteToken = Security::clean($_GET['invite'] ?? '', 120);
$resetToken = Security::clean($_GET['reset'] ?? '', 120);
$forgotMode = isset($_GET['forgot']);
$currentUser = Auth::currentUser(null);
$view = Security::clean($_GET['view'] ?? 'overview', 30);
$next = (string)($_GET['next'] ?? '/lehrer/');
$nextParts = parse_url($next);
if (
    preg_match('/[\r\n]/', $next)
    || !is_array($nextParts)
    || isset($nextParts['scheme'], $nextParts['host'])
    || !str_starts_with($next, '/')
    || str_starts_with($next, '//')
) $next = '/lehrer/';

function page_head(string $title): void { ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title><?= Security::h($title) ?></title><link rel="stylesheet" href="/lehrer/assets/platform.css?v=1.2.1"><script src="/lehrer/assets/platform.js?v=1.2.1" defer></script></head><body>
<?php }

if ($userCount === 0) {
    page_head('Ersteinrichtung · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Einmalige Ersteinrichtung</p><h1>Persönliches Administratorkonto anlegen</h1><p>Das bisherige gemeinsame Lehrerpasswort autorisiert nur diesen einmaligen Übergang. Danach melden Sie sich mit Benutzername und neuem persönlichen Passwort an.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="setup">
      <label>Bisheriges Lehrerpasswort<input type="password" name="legacy_password" autocomplete="current-password" required></label>
      <div class="form-grid"><label>Anzeigename<input name="display_name" autocomplete="name" required></label><label>Benutzername<input name="username" autocomplete="username" pattern="[a-zA-Z0-9._-]{3,60}" required></label></div>
      <label>E-Mail<input type="email" name="email" autocomplete="email" required></label><label>Schule/Organisation<input name="school" autocomplete="organization" required></label>
      <label>Neues persönliches Passwort<input type="password" name="password" autocomplete="new-password" minlength="15" maxlength="256" required><small>Mindestens 15 Zeichen. Ein langer, gut merkbarer Passwortsatz ist geeignet.</small></label>
      <button class="primary" type="submit">Plattform sicher einrichten</button>
    </form><p class="auth-links"><a href="/datenschutz-lernplattform/">Datenschutzinformationen</a><a href="/bereiche/mensch-gott-welt.html">Zu den Lernbereichen</a></p></section></main></body></html><?php exit;
}

if ($inviteToken !== '' && !$currentUser) {
    page_head('Einladung annehmen · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Persönlicher Zugang</p><h1>Einladung annehmen</h1><p>Wählen Sie Ihren Benutzernamen und ein persönliches Passwort. Ein API-Schlüssel ist optional und kann später verschlüsselt hinterlegt werden.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="invite_accept"><input type="hidden" name="invite" value="<?= Security::h($inviteToken) ?>">
      <label>Anzeigename<input name="display_name" autocomplete="name" required></label><label>Benutzername<input name="username" autocomplete="username" pattern="[a-zA-Z0-9._-]{3,60}" required></label><label>Passwort<input type="password" name="password" autocomplete="new-password" minlength="15" maxlength="256" required><small>Mindestens 15 Zeichen; ein Passwortsatz ist geeignet.</small></label><button class="primary" type="submit">Lehrerprofil anlegen</button>
    </form><p class="auth-links"><a href="/datenschutz-lernplattform/">Datenschutzinformationen</a><a href="/bereiche/mensch-gott-welt.html">Zu den Lernbereichen</a></p></section></main></body></html><?php exit;
}

if ($resetToken !== '' && !$currentUser) {
    $validReset = PasswordResets::validToken($resetToken);
    page_head('Passwort neu setzen · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Kontowiederherstellung</p><h1>Neues Passwort festlegen</h1>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <?php if ($validReset): ?>
      <p>Der Link gilt nur für <?= Security::h((string)$validReset['email']) ?> und wird nach der Verwendung ungültig.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="password_reset_complete"><input type="hidden" name="reset_token" value="<?= Security::h($resetToken) ?>">
        <label>Neues Passwort<input type="password" name="password" autocomplete="new-password" minlength="15" maxlength="256" required><small>Mindestens 15 Zeichen; ein langer Passwortsatz ist geeignet.</small></label>
        <label>Neues Passwort wiederholen<input type="password" name="password_confirmation" autocomplete="new-password" minlength="15" maxlength="256" required></label>
        <button class="primary" type="submit">Passwort sicher ändern</button>
      </form>
    <?php else: ?><p class="alert error">Dieser Wiederherstellungslink ist ungültig, abgelaufen oder wurde bereits verwendet.</p><p><a class="button primary" href="/lehrer/?forgot=1">Neuen Link anfordern</a></p><?php endif; ?>
    <p class="auth-links"><a href="/lehrer/">Zur Anmeldung</a><a href="/support-anfragen/">Support</a><a href="/datenschutz-lernplattform/">Datenschutz</a></p></section></main></body></html><?php exit;
}

if ($forgotMode && !$currentUser) {
    page_head('Passwort vergessen · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Kontowiederherstellung</p><h1>Passwort zurücksetzen</h1><p>Geben Sie Benutzername oder E-Mail-Adresse ein. Aus Sicherheitsgründen bestätigt die Plattform nicht, ob ein passendes Konto existiert.</p>
    <?php if ($success): ?><p class="alert success" role="status"><?= Security::h($success) ?></p><?php endif; ?><?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="password_reset_request"><label>Benutzername oder E-Mail<input name="identity" autocomplete="username" required autofocus></label><button class="primary" type="submit">Wiederherstellungslink anfordern</button></form>
    <p class="auth-links"><a href="/lehrer/">Zur Anmeldung</a><a href="/support-anfragen/">Support</a><a href="/datenschutz-lernplattform/">Datenschutz</a></p></section></main></body></html><?php exit;
}

if (!$currentUser) {
    page_head('Anmelden · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Religionsunterricht</p><h1>Lehrerbereich</h1><p>Verwalten Sie Lernpfade, vorbereitete Kursräume und Ihren persönlichen KI-Zugang geräteübergreifend.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="login"><input type="hidden" name="next" value="<?= Security::h($next) ?>"><label>Benutzername oder E-Mail<input name="identity" autocomplete="username" required autofocus></label><label>Passwort<input type="password" name="password" autocomplete="current-password" required></label><label>Bestätigungscode <small>Nur falls die Zwei-Faktor-Anmeldung aktiviert ist; alternativ Wiederherstellungscode.</small><input name="mfa_code" autocomplete="one-time-code"></label><button class="primary" type="submit">Anmelden</button></form>
    <div class="auth-links"><a href="/lehrer/?forgot=1">Passwort vergessen</a><a href="/zugriff-anfragen/">Zugriff anfragen</a><a href="/support-anfragen/">Support</a><a href="/datenschutz-lernplattform/">Datenschutz</a><a href="/bereiche/mensch-gott-welt.html">Zu den Lernbereichen</a></div></section></main></body></html><?php exit;
}

$org = Auth::defaultOrganisation((int)$currentUser['id']);
$orgId = (int)($org['id'] ?? 0);
$isAdmin = Auth::isAdmin($currentUser);
$modules = Modules::all($isAdmin);
$rooms = Rooms::listForUser($currentUser);
$roomTeacherUrl = static function (array $room): string {
    $base = trim((string)($room['module_teacher_url'] ?? ''));
    if ($base === '' || $base[0] !== '/') {
        $base = '/lehrer/';
    }
    $separator = str_contains($base, '?') ? '&' : '?';
    return $base . $separator . 'room=' . rawurlencode((string)$room['code']);
};
$personalKey = Vault::has('user', (int)$currentUser['id'], 'openai_api_key');
$orgKey = $orgId > 0 && Vault::has('organisation', $orgId, 'openai_api_key');
$userGrants = AiGrants::list((int)$currentUser['id']);
$activeGrant = array_values(array_filter($userGrants, static fn(array $grant): bool => $grant['status'] === 'active' && (int)$grant['starts_at'] <= time() && (int)$grant['expires_at'] > time() && (int)$grant['used_requests'] < (int)$grant['request_limit'] && (int)$grant['used_tokens'] < (int)$grant['token_limit']))[0] ?? null;
page_head('Lehrerbereich · Religionsunterricht');
?>
<header class="app-header"><div><a class="brand" href="/lehrer/">Religionsunterricht</a><span>Lehrerplattform</span></div><div class="account"><span><?= Security::h((string)$currentUser['display_name']) ?></span><form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="logout"><button class="text-button" type="submit">Abmelden</button></form></div></header>
<div class="app-layout"><nav class="side-nav" aria-label="Lehrerbereich"><a href="/zugang/">Mein Unterricht · gemeinsamer Zugang</a><a href="/zugang/?view=classes">Gemeinsame Klassen</a><a href="/zugang/arbeiten/">Schülerstände</a><a class="<?= $view==='overview'?'active':'' ?>" href="/lehrer/">Überblick</a><a class="<?= $view==='rooms'?'active':'' ?>" href="/lehrer/?view=rooms">Kursräume</a><a class="<?= $view==='keys'?'active':'' ?>" href="/lehrer/?view=keys">KI-Zugang</a><a class="<?= $view==='support'?'active':'' ?>" href="/lehrer/?view=support">Support</a><a class="<?= $view==='profile'?'active':'' ?>" href="/lehrer/?view=profile">Profil</a><?php if ($isAdmin): ?><hr><a class="<?= $view==='requests'?'active':'' ?>" href="/lehrer/?view=requests">Zugriffsanfragen</a><a class="<?= $view==='support-admin'?'active':'' ?>" href="/lehrer/?view=support-admin">Supportfälle</a><a class="<?= $view==='ai-admin'?'active':'' ?>" href="/lehrer/?view=ai-admin">KI-Kontingente</a><a class="<?= $view==='admin'?'active':'' ?>" href="/lehrer/?view=admin">Administration</a><?php endif; ?><hr><a href="/datenschutz-lernplattform/">Datenschutz</a></nav>
<main class="content">
<?php if ($success): ?><p class="alert success" role="status"><?= Security::h($success) ?></p><?php endif; ?>
<?php if ($oneTime): ?><div class="alert notice"><strong>Einladungslink – nur jetzt sichtbar:</strong><div class="copy-line"><input readonly value="<?= Security::h($oneTime) ?>"><button type="button" data-copy-nearby>Kopieren</button></div></div><?php endif; ?>
<?php if (is_array($recoveryOneTime) && $recoveryOneTime): ?><div class="alert notice"><strong>Wiederherstellungscodes – nur jetzt sichtbar:</strong><p>Jeder Code kann genau einmal statt des sechsstelligen Codes verwendet werden. Offline und getrennt vom Passwort aufbewahren.</p><pre class="recovery-codes"><?= Security::h(implode("\n", $recoveryOneTime)) ?></pre></div><?php endif; ?>
<?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>

<?php if ($view === 'overview'): ?>
  <section class="hero"><p class="eyebrow">Guten Tag, <?= Security::h((string)$currentUser['display_name']) ?></p><h1>Unterricht vorbereiten und auf jedem Gerät fortsetzen</h1><p>Räume liegen auf dem Server und gehören Ihrem Konto. Ein am PC vorbereiteter Raum lässt sich am Surface in derselben Lehreransicht öffnen.</p></section>
  <div class="stats"><article><strong><?= count($rooms) ?></strong><span>aktive Räume</span></article><article><strong><?= $personalKey?'bereit':'–' ?></strong><span>persönlicher API-Zugang</span></article><article><strong><?= $orgKey?'bereit':'–' ?></strong><span>Schulzugang</span></article></div>
  <section><div class="section-head"><div><p class="eyebrow">Lernpfade</p><h2>Verfügbare Einheiten</h2></div></div><div class="module-grid"><?php foreach ($modules as $module): ?><article class="module-card"><p class="pill"><?= Security::h((string)$module['status']) ?></p><h3><?= Security::h((string)$module['label']) ?></h3><p><?= Security::h((string)$module['description']) ?></p><div class="actions"><a class="button primary" href="<?= Security::h((string)$module['teacher_url']) ?>">Lehreransicht öffnen</a><a class="button" href="<?= Security::h((string)$module['public_url']) ?>">Schüleransicht</a></div></article><?php endforeach; ?></div></section>
<?php elseif ($view === 'rooms'): ?>
  <section class="section-head"><div><p class="eyebrow">Geräteübergreifend</p><h1>Aktive Kursräume</h1><p>Verlängern, Freigaben steuern und beenden Sie Räume im jeweiligen Lernpfad. Die zentrale Liste verhindert, dass Räume nur auf einem Gerät auffindbar sind.</p></div></section>
  <div class="table-wrap"><table><thead><tr><th>Raum</th><th>Lernpfad</th><th>Bezeichnung</th><?php if($isAdmin):?><th>Lehrkraft</th><?php endif;?><th>Gültig bis</th><th>KI-Feedback</th><th></th></tr></thead><tbody><?php foreach($rooms as $room): ?><tr><td><strong><?= Security::h((string)$room['code']) ?></strong></td><td><?= Security::h((string)($room['module_label'] ?: $room['module_slug'])) ?></td><td><?= Security::h((string)$room['label']) ?></td><?php if($isAdmin):?><td><?= Security::h((string)$room['owner_name']) ?></td><?php endif;?><td><?= date('d.m.Y H:i',(int)$room['expires_at']) ?></td><td><?= $room['ai_feedback_enabled']?'freigegeben':'aus' ?></td><td><a class="button small" href="<?= Security::h($roomTeacherUrl($room)) ?>">Öffnen</a></td></tr><?php endforeach; ?><?php if(!$rooms):?><tr><td colspan="7">Noch kein aktiver Raum. Öffnen Sie einen Lernpfad und legen Sie dort den Raum an.</td></tr><?php endif;?></tbody></table></div>
<?php elseif ($view === 'keys'): ?>
  <section class="section-head"><div><p class="eyebrow">Sicherer Schlüssel-Tresor</p><h1>KI-Zugang verwalten</h1><p>Schlüssel werden serverseitig verschlüsselt und niemals an Schülerbrowser ausgegeben. Die Reihenfolge lautet: persönlicher Schlüssel, berechtigter Schulzugang, danach ein ausdrücklich vergebenes Förderkontingent.</p></div></section>
  <div class="two-col"><section class="panel"><h2>Persönlicher OpenAI-Schlüssel</h2><p class="status <?= $personalKey?'ok':'' ?>"><?= $personalKey?'Eingerichtet':'Noch nicht eingerichtet' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_personal_key"><label>Neuen Schlüssel hinterlegen<input type="password" name="api_key" autocomplete="off" placeholder="sk-…" required></label><button class="primary" type="submit"><?= $personalKey?'Schlüssel ersetzen':'Schlüssel speichern' ?></button></form><?php if($personalKey):?><form method="post" data-confirm="Persönlichen API-Schlüssel wirklich löschen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="delete_personal_key"><button class="danger text-button" type="submit">Persönlichen Schlüssel löschen</button></form><?php endif;?></section>
  <section class="panel"><h2>Schulischer OpenAI-Schlüssel</h2><p class="status <?= $orgKey?'ok':'' ?>"><?= $orgKey?'Für Ihre Organisation verfügbar':'Nicht eingerichtet' ?></p><?php if($isAdmin):?><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_org_key"><label>Schulschlüssel hinterlegen<input type="password" name="api_key" autocomplete="off" placeholder="sk-…" required></label><button class="primary" type="submit"><?= $orgKey?'Schlüssel ersetzen':'Schlüssel speichern' ?></button></form><?php if($orgKey):?><form method="post" data-confirm="Schulischen API-Schlüssel wirklich löschen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="delete_org_key"><button class="danger text-button" type="submit">Schulschlüssel löschen</button></form><?php endif;?><?php else:?><p>Nur die Administration Ihrer Organisation kann den gemeinsamen Schlüssel ändern.</p><?php endif;?></section></div>
  <section class="panel"><h2>Zeitlich begrenztes Förderkontingent</h2><?php if($activeGrant):?><p class="status ok">Aktiv bis <?= date('d.m.Y H:i',(int)$activeGrant['expires_at']) ?> Uhr</p><dl><dt>Bezeichnung</dt><dd><?= Security::h((string)$activeGrant['label']) ?></dd><dt>Rückmeldungen</dt><dd><?= (int)$activeGrant['used_requests'] ?> von <?= (int)$activeGrant['request_limit'] ?> verwendet</dd><dt>Token</dt><dd><?= number_format((int)$activeGrant['used_tokens'],0,',','.') ?> von <?= number_format((int)$activeGrant['token_limit'],0,',','.') ?> verwendet</dd></dl><p>Der Schlüssel bleibt serverseitig und ist für Sie nicht einsehbar. Die Administration kann die Freigabe jederzeit widerrufen.</p><?php else:?><p class="status">Kein aktives Förderkontingent</p><p>Bei Bedarf kann die Administration ein eng begrenztes Demo- oder Fortbildungskontingent freigeben. Dabei wird kein gemeinsamer API-Schlüssel weitergegeben.</p><?php endif;?></section>
  <aside class="info"><strong>Datenschutz und Kosten:</strong> Antworten werden nur für den einzelnen Feedbackaufruf übertragen und nicht in dieser Plattform gespeichert. Raum-, Stunden- und Monatskontingente begrenzen Missbrauch und Kosten. Vor einem schulischen Einsatz muss die verantwortliche Schule die Rechtsgrundlage, Information der Betroffenen und die erforderlichen Verträge zum verwendeten API-Konto klären. <a href="/datenschutz-lernplattform/">Datenflüsse im Detail</a></aside>
<?php elseif ($view === 'profile'): ?>
  <section class="section-head"><div><p class="eyebrow">Persönliches Konto</p><h1>Profil und Sicherheit</h1></div></section><div class="two-col"><section class="panel"><h2>Kontodaten</h2><dl><dt>Name</dt><dd><?= Security::h((string)$currentUser['display_name']) ?></dd><dt>Benutzername</dt><dd><?= Security::h((string)$currentUser['username']) ?></dd><dt>E-Mail</dt><dd><?= Security::h((string)$currentUser['email']) ?></dd><dt>Organisation</dt><dd><?= Security::h((string)($org['name'] ?? 'keine')) ?></dd><dt>Rolle</dt><dd><?= Security::h((string)$currentUser['role']) ?></dd></dl><details><summary>Anmeldenamen ändern</summary><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="change_username"><label>Neuer Anmeldename<input name="new_username" value="<?= Security::h((string)$currentUser['username']) ?>" autocomplete="username" minlength="3" maxlength="60" pattern="[a-z0-9][a-z0-9._-]{2,59}" required></label><label>Mit bisherigem Passwort bestätigen<input type="password" name="current_password" autocomplete="current-password" required></label><button type="submit">Anmeldenamen ändern</button></form></details></section><section class="panel"><h2>Passwort ändern</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="change_password"><label>Bisheriges Passwort<input type="password" name="current_password" autocomplete="current-password" required></label><label>Neues Passwort<input type="password" name="new_password" autocomplete="new-password" minlength="15" maxlength="256" required><small>Mindestens 15 Zeichen; ein Passwortsatz ist geeignet.</small></label><label>Neues Passwort wiederholen<input type="password" name="new_password_confirmation" autocomplete="new-password" minlength="15" maxlength="256" required></label><button class="primary" type="submit">Passwort ändern</button></form></section></div>
  <section class="panel"><h2>E-Mail-Informationen zur Pilotphase</h2><p>Notwendige Konto-, Sicherheits- und Supportnachrichten bleiben von dieser freiwilligen Einstellung unberührt.</p><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_platform_mail_preference"><label class="check optional-consent"><input type="checkbox" name="platform_updates_opt_in" value="1" <?= !empty($currentUser['platform_updates_opt_in'])?'checked':'' ?>> Ich möchte per E-Mail über wichtige Änderungen der kostenlosen Pilotphase informiert werden. Die Einwilligung kann hier jederzeit mit Wirkung für die Zukunft widerrufen werden.</label><button type="submit">E-Mail-Einstellung speichern</button></form></section>
  <section class="panel"><h2>Zwei-Faktor-Anmeldung</h2><?php if(!empty($currentUser['mfa_enabled_at'])):?><p class="status ok">Aktiv seit <?= date('d.m.Y',(int)$currentUser['mfa_enabled_at']) ?></p><p>Zusätzlich zum Passwort ist bei jeder neuen Anmeldung ein sechsstelliger Code aus einer Authenticator-App oder ein einmaliger Wiederherstellungscode erforderlich.</p><details><summary>Deaktivieren</summary><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="disable_mfa"><label>Aktuelles Passwort<input type="password" name="current_password" autocomplete="current-password" required></label><label>Authenticator- oder Wiederherstellungscode<input name="mfa_code" autocomplete="one-time-code" required></label><button class="danger" type="submit">Zwei-Faktor-Anmeldung deaktivieren</button></form></details><?php elseif(!empty($_SESSION['platform_pending_mfa_secret'])):$pendingSecret=(string)$_SESSION['platform_pending_mfa_secret'];?><p class="status">Einrichtung noch nicht bestätigt</p><ol><li>In einer Authenticator-App ein neues Konto anlegen.</li><li>Das Geheimnis manuell übertragen: <code class="secret-display"><?= Security::h($pendingSecret) ?></code></li><li>Alternativ die folgende TOTP-Adresse verwenden: <span class="copy-line"><input readonly value="<?= Security::h(Mfa::provisioningUri($currentUser,$pendingSecret)) ?>"><button type="button" data-copy-nearby>Kopieren</button></span></li><li>Den aktuell erzeugten sechsstelligen Code bestätigen.</li></ol><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="confirm_mfa"><label>Aktueller sechsstelliger Code<input name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button class="primary" type="submit">Einrichtung bestätigen</button></form><?php else:?><p class="status">Noch nicht aktiviert</p><p>Die zusätzliche Anmeldung mit einer TOTP-Authenticator-App schützt insbesondere Administrationskonten und hinterlegte API-Zugänge.</p><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="begin_mfa"><button class="primary" type="submit">Zwei-Faktor-Anmeldung einrichten</button></form><?php endif;?></section>
<?php elseif ($view === 'support'):
  $ticketId=(int)($_GET['ticket']??0);
  $tickets=SupportTickets::listForUser((int)$currentUser['id']);
  $ticket=$ticketId>0?SupportTickets::get($ticketId,$currentUser):null;
?>
  <section class="section-head"><div><p class="eyebrow">Hilfe und Rückmeldung</p><h1>Support</h1><p>Bitte keine Schülernamen, Schülerantworten oder andere sensible Unterrichtsdaten übermitteln.</p></div></section>
  <?php if($ticket):?><section class="panel"><p><a href="/lehrer/?view=support">← Zur Übersicht</a></p><h2><?= Security::h((string)$ticket['public_id']) ?> · <?= Security::h((string)$ticket['subject']) ?></h2><p class="status"><?= Security::h((string)$ticket['status']) ?></p><div class="ticket-thread"><?php foreach($ticket['messages'] as $message):?><article><p class="meta"><?= Security::h((string)($message['author_name'] ?: $message['author_role'])) ?> · <?= date('d.m.Y H:i',(int)$message['created_at']) ?> Uhr</p><p><?= nl2br(Security::h((string)$message['body'])) ?></p></article><?php endforeach;?></div><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="support_reply"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label>Antwort<textarea name="body" minlength="2" maxlength="5000" required></textarea></label><button class="primary" type="submit">Antwort senden</button></form></section>
  <?php else:?><div class="two-col"><section class="panel"><h2>Neue Supportanfrage</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="support_create"><label>Bereich<select name="category"><option value="login">Anmeldung und Konto</option><option value="room">Kursraum</option><option value="presentation">Lehrer-/Beamersteuerung</option><option value="ai">KI-Feedback</option><option value="content">Inhalt eines Lernpfads</option><option value="privacy">Datenschutz</option><option value="security">Sicherheit</option><option value="other">Sonstiges</option></select></label><label>Betreff<input name="subject" minlength="5" maxlength="140" required></label><label>Beschreibung<textarea name="body" minlength="20" maxlength="5000" required></textarea></label><button class="primary" type="submit">Supportanfrage senden</button></form></section><section class="panel"><h2>Meine Supportfälle</h2><?php foreach($tickets as $item):?><p><a href="/lehrer/?view=support&amp;ticket=<?= (int)$item['id'] ?>"><strong><?= Security::h((string)$item['public_id']) ?></strong><br><?= Security::h((string)$item['subject']) ?> · <?= Security::h((string)$item['status']) ?></a></p><?php endforeach;?><?php if(!$tickets):?><p class="empty">Noch keine Supportanfrage.</p><?php endif;?></section></div><?php endif;?>
<?php elseif ($view === 'requests' && $isAdmin):
  $requestStage = Security::clean($_GET['stage'] ?? 'pending', 20);
  if (!in_array($requestStage, ['pending','approved','registered','rejected','archived','all'], true)) $requestStage = 'pending';
  $requests = AccessRequests::list($requestStage);
  $requestCounts = AccessRequests::counts();
  $stageLabels = ['pending'=>'Offen','approved'=>'Genehmigt – Registrierung ausstehend','registered'=>'Registriert','rejected'=>'Abgelehnt','archived'=>'Archiviert','all'=>'Alle'];
?>
  <section class="section-head"><div><p class="eyebrow">Moderierter Zugang</p><h1>Zugriffsanfragen</h1><p>Jede Anfrage bleibt bis zur Registrierung nachvollziehbar. Bei Genehmigung wird automatisch eine persönliche Einladung versendet; bei einem Mailfehler können Sie sicher einen neuen Link erzeugen.</p></div></section>
  <nav class="stage-nav" aria-label="Anfragestatus">
    <?php foreach ($stageLabels as $stageKey => $stageLabel): ?>
      <a class="<?= $requestStage===$stageKey?'active':'' ?>" href="/lehrer/?view=requests&amp;stage=<?= Security::h($stageKey) ?>">
        <?= Security::h($stageLabel) ?><?php if ($stageKey !== 'all'): ?> <span><?= (int)($requestCounts[$stageKey] ?? 0) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="request-list">
    <?php foreach($requests as $request):
      $isPending = $request['status'] === 'pending';
      $isWaiting = $request['status'] === 'approved' && empty($request['registered_at']);
      $isRegistered = !empty($request['registered_at']);
      $mailSent = !empty($request['invitation_email_sent_at']);
      $mailAttempted = (int)($request['invitation_email_attempts'] ?? 0) > 0;
      $inviteExpired = !empty($request['invitation_expires_at']) && (int)$request['invitation_expires_at'] < time();
      $stateLabel = $isRegistered ? 'Registriert' : ($isWaiting ? 'Genehmigt – Registrierung ausstehend' : ($request['status']==='pending' ? 'Offen' : ($request['status']==='rejected' ? 'Abgelehnt' : 'Archiviert')));
      $stateKey = $isRegistered ? 'registered' : ($isWaiting ? 'approved' : (string)$request['status']);
    ?>
      <article class="request-card">
        <header><div><h2><?= Security::h((string)$request['name']) ?></h2><p><?= Security::h((string)$request['email']) ?> · <?= Security::h((string)$request['school']) ?></p></div><span class="pill state-<?= Security::h($stateKey) ?>"><?= Security::h($stateLabel) ?></span></header>
        <dl>
          <dt>Fächer</dt><dd><?= Security::h((string)$request['subjects']) ?></dd>
          <dt>Bundesland</dt><dd><?= Security::h((string)$request['bundesland']) ?></dd>
          <dt>Zugangsart</dt><dd><?= Security::h((string)$request['access_type']) ?></dd>
          <dt>Nachricht</dt><dd><?= trim((string)$request['reason']) !== '' ? nl2br(Security::h((string)$request['reason'])) : '–' ?></dd>
          <dt>Änderungs-E-Mails</dt><dd><?= !empty($request['platform_updates_opt_in']) ? 'freiwillig eingewilligt' : 'nicht eingewilligt' ?><?= !empty($request['platform_updates_opted_at']) ? ' · '.date('d.m.Y H:i',(int)$request['platform_updates_opted_at']).' Uhr' : '' ?></dd>
          <dt>Angefragt</dt><dd><?= date('d.m.Y H:i', (int)$request['created_at']) ?> Uhr</dd>
          <?php if (!empty($request['decided_at'])): ?><dt>Entschieden</dt><dd><?= date('d.m.Y H:i', (int)$request['decided_at']) ?> Uhr<?= !empty($request['decided_by_name']) ? ' · '.Security::h((string)$request['decided_by_name']) : '' ?></dd><?php endif; ?>
          <?php if ($isWaiting): ?>
            <dt>Einladung</dt><dd><?php if ($mailSent): ?>versendet am <?= date('d.m.Y H:i', (int)$request['invitation_email_sent_at']) ?> Uhr<?php elseif ($mailAttempted): ?><span class="mail-error">nicht versendet</span><?php else: ?>Versandstatus vor der Umstellung nicht protokolliert<?php endif; ?> · gültig bis <?= !empty($request['invitation_expires_at']) ? date('d.m.Y H:i', (int)$request['invitation_expires_at']).' Uhr' : '–' ?><?= $inviteExpired ? ' · abgelaufen' : '' ?></dd>
            <?php if (!$mailSent && !empty($request['invitation_email_last_error'])): ?><dt>Mailhinweis</dt><dd class="mail-error"><?= Security::h((string)$request['invitation_email_last_error']) ?></dd><?php endif; ?>
          <?php elseif ($isRegistered): ?>
            <dt>Konto</dt><dd><?= Security::h((string)($request['registered_user_name'] ?: $request['name'])) ?> · angelegt am <?= date('d.m.Y H:i', (int)$request['registered_at']) ?> Uhr</dd>
          <?php endif; ?>
          <?php if (!empty($request['admin_note'])): ?><dt>Interne Notiz</dt><dd><?= nl2br(Security::h((string)$request['admin_note'])) ?></dd><?php endif; ?>
        </dl>
        <?php if ($isPending): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="request_decision"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><label>Interne Notiz<textarea name="admin_note"><?= Security::h((string)$request['admin_note']) ?></textarea></label><div class="actions"><button class="primary" name="decision" value="approve" type="submit">Genehmigen und Einladung senden</button><button class="danger" name="decision" value="reject" type="submit">Ablehnen</button></div></form>
        <?php elseif ($isWaiting): ?>
          <form method="post" data-confirm="Der bisherige Einladungslink wird ungültig. Wirklich eine neue Einladung erzeugen und versenden?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="resend_request_invite"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="primary" type="submit">Neue Einladung senden</button></form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if(!$requests):?><p class="empty">In diesem Status gibt es keine Zugriffsanfrage.</p><?php endif;?>
  </div>
<?php elseif ($view === 'support-admin' && $isAdmin):
  $supportStage=Security::clean($_GET['status']??'active',20);
  $supportTickets=SupportTickets::listAll($supportStage);
  $supportCounts=SupportTickets::counts();
  $supportTicketId=(int)($_GET['ticket']??0);
  $supportTicket=$supportTicketId>0?SupportTickets::get($supportTicketId,$currentUser):null;
?>
  <section class="section-head"><div><p class="eyebrow">Administration</p><h1>Supportfälle</h1><p>Technische Hilfe, Rückfragen und Sicherheitsmeldungen bleiben mit Status und Verlauf nachvollziehbar.</p></div></section>
  <?php if($supportTicket):?><section class="panel"><p><a href="/lehrer/?view=support-admin">← Zur Übersicht</a></p><h2><?= Security::h((string)$supportTicket['public_id']) ?> · <?= Security::h((string)$supportTicket['subject']) ?></h2><dl><dt>Kontakt</dt><dd><?= Security::h((string)$supportTicket['email']) ?></dd><dt>Kategorie</dt><dd><?= Security::h((string)$supportTicket['category']) ?></dd><dt>Status</dt><dd><?= Security::h((string)$supportTicket['status']) ?></dd></dl><div class="ticket-thread"><?php foreach($supportTicket['messages'] as $message):?><article class="<?= !empty($message['is_internal'])?'internal':'' ?>"><p class="meta"><?= Security::h((string)($message['author_name'] ?: $message['author_role'])) ?> · <?= date('d.m.Y H:i',(int)$message['created_at']) ?> Uhr<?= !empty($message['is_internal'])?' · interne Notiz':'' ?></p><p><?= nl2br(Security::h((string)$message['body'])) ?></p></article><?php endforeach;?></div><div class="two-col"><form method="post" class="panel compact"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="support_reply"><input type="hidden" name="ticket_id" value="<?= (int)$supportTicket['id'] ?>"><label>Antwort oder interne Notiz<textarea name="body" minlength="2" maxlength="5000" required></textarea></label><label class="check"><input type="checkbox" name="internal" value="1"> Nur intern speichern</label><button class="primary" type="submit">Nachricht speichern</button></form><form method="post" class="panel compact"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="support_update"><input type="hidden" name="ticket_id" value="<?= (int)$supportTicket['id'] ?>"><label>Status<select name="status"><?php foreach(['open'=>'offen','in_progress'=>'in Bearbeitung','waiting_user'=>'Antwort der Lehrkraft ausstehend','resolved'=>'gelöst','closed'=>'geschlossen'] as $key=>$label):?><option value="<?= $key ?>" <?= $supportTicket['status']===$key?'selected':'' ?>><?= Security::h($label) ?></option><?php endforeach;?></select></label><label>Priorität<select name="priority"><?php foreach(['normal'=>'normal','high'=>'hoch','urgent'=>'dringend'] as $key=>$label):?><option value="<?= $key ?>" <?= $supportTicket['priority']===$key?'selected':'' ?>><?= Security::h($label) ?></option><?php endforeach;?></select></label><button type="submit">Status aktualisieren</button></form></div></section>
  <?php else:?><nav class="stage-nav" aria-label="Supportstatus"><?php foreach(['active'=>'Aktiv','open'=>'Offen','in_progress'=>'In Bearbeitung','waiting_user'=>'Wartet','resolved'=>'Gelöst','closed'=>'Geschlossen','all'=>'Alle'] as $key=>$label):?><a class="<?= $supportStage===$key?'active':'' ?>" href="/lehrer/?view=support-admin&amp;status=<?= $key ?>"><?= Security::h($label) ?><?php if(isset($supportCounts[$key])):?><span><?= (int)$supportCounts[$key] ?></span><?php endif;?></a><?php endforeach;?></nav><div class="request-list"><?php foreach($supportTickets as $item):?><article class="request-card"><header><div><h2><a href="/lehrer/?view=support-admin&amp;ticket=<?= (int)$item['id'] ?>"><?= Security::h((string)$item['public_id']) ?> · <?= Security::h((string)$item['subject']) ?></a></h2><p><?= Security::h((string)$item['email']) ?> · <?= Security::h((string)$item['category']) ?></p></div><span class="pill"><?= Security::h((string)$item['status']) ?></span></header><p>Aktualisiert: <?= date('d.m.Y H:i',(int)$item['updated_at']) ?> Uhr · Priorität: <?= Security::h((string)$item['priority']) ?></p></article><?php endforeach;?><?php if(!$supportTickets):?><p class="empty">In diesem Status gibt es keinen Supportfall.</p><?php endif;?></div><?php endif;?>
<?php elseif ($view === 'ai-admin' && $isAdmin):
  $grantUsers=Database::connection()->query('SELECT id,display_name,email,status FROM users ORDER BY display_name')->fetchAll();
  $allGrants=AiGrants::list();
  $sponsoredKey=AiGrants::hasSystemKey();
?>
  <section class="section-head"><div><p class="eyebrow">Kontrollierte Freigaben</p><h1>KI-Förderkontingente</h1><p>Ein eigener serverseitiger OpenAI-Projektschlüssel kann ausgewählten Konten zeitlich und mengenmäßig begrenzt zur Verfügung gestellt werden. Der Schlüssel selbst bleibt unsichtbar.</p></div></section>
  <div class="two-col"><section class="panel"><h2>Serverseitiger Förderzugang</h2><p class="status <?= $sponsoredKey?'ok':'' ?>"><?= $sponsoredKey?'Verschlüsselt eingerichtet':'Nicht eingerichtet' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_sponsored_key"><label>OpenAI-Projektschlüssel<input type="password" name="api_key" autocomplete="off" placeholder="sk-…" required></label><button class="primary" type="submit"><?= $sponsoredKey?'Schlüssel ersetzen':'Schlüssel speichern' ?></button></form><?php if($sponsoredKey):?><form method="post" data-confirm="Förderzugang wirklich löschen? Alle Freigaben verlieren sofort ihre Wirkung."><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="delete_sponsored_key"><button class="danger text-button" type="submit">Förderzugang löschen</button></form><?php endif;?></section><section class="panel"><h2>Kontingent freigeben</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="create_ai_grant"><label>Lehrerkonto<select name="user_id" required><?php foreach($grantUsers as $account):?><option value="<?= (int)$account['id'] ?>"><?= Security::h((string)$account['display_name']) ?> · <?= Security::h((string)$account['email']) ?></option><?php endforeach;?></select></label><label>Bezeichnung<input name="label" value="Demo-/Fortbildungskontingent" maxlength="100" required></label><div class="form-grid"><label>Beginn<input type="date" name="starts_at" value="<?= date('Y-m-d') ?>" required></label><label>Ende<input type="date" name="expires_at" value="<?= date('Y-m-d',time()+14*86400) ?>" required></label></div><div class="form-grid"><label>Maximale Rückmeldungen<input type="number" name="request_limit" min="1" max="5000" value="30" required></label><label>Maximale Token<input type="number" name="token_limit" min="1000" max="50000000" value="60000" required></label></div><label>Lernpfade einschränken <small>Optional: Slugs mit Komma trennen; leer bedeutet alle registrierten Lernpfade.</small><input name="allowed_modules" placeholder="religion-12-12.1.1"></label><button class="primary" type="submit">Kontingent freigeben</button></form></section></div>
  <section class="panel"><h2>Vergebene Kontingente</h2><div class="table-wrap"><table><thead><tr><th>Lehrkraft</th><th>Kontingent</th><th>Laufzeit</th><th>Verbrauch</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($allGrants as $grant):?><tr><td><?= Security::h((string)$grant['display_name']) ?><br><small><?= Security::h((string)$grant['email']) ?></small></td><td><?= Security::h((string)$grant['label']) ?></td><td><?= date('d.m.Y',(int)$grant['starts_at']) ?> – <?= date('d.m.Y',(int)$grant['expires_at']) ?></td><td><?= (int)$grant['used_requests'] ?>/<?= (int)$grant['request_limit'] ?> Rückmeldungen<br><?= number_format((int)$grant['used_tokens'],0,',','.') ?>/<?= number_format((int)$grant['token_limit'],0,',','.') ?> Token</td><td><?= Security::h((string)$grant['status']) ?></td><td><?php if($grant['status']==='active'):?><form method="post" data-confirm="Kontingent sofort widerrufen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="revoke_ai_grant"><input type="hidden" name="grant_id" value="<?= (int)$grant['id'] ?>"><button class="danger text-button" type="submit">Widerrufen</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$allGrants):?><tr><td colspan="6">Noch kein Förderkontingent vergeben.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif ($view === 'admin' && $isAdmin):
  $users=Database::connection()->query('SELECT u.*,o.name AS organisation_name FROM users u LEFT JOIN organisation_memberships m ON m.user_id=u.id AND m.is_default=1 LEFT JOIN organisations o ON o.id=m.organisation_id ORDER BY u.created_at')->fetchAll();
  $smtpConfigured=Mailer::configured();
  $mailSummary=Mailer::summary(); ?>
  <section class="section-head"><div><p class="eyebrow">Administration</p><h1>Konten, Organisation und Mail</h1></div></section>
  <div class="two-col"><section class="panel"><h2>Lehrkraft einladen</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="manual_invite"><label>Name<input name="display_name" required></label><label>E-Mail<input type="email" name="email" required></label><label>Rolle<select name="role"><option value="teacher">Lehrkraft</option><option value="admin">Administration</option></select></label><label>Organisationszugriff<select name="organisation_scope"><option value="independent">Unabhängiges Konto – kein Schulschlüssel</option><option value="school">Bestätigte Lehrkraft der eigenen Schule</option></select></label><button class="primary" type="submit">Einladung erzeugen</button></form></section><section class="panel"><h2>Organisation</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_org"><label>Name<input name="organisation_name" value="<?= Security::h((string)($org['name']??'')) ?>" required></label><label>KI-Rückmeldungen pro Monat<input type="number" name="monthly_request_limit" min="0" max="100000" value="<?= (int)($org['monthly_request_limit']??2000) ?>"><small>0 deaktiviert den schulischen KI-Zugang vollständig.</small></label><button class="primary" type="submit">Organisation speichern</button></form><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_model"><label>OpenAI-Modell<input name="openai_model" value="<?= Security::h((string)(Settings::get('openai.model',(string)Config::get('openai_model')))) ?>" required></label><button type="submit">Modell speichern</button></form></section></div>
  <section class="panel"><div class="section-head"><div><h2>Lehrerkonten</h2><p>Passwort-Reset-Links laufen nach 45 Minuten ab und können nur einmal verwendet werden. Nur bestätigte Konten der eigenen Schule dürfen der Schulorganisation zugeordnet werden.</p></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Organisation</th><th>Rolle</th><th>Status</th><th>2FA</th><th>Pilot-E-Mails</th><th>Letzte Anmeldung</th><th>Aktionen</th></tr></thead><tbody><?php foreach($users as $account):?><tr><td><?= Security::h((string)$account['display_name']) ?></td><td><?= Security::h((string)$account['email']) ?></td><td><?= Security::h((string)($account['organisation_name']??'unabhängig')) ?></td><td><?= Security::h((string)$account['role']) ?></td><td><?= Security::h((string)$account['status']) ?></td><td><?= !empty($account['mfa_enabled_at'])?'aktiv':'–' ?></td><td><?= !empty($account['platform_updates_opt_in'])?'ja':'–' ?></td><td><?= $account['last_login_at']?date('d.m.Y H:i',(int)$account['last_login_at']):'–' ?></td><td><div class="actions"><?php if($account['status']==='active'):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="send_password_reset"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><button type="submit">Reset senden</button></form><?php endif;?><?php if((int)$account['id']!==(int)$currentUser['id']):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="set_user_org_scope"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="organisation_scope" value="<?= empty($account['organisation_name'])?'school':'independent' ?>"><button type="submit"><?= empty($account['organisation_name'])?'Schule zuordnen':'Von Schule trennen' ?></button></form><?php if(!empty($account['mfa_enabled_at'])):?><form method="post" class="inline-form" data-confirm="Zwei-Faktor-Anmeldung für dieses Konto zurücksetzen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="reset_user_mfa"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><button type="submit">2FA zurücksetzen</button></form><?php endif;?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="set_user_status"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><button name="status" value="<?= $account['status']==='active'?'suspended':'active' ?>" type="submit"><?= $account['status']==='active'?'Sperren':'Aktivieren' ?></button></form><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></section>
  <details class="panel"><summary><strong>Mailversand <?= $smtpConfigured?'· eingerichtet':'· noch nicht vollständig eingerichtet' ?></strong></summary>
    <p>Nach erfolgreicher Einrichtung gehen Anfragen-Hinweise an die Benachrichtigungsadresse und Einladungen automatisch an die anfragende Lehrkraft. Die Absenderdaten gelten sowohl für den vorhandenen Server-Maildienst als auch für eine später hinterlegte SMTP-Verbindung.</p>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_mail_identity">
      <label>Absenderadresse<input type="email" name="from_email" value="<?= Security::h((string)($mailSummary['from_email'] ?? 'lern-html-lehrerzugang@markuspiller.de')) ?>" required></label>
      <label>Absendername<input name="from_name" value="<?= Security::h((string)($mailSummary['from_name'] ?? 'Religionsunterricht · Lehrerplattform')) ?>" required></label>
      <label>Benachrichtigungen an<input type="email" name="notify_to" value="<?= Security::h((string)($mailSummary['notify_to'] ?? 'markus.piller@jmf-gymnasium.de')) ?>" required></label>
      <button class="primary" type="submit">Absender und Ziel speichern</button>
    </form>
    <details class="mail-advanced"><summary>Optional: externen SMTP-Zugang verwenden</summary><p>SMTP-Zugangsdaten werden verschlüsselt gespeichert. Ein leeres Passwortfeld behält bei späteren Änderungen das vorhandene Passwort.</p>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_smtp">
      <label>SMTP-Host<input name="host" value="<?= Security::h((string)($mailSummary['host'] ?? '')) ?>" required></label>
      <label>Port<input type="number" name="port" value="<?= (int)($mailSummary['port'] ?? 587) ?>" min="1" max="65535" required></label>
      <label>Verschlüsselung<select name="encryption"><option value="tls" <?= ($mailSummary['encryption']??'tls')==='tls'?'selected':'' ?>>STARTTLS</option><option value="ssl" <?= ($mailSummary['encryption']??'')==='ssl'?'selected':'' ?>>TLS/SSL direkt</option><option value="none" <?= ($mailSummary['encryption']??'')==='none'?'selected':'' ?>>keine</option></select></label>
      <label>Benutzername<input name="username" value="<?= Security::h((string)($mailSummary['username'] ?? '')) ?>" autocomplete="off"></label>
      <label>Passwort<input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($mailSummary['has_password'])?'vorhandenes Passwort beibehalten':'SMTP-Passwort' ?>"></label>
      <label>Absenderadresse<input type="email" name="from_email" value="<?= Security::h((string)($mailSummary['from_email'] ?? 'lern-html-lehrerzugang@markuspiller.de')) ?>" required></label>
      <label>Absendername<input name="from_name" value="<?= Security::h((string)($mailSummary['from_name'] ?? 'Religionsunterricht · Lehrerplattform')) ?>" required></label>
      <label>Benachrichtigungen an<input type="email" name="notify_to" value="<?= Security::h((string)($mailSummary['notify_to'] ?? 'markus.piller@jmf-gymnasium.de')) ?>" required></label>
      <button class="primary" type="submit">SMTP verschlüsselt speichern</button>
    </form>
    </details>
    <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="test_mail"><label>Testnachricht an<input type="email" name="test_to" value="<?= Security::h((string)($mailSummary['notify_to'] ?? 'markus.piller@jmf-gymnasium.de')) ?>" required></label><button type="submit">Testnachricht bewusst senden</button></form>
  </details>
<?php else: ?><p class="alert error">Diese Seite ist nicht verfügbar.</p><?php endif; ?>
</main></div><footer>Lehrerplattform v2.1.0 · datensparsame Plattform für interaktive Lernpfade</footer></body></html>
