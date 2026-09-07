<?php
declare(strict_types=1);

require_once '/websites/_protected/teacher-platform-v1/app/bootstrap.php';

use ReligionPlatform\Security;
use ReligionPlatform\SupportTickets;

Security::headers("default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
$error = '';
$ticket = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket = SupportTickets::create($_POST);
    } catch (RuntimeException $caught) {
        $error = $caught->getMessage();
    } catch (Throwable $caught) {
        error_log('teacher-platform support request: ' . $caught->getMessage());
        $error = 'Die Supportanfrage konnte gerade nicht gespeichert werden. Bitte versuchen Sie es später erneut.';
    }
}
$token = Security::formToken('support-request');
$allowedCategories = ['login','room','presentation','ai','content','privacy','security','other'];
$requestedCategory = Security::clean($_POST['category'] ?? $_GET['category'] ?? '', 30);
$formCategory = in_array($requestedCategory, $allowedCategories, true) ? $requestedCategory : 'login';
$formEmail = Security::clean($_POST['email'] ?? '', 190);
$formSubject = Security::clean($_POST['subject'] ?? $_GET['subject'] ?? '', 140);
$formBody = Security::cleanMultiline($_POST['body'] ?? '', 5000);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Support · Religions-Lernpfade</title><link rel="stylesheet" href="/lehrer/assets/platform.css?v=1.2.1"></head><body>
<main class="auth-shell"><section class="auth-card"><p class="eyebrow">Religions-Lernpfade</p><h1>Support anfragen</h1>
<?php if($ticket):?><div class="alert success"><strong>Ihre Anfrage wurde gespeichert.</strong><p>Die Vorgangsnummer lautet <?= Security::h((string)$ticket['public_id']) ?>. Eine Eingangsbestätigung wurde an Ihre E-Mail-Adresse gesendet.</p></div><p><a class="button primary" href="/lehrer/">Zur Lehrer-Anmeldung</a></p>
<?php else:?><p>Nutzen Sie dieses Formular bei Problemen mit Anmeldung, Kursräumen oder den Lernpfaden. Übermitteln Sie keine Schülernamen, Schülerantworten oder sonstigen sensiblen Unterrichtsdaten.</p><?php if($error):?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif;?>
<form method="post"><input type="hidden" name="form_token" value="<?= Security::h($token) ?>"><label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><label>E-Mail<input type="email" name="email" autocomplete="email" value="<?= Security::h($formEmail) ?>" required></label><label>Bereich<select name="category"><?php foreach(['login'=>'Anmeldung und Konto','room'=>'Kursraum','presentation'=>'Lehrer-/Beamersteuerung','ai'=>'KI-Feedback','content'=>'Inhalt eines Lernpfads','privacy'=>'Datenschutz','security'=>'Sicherheitsmeldung','other'=>'Sonstiges'] as $value=>$label):?><option value="<?= Security::h($value) ?>" <?= $formCategory===$value?'selected':'' ?>><?= Security::h($label) ?></option><?php endforeach;?></select></label><label>Betreff<input name="subject" value="<?= Security::h($formSubject) ?>" minlength="5" maxlength="140" required></label><label>Beschreibung<textarea name="body" minlength="20" maxlength="5000" required><?= Security::h($formBody) ?></textarea></label><label class="check"><input type="checkbox" name="consent" value="1" required> Ich habe die <a href="/datenschutz-lernplattform/" target="_blank" rel="noopener noreferrer">Datenschutzinformationen</a> gelesen. Meine Angaben dürfen zur Bearbeitung der Supportanfrage gespeichert und zur Kontaktaufnahme verwendet werden.</label><button class="primary" type="submit">Supportanfrage senden</button></form><p class="auth-links"><a href="/lehrer/">Zur Anmeldung</a><a href="/zugriff-anfragen/">Zugriff anfragen</a><a href="/datenschutz-lernplattform/">Datenschutz</a></p><?php endif;?>
</section></main></body></html>
