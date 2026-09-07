<?php
// Included by the module after its JSON request and teacher authentication are parsed.
// Uses that module's existing create_room/read_room/public_state functions, not a second engine.
if (($action ?? '') === 'start_teaching') {
    require_teacher();
    $actor=platform_teacher();
    if(!$actor)respond(401,['ok'=>false,'message'=>'Bitte im gemeinsamen Zugang anmelden.']);
    if(!\ReligionPlatform\Security::verifyCsrf((string)($payload['csrf'] ?? '')))respond(403,['ok'=>false,'message'=>'Die Anmeldung ist abgelaufen. Bitte neu laden.']);
    if(!\ReligionPlatform\Security::rateLimit('teaching-start',(string)$actor['id'],60,20))respond(429,['ok'=>false,'message'=>'Bitte kurz warten, bevor du erneut Unterricht startest.']);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try {
        $started=\ReligionPlatform\TeachingStart::start($actor,PLATFORM_MODULE_SLUG,$payload,
            static fn(string $label,int $expires): array => create_room($label,$expires),
            static fn(string $code): array => read_room($code));
        $response=public_state($started['state'],true);
        $response['teaching']=$started['teaching'];
        respond(200,$response);
    } catch(Throwable $error) {
        respond(403,['ok'=>false,'message'=>$error instanceof \PDOException?'Der Unterricht konnte nicht geöffnet werden. Bitte erneut versuchen.':$error->getMessage()]);
    }
}
