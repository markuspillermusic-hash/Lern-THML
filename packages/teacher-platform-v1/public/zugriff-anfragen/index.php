<?php
declare(strict_types=1);

$bootstrap = getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/srv/teacher-platform-v1/app/bootstrap.php';
require_once $bootstrap;

use ReligionPlatform\AccessRequests;
use ReligionPlatform\Security;

Security::headers("default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        AccessRequests::create($_POST);
        $done = true;
    } catch (RuntimeException $caught) {
        $error = $caught->getMessage();
    } catch (Throwable $caught) {
        error_log('teacher-platform access request: ' . $caught->getMessage());
        $error = 'Die Anfrage konnte gerade nicht gespeichert werden. Bitte versuchen Sie es später erneut.';
    }
}
$token = Security::formToken('access-request');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Zugriff anfragen · LernHTML</title>
  <link rel="stylesheet" href="/lehrer/assets/platform.css?v=1.0.0">
</head>
<body>
<main class="auth-shell">
  <section class="auth-card">
    <p class="eyebrow">LernHTML-Plattform</p>
    <h1>Zugriff für Lehrkräfte anfragen</h1>
    <?php if ($done): ?>
      <div class="alert success"><strong>Vielen Dank.</strong> Die Anfrage wurde gespeichert und wird persönlich geprüft. Sie erhalten eine E-Mail, wenn ein Zugang eingerichtet werden kann.</div>
      <p><a class="button" href="/bereiche/">Zu den Lernbereichen</a></p>
    <?php else: ?>
      <p>Lehrkräfte der eigenen Schule können nach Freigabe den schulischen KI-Zugang nutzen. Externe Lehrkräfte erhalten einen persönlichen Zugang und können – falls gewünscht – ihren eigenen OpenAI-Schlüssel verschlüsselt hinterlegen.</p>
      <p>Die Seiten bleiben auch ohne KI-Feedback nutzbar. Ein Zugang ist persönlich und darf nicht weitergegeben werden.</p>
      <?php if ($error): ?><p class="alert error" role="alert"><?= Security::h($error) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="form_token" value="<?= Security::h($token) ?>">
        <label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
        <div class="form-grid">
          <label>Name<input name="name" autocomplete="name" required></label>
          <label>E-Mail<input type="email" name="email" autocomplete="email" required></label>
          <label>Schule/Einrichtung<input name="school" autocomplete="organization" required></label>
          <label>Fach/Fächer<input name="subjects" placeholder="z. B. Musik, Religion" required></label>
          <label>Bundesland<input name="bundesland" value="Bayern" required></label>
          <label>Art des Zugangs
            <select name="access_type" required>
              <option value="">Bitte wählen …</option>
              <option value="own-school">Lehrkraft derselben Schule</option>
              <option value="external">Externe Lehrkraft</option>
              <option value="institution">Fortbildung/Institution</option>
            </select>
          </label>
        </div>
        <label>Warum möchten Sie die Lernpfade nutzen?<textarea name="reason" minlength="20" maxlength="2000" required></textarea></label>
        <label class="check"><input type="checkbox" name="consent" value="1" required> Ich habe die <a href="/datenschutz-lernplattform/" target="_blank" rel="noopener noreferrer">Datenschutzinformationen zur Lehrerplattform</a> gelesen. Meine Angaben dürfen zur Bearbeitung der von mir gewünschten Zugriffsanfrage gespeichert und zur Kontaktaufnahme verwendet werden.</label>
        <button class="primary" type="submit">Zugriff anfragen</button>
      </form>
      <p class="auth-links"><a href="/lehrer/">Bereits registriert? Anmelden</a><a href="/datenschutz-lernplattform/">Datenschutz</a><a href="/bereiche/">Abbrechen</a></p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
