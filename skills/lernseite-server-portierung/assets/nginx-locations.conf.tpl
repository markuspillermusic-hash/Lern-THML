# Nur in den passenden server{}-Block einsetzen. Vorher Konfiguration sichern.
# Platzhalter mit scripts/render_server_module.py ersetzen.

# Temporär für den PHP-Test aktivieren und nach erfolgreichem Test entfernen.
location = {{BASE_PATH}}/php-runtime-probe.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {{DOCUMENT_ROOT}}{{BASE_PATH}}/php-runtime-probe.php;
    fastcgi_pass {{FASTCGI_PASS}};
}

location = {{BASE_PATH}}/lehrer/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {{DOCUMENT_ROOT}}{{BASE_PATH}}/lehrer/index.php;
    fastcgi_pass {{FASTCGI_PASS}};
}

location = {{BASE_PATH}}/api/live.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {{DOCUMENT_ROOT}}{{BASE_PATH}}/api/live.php;
    fastcgi_pass {{FASTCGI_PASS}};
}

# PHP in diesem Modul niemals als Quelltext oder über andere Dateinamen ausliefern.
location ~ ^{{BASE_PATH_REGEX}}/(?:lehrer|api)/.*\.php$ {
    return 404;
}

location ~ ^{{BASE_PATH_REGEX}}/(?:lehrer|api)/.*\.(?:json|log|sqlite)$ {
    deny all;
}

