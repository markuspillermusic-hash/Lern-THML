<?php
declare(strict_types=1);

$bootstrap = getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/srv/teacher-platform-v1/app/bootstrap.php';
require_once $bootstrap;

use ReligionPlatform\AccessRequests;
use ReligionPlatform\Audit;
use ReligionPlatform\Auth;
use ReligionPlatform\Config;
use ReligionPlatform\Database;
use ReligionPlatform\Invitations;
use ReligionPlatform\Mailer;
use ReligionPlatform\Modules;
use ReligionPlatform\Rooms;
use ReligionPlatform\Security;
use ReligionPlatform\Settings;
use ReligionPlatform\Vault;

Security::headers("default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
Security::startSession();

$error = '';
$success = (string)($_SESSION['platform_flash_success'] ?? '');
$oneTime = (string)($_SESSION['platform_flash_once'] ?? '');
unset($_SESSION['platform_flash_success'], $_SESSION['platform_flash_once']);

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

if (isset($_GET['logout'])) {
    Auth::logout();
    local_redirect('/lehrer/');
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
            Auth::login((string)($_POST['identity'] ?? ''), (string)($_POST['password'] ?? ''));
            $next = (string)($_POST['next'] ?? '/lehrer/');
            local_redirect($next);
        }

        $actor = Auth::requireUser();
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
            if (strlen($new) < 12 || !preg_match('/[A-ZÄÖÜ]/u', $new) || !preg_match('/[a-zäöüß]/u', $new) || !preg_match('/\d/', $new)) throw new RuntimeException('Das neue Passwort braucht mindestens 12 Zeichen sowie Groß-, Kleinbuchstaben und eine Zahl.');
            $version = bin2hex(random_bytes(16));
            $statement = Database::connection()->prepare('UPDATE users SET password_hash=?,auth_version=?,updated_at=? WHERE id=?');
            $statement->execute([password_hash($new, PASSWORD_ARGON2ID), $version, time(), (int)$actor['id']]);
            $_SESSION['platform_auth_version'] = $version;
            Audit::record((int)$actor['id'], 'auth.password_changed', 'user', (string)$actor['id']);
            flash_redirect('Das Passwort wurde geändert.', '/lehrer/?view=profile');
        }

        $adminActions = ['save_org_key','delete_org_key','save_smtp','test_mail','request_decision','manual_invite','set_user_status','save_org','save_model'];
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
        if ($action === 'test_mail') {
            $to = Security::clean($_POST['test_to'] ?? '', 190);
            Mailer::send($to, 'Test · LernHTML-Lehrerplattform', "Der Mailversand der Lehrerplattform funktioniert.\n\nZeitpunkt: " . date('d.m.Y H:i'));
            flash_redirect('Die Testnachricht wurde versendet.', '/lehrer/?view=admin');
        }
        if ($action === 'request_decision') {
            $inviteUrl = AccessRequests::decide((int)($_POST['request_id'] ?? 0), (string)($_POST['decision'] ?? ''), $actor, (string)($_POST['admin_note'] ?? ''));
            if (is_string($inviteUrl)) $_SESSION['platform_flash_once'] = $inviteUrl;
            flash_redirect($inviteUrl ? 'Die Anfrage wurde angenommen und eine Einladung erzeugt.' : 'Die Anfrage wurde aktualisiert.', '/lehrer/?view=requests');
        }
        if ($action === 'manual_invite') {
            $url = Invitations::create((string)$_POST['email'], (string)$_POST['display_name'], (string)($_POST['role'] ?? 'teacher'), $orgId, (int)$actor['id']);
            $_SESSION['platform_flash_once'] = $url;
            flash_redirect('Die Einladung wurde erzeugt. Falls der Mailversand nicht eingerichtet ist, kopieren Sie den unten einmalig angezeigten Link.', '/lehrer/?view=admin');
        }
        if ($action === 'set_user_status') {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $status = in_array(($_POST['status'] ?? ''), ['active','suspended'], true) ? $_POST['status'] : '';
            if ($targetId === (int)$actor['id'] || $status === '') throw new RuntimeException('Dieses Konto kann so nicht geändert werden.');
            $statement = Database::connection()->prepare('UPDATE users SET status=?,auth_version=?,updated_at=? WHERE id=?');
            $statement->execute([$status, bin2hex(random_bytes(16)), time(), $targetId]);
            Audit::record((int)$actor['id'], 'user.' . $status, 'user', (string)$targetId);
            flash_redirect('Der Kontostatus wurde geändert.', '/lehrer/?view=admin');
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
$currentUser = Auth::currentUser();
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
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title><?= Security::h($title) ?></title><link rel="stylesheet" href="/lehrer/assets/platform.css?v=1.0.0"><script src="/lehrer/assets/platform.js?v=1.0.0" defer></script></head><body>
<?php }

if ($userCount === 0) {
    page_head('Ersteinrichtung · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Einmalige Ersteinrichtung</p><h1>Persönliches Administratorkonto anlegen</h1><p>Das bisherige gemeinsame Lehrerpasswort autorisiert nur diesen einmaligen Übergang. Danach melden Sie sich mit Benutzername und neuem persönlichen Passwort an.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="setup">
      <label>Bisheriges Lehrerpasswort<input type="password" name="legacy_password" autocomplete="current-password" required></label>
      <div class="form-grid"><label>Anzeigename<input name="display_name" autocomplete="name" required></label><label>Benutzername<input name="username" autocomplete="username" pattern="[a-zA-Z0-9._-]{3,60}" required></label></div>
      <label>E-Mail<input type="email" name="email" autocomplete="email" required></label><label>Schule/Organisation<input name="school" autocomplete="organization" required></label>
      <label>Neues persönliches Passwort<input type="password" name="password" autocomplete="new-password" minlength="12" required><small>Mindestens 12 Zeichen, Groß- und Kleinbuchstaben sowie eine Zahl.</small></label>
      <button class="primary" type="submit">Plattform sicher einrichten</button>
    </form><p class="auth-links"><a href="/datenschutz-lernplattform/">Datenschutzinformationen</a><a href="/bereiche/">Zu den Lernbereichen</a></p></section></main></body></html><?php exit;
}

if ($inviteToken !== '' && !$currentUser) {
    page_head('Einladung annehmen · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">Persönlicher Zugang</p><h1>Einladung annehmen</h1><p>Wählen Sie Ihren Benutzernamen und ein persönliches Passwort. Ein API-Schlüssel ist optional und kann später verschlüsselt hinterlegt werden.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="invite_accept"><input type="hidden" name="invite" value="<?= Security::h($inviteToken) ?>">
      <label>Anzeigename<input name="display_name" autocomplete="name" required></label><label>Benutzername<input name="username" autocomplete="username" pattern="[a-zA-Z0-9._-]{3,60}" required></label><label>Passwort<input type="password" name="password" autocomplete="new-password" minlength="12" required></label><button class="primary" type="submit">Lehrerprofil anlegen</button>
    </form><p class="auth-links"><a href="/datenschutz-lernplattform/">Datenschutzinformationen</a><a href="/bereiche/">Zu den Lernbereichen</a></p></section></main></body></html><?php exit;
}

if (!$currentUser) {
    page_head('Anmelden · Lehrerplattform'); ?>
    <main class="auth-shell"><section class="auth-card"><p class="eyebrow">LernHTML</p><h1>Lehrerbereich</h1><p>Verwalten Sie Lernpfade, vorbereitete Kursräume und Ihren persönlichen KI-Zugang geräteübergreifend.</p>
    <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="login"><input type="hidden" name="next" value="<?= Security::h($next) ?>"><label>Benutzername oder E-Mail<input name="identity" autocomplete="username" required autofocus></label><label>Passwort<input type="password" name="password" autocomplete="current-password" required></label><button class="primary" type="submit">Anmelden</button></form>
    <div class="auth-links"><a href="/zugriff-anfragen/">Zugriff anfragen</a><a href="/datenschutz-lernplattform/">Datenschutz</a><a href="/bereiche/">Zu den Lernbereichen</a></div></section></main></body></html><?php exit;
}

$org = Auth::defaultOrganisation((int)$currentUser['id']);
$orgId = (int)($org['id'] ?? 0);
$isAdmin = Auth::isAdmin($currentUser);
$modules = Modules::all($isAdmin);
$rooms = Rooms::listForUser($currentUser);
$personalKey = Vault::has('user', (int)$currentUser['id'], 'openai_api_key');
$orgKey = $orgId > 0 && Vault::has('organisation', $orgId, 'openai_api_key');
page_head('Lehrerbereich · LernHTML');
?>
<header class="app-header"><div><a class="brand" href="/lehrer/">LernHTML</a><span>Lehrerplattform</span></div><div class="account"><span><?= Security::h((string)$currentUser['display_name']) ?></span><a href="/lehrer/?logout=1">Abmelden</a></div></header>
<div class="app-layout"><nav class="side-nav" aria-label="Lehrerbereich"><a class="<?= $view==='overview'?'active':'' ?>" href="/lehrer/">Überblick</a><a class="<?= $view==='rooms'?'active':'' ?>" href="/lehrer/?view=rooms">Kursräume</a><a class="<?= $view==='keys'?'active':'' ?>" href="/lehrer/?view=keys">API-Schlüssel</a><a class="<?= $view==='profile'?'active':'' ?>" href="/lehrer/?view=profile">Profil</a><?php if ($isAdmin): ?><hr><a class="<?= $view==='requests'?'active':'' ?>" href="/lehrer/?view=requests">Zugriffsanfragen</a><a class="<?= $view==='admin'?'active':'' ?>" href="/lehrer/?view=admin">Administration</a><?php endif; ?><hr><a href="/datenschutz-lernplattform/">Datenschutz</a></nav>
<main class="content">
<?php if ($success): ?><p class="alert success" role="status"><?= Security::h($success) ?></p><?php endif; ?>
<?php if ($oneTime): ?><div class="alert notice"><strong>Einladungslink – nur jetzt sichtbar:</strong><div class="copy-line"><input readonly value="<?= Security::h($oneTime) ?>"><button type="button" data-copy-nearby>Kopieren</button></div></div><?php endif; ?>
<?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>

<?php if ($view === 'overview'): ?>
  <section class="hero"><p class="eyebrow">Guten Tag, <?= Security::h((string)$currentUser['display_name']) ?></p><h1>Unterricht vorbereiten und auf jedem Gerät fortsetzen</h1><p>Räume liegen auf dem Server und gehören Ihrem Konto. Ein am PC vorbereiteter Raum lässt sich am Surface in derselben Lehreransicht öffnen.</p></section>
  <div class="stats"><article><strong><?= count($rooms) ?></strong><span>aktive Räume</span></article><article><strong><?= $personalKey?'bereit':'–' ?></strong><span>persönlicher API-Zugang</span></article><article><strong><?= $orgKey?'bereit':'–' ?></strong><span>Schulzugang</span></article></div>
  <section><div class="section-head"><div><p class="eyebrow">Lernpfade</p><h2>Verfügbare Einheiten</h2></div></div><div class="module-grid"><?php foreach ($modules as $module): ?><article class="module-card"><p class="pill"><?= Security::h((string)$module['status']) ?></p><h3><?= Security::h((string)$module['label']) ?></h3><p><?= Security::h((string)$module['description']) ?></p><div class="actions"><a class="button primary" href="<?= Security::h((string)$module['teacher_url']) ?>">Lehreransicht öffnen</a><a class="button" href="<?= Security::h((string)$module['public_url']) ?>">Schüleransicht</a></div></article><?php endforeach; ?></div></section>
<?php elseif ($view === 'rooms'): ?>
  <section class="section-head"><div><p class="eyebrow">Geräteübergreifend</p><h1>Aktive Kursräume</h1><p>Verlängern, Freigaben steuern und beenden Sie Räume im jeweiligen Lernpfad. Die zentrale Liste verhindert, dass Räume nur auf einem Gerät auffindbar sind.</p></div></section>
  <div class="table-wrap"><table><thead><tr><th>Raum</th><th>Lernpfad</th><th>Bezeichnung</th><?php if($isAdmin):?><th>Lehrkraft</th><?php endif;?><th>Gültig bis</th><th>KI-Feedback</th><th></th></tr></thead><tbody><?php foreach($rooms as $room): $teacherUrl=(string)($room['module_teacher_url']??''); ?><tr><td><strong><?= Security::h((string)$room['code']) ?></strong></td><td><?= Security::h((string)($room['module_label'] ?: $room['module_slug'])) ?></td><td><?= Security::h((string)$room['label']) ?></td><?php if($isAdmin):?><td><?= Security::h((string)$room['owner_name']) ?></td><?php endif;?><td><?= date('d.m.Y H:i',(int)$room['expires_at']) ?></td><td><?= $room['ai_feedback_enabled']?'freigegeben':'aus' ?></td><td><?php if(str_starts_with($teacherUrl,'/')&&!str_starts_with($teacherUrl,'//')):?><a class="button small" href="<?= Security::h($teacherUrl.(str_contains($teacherUrl,'?')?'&':'?').'room='.rawurlencode((string)$room['code'])) ?>">Öffnen</a><?php else:?>–<?php endif;?></td></tr><?php endforeach; ?><?php if(!$rooms):?><tr><td colspan="7">Noch kein aktiver Raum. Öffnen Sie einen Lernpfad und legen Sie dort den Raum an.</td></tr><?php endif;?></tbody></table></div>
<?php elseif ($view === 'keys'): ?>
  <section class="section-head"><div><p class="eyebrow">Sicherer Schlüssel-Tresor</p><h1>KI-Zugang verwalten</h1><p>Schlüssel werden serverseitig verschlüsselt und niemals an Schülerbrowser ausgegeben. Für einen Raum gilt zuerst Ihr persönlicher Schlüssel, sonst der freigegebene Schulschlüssel.</p></div></section>
  <div class="two-col"><section class="panel"><h2>Persönlicher OpenAI-Schlüssel</h2><p class="status <?= $personalKey?'ok':'' ?>"><?= $personalKey?'Eingerichtet':'Noch nicht eingerichtet' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_personal_key"><label>Neuen Schlüssel hinterlegen<input type="password" name="api_key" autocomplete="off" placeholder="sk-…" required></label><button class="primary" type="submit"><?= $personalKey?'Schlüssel ersetzen':'Schlüssel speichern' ?></button></form><?php if($personalKey):?><form method="post" data-confirm="Persönlichen API-Schlüssel wirklich löschen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="delete_personal_key"><button class="danger text-button" type="submit">Persönlichen Schlüssel löschen</button></form><?php endif;?></section>
  <section class="panel"><h2>Schulischer OpenAI-Schlüssel</h2><p class="status <?= $orgKey?'ok':'' ?>"><?= $orgKey?'Für Ihre Organisation verfügbar':'Nicht eingerichtet' ?></p><?php if($isAdmin):?><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_org_key"><label>Schulschlüssel hinterlegen<input type="password" name="api_key" autocomplete="off" placeholder="sk-…" required></label><button class="primary" type="submit"><?= $orgKey?'Schlüssel ersetzen':'Schlüssel speichern' ?></button></form><?php if($orgKey):?><form method="post" data-confirm="Schulischen API-Schlüssel wirklich löschen?"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="delete_org_key"><button class="danger text-button" type="submit">Schulschlüssel löschen</button></form><?php endif;?><?php else:?><p>Nur die Administration Ihrer Organisation kann den gemeinsamen Schlüssel ändern.</p><?php endif;?></section></div>
  <aside class="info"><strong>Datenschutz und Kosten:</strong> Antworten werden nur für den einzelnen Feedbackaufruf übertragen und nicht in dieser Plattform gespeichert. Raum-, Stunden- und Monatskontingente begrenzen Missbrauch und Kosten. Vor einem schulischen Einsatz muss die verantwortliche Schule die Rechtsgrundlage, Information der Betroffenen und die erforderlichen Verträge zum verwendeten API-Konto klären. <a href="/datenschutz-lernplattform/">Datenflüsse im Detail</a></aside>
<?php elseif ($view === 'profile'): ?>
  <section class="section-head"><div><p class="eyebrow">Persönliches Konto</p><h1>Profil und Sicherheit</h1></div></section><div class="two-col"><section class="panel"><h2>Kontodaten</h2><dl><dt>Name</dt><dd><?= Security::h((string)$currentUser['display_name']) ?></dd><dt>Benutzername</dt><dd><?= Security::h((string)$currentUser['username']) ?></dd><dt>E-Mail</dt><dd><?= Security::h((string)$currentUser['email']) ?></dd><dt>Organisation</dt><dd><?= Security::h((string)($org['name'] ?? 'keine')) ?></dd><dt>Rolle</dt><dd><?= Security::h((string)$currentUser['role']) ?></dd></dl></section><section class="panel"><h2>Passwort ändern</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="change_password"><label>Bisheriges Passwort<input type="password" name="current_password" autocomplete="current-password" required></label><label>Neues Passwort<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label><button class="primary" type="submit">Passwort ändern</button></form></section></div>
<?php elseif ($view === 'requests' && $isAdmin): $requests=AccessRequests::list('pending'); ?>
  <section class="section-head"><div><p class="eyebrow">Moderierter Zugang</p><h1>Zugriffsanfragen</h1><p>Eine Annahme erzeugt eine persönliche Einladung. Bei fehlender Mailkonfiguration wird der Einladungslink einmalig zum Kopieren angezeigt.</p></div></section>
  <div class="request-list"><?php foreach($requests as $request):?><article class="request-card"><header><div><h2><?= Security::h((string)$request['name']) ?></h2><p><?= Security::h((string)$request['email']) ?> · <?= Security::h((string)$request['school']) ?></p></div><span class="pill"><?= Security::h((string)$request['access_type']) ?></span></header><dl><dt>Fächer</dt><dd><?= Security::h((string)$request['subjects']) ?></dd><dt>Bundesland</dt><dd><?= Security::h((string)$request['bundesland']) ?></dd><dt>Grund</dt><dd><?= nl2br(Security::h((string)$request['reason'])) ?></dd></dl><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="request_decision"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><label>Interne Notiz<textarea name="admin_note"></textarea></label><div class="actions"><button class="primary" name="decision" value="approve" type="submit">Annehmen und einladen</button><button class="danger" name="decision" value="reject" type="submit">Ablehnen</button></div></form></article><?php endforeach;?><?php if(!$requests):?><p class="empty">Keine offene Zugriffsanfrage.</p><?php endif;?></div>
<?php elseif ($view === 'admin' && $isAdmin):
  $users=Database::connection()->query('SELECT u.*,o.name AS organisation_name FROM users u LEFT JOIN organisation_memberships m ON m.user_id=u.id AND m.is_default=1 LEFT JOIN organisations o ON o.id=m.organisation_id ORDER BY u.created_at')->fetchAll();
  $smtpConfigured=Mailer::configured(); ?>
  <section class="section-head"><div><p class="eyebrow">Administration</p><h1>Konten, Organisation und Mail</h1></div></section>
  <div class="two-col"><section class="panel"><h2>Lehrkraft einladen</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="manual_invite"><label>Name<input name="display_name" required></label><label>E-Mail<input type="email" name="email" required></label><label>Rolle<select name="role"><option value="teacher">Lehrkraft</option><option value="admin">Administration</option></select></label><button class="primary" type="submit">Einladung erzeugen</button></form></section><section class="panel"><h2>Organisation</h2><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_org"><label>Name<input name="organisation_name" value="<?= Security::h((string)($org['name']??'')) ?>" required></label><label>KI-Rückmeldungen pro Monat<input type="number" name="monthly_request_limit" min="0" max="100000" value="<?= (int)($org['monthly_request_limit']??2000) ?>"></label><button class="primary" type="submit">Organisation speichern</button></form><form method="post"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_model"><label>OpenAI-Modell<input name="openai_model" value="<?= Security::h((string)(Settings::get('openai.model',(string)Config::get('openai_model')))) ?>" required></label><button type="submit">Modell speichern</button></form></section></div>
  <section class="panel"><div class="section-head"><div><h2>Lehrerkonten</h2></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead><tbody><?php foreach($users as $account):?><tr><td><?= Security::h((string)$account['display_name']) ?></td><td><?= Security::h((string)$account['email']) ?></td><td><?= Security::h((string)$account['role']) ?></td><td><?= Security::h((string)$account['status']) ?></td><td><?= $account['last_login_at']?date('d.m.Y H:i',(int)$account['last_login_at']):'–' ?></td><td><?php if((int)$account['id']!==(int)$currentUser['id']):?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="set_user_status"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><button name="status" value="<?= $account['status']==='active'?'suspended':'active' ?>" type="submit"><?= $account['status']==='active'?'Sperren':'Aktivieren' ?></button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
  <details class="panel"><summary><strong>Mailversand <?= $smtpConfigured?'· eingerichtet':'· prüfen' ?></strong></summary><p>SMTP-Zugangsdaten werden verschlüsselt gespeichert. Ein leeres Passwortfeld behält bei späteren Änderungen das vorhandene Passwort.</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="save_smtp"><label>SMTP-Host<input name="host" required></label><label>Port<input type="number" name="port" value="587" min="1" max="65535" required></label><label>Verschlüsselung<select name="encryption"><option value="tls">STARTTLS</option><option value="ssl">TLS/SSL direkt</option><option value="none">keine</option></select></label><label>Benutzername<input name="username" autocomplete="off"></label><label>Passwort<input type="password" name="password" autocomplete="new-password"></label><label>Absenderadresse<input type="email" name="from_email" required></label><label>Absendername<input name="from_name" value="LernHTML" required></label><label>Benachrichtigungen an<input type="email" name="notify_to" required></label><button class="primary" type="submit">SMTP speichern</button></form><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= Security::h($csrf) ?>"><input type="hidden" name="form_action" value="test_mail"><label>Test an<input type="email" name="test_to" required></label><button type="submit">Testnachricht senden</button></form></details>
<?php else: ?><p class="alert error">Diese Seite ist nicht verfügbar.</p><?php endif; ?>
</main></div><footer>Lehrerplattform v1 · datensparsame Architektur für LernHTMLs</footer></body></html>
