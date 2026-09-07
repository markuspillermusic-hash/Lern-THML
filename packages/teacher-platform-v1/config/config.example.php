<?php
declare(strict_types=1);

return [
    'app_name' => 'Religionsunterricht · Lehrerbereich',
    'base_url' => 'https://markuspiller.de',
    'data_dir' => '/var/lib/teacher-platform',
    'database' => '/var/lib/teacher-platform/platform.sqlite',
    'backup_dir' => '/var/backups/teacher-platform',
    'backup_retention_days' => 30,
    'master_key_file' => '/etc/teacher-platform/master.key',
    // Nur für die einmalige, lokal bestätigte Ersteinrichtung. Die Datei liegt außerhalb des Webroots.
    'legacy_auth_file' => '/websites/_protected/teacher-platform-v1/legacy/teacher-auth-source.php',
    'session_name' => 'religion_teacher_platform',
    'session_idle_seconds' => 28800,
    'session_absolute_seconds' => 43200,
    'default_room_days' => 42,
    'maximum_room_days' => 365,
    'openai_model' => 'gpt-5-mini',
    'openai_timeout_seconds' => 45,
    'feedback_min_seconds' => 12,
    'feedback_user_hour_limit' => 12,
    'feedback_room_day_limit' => 80,
    'feedback_org_month_limit' => 2000,
    'request_form_hour_limit' => 4,
    'login_window_seconds' => 900,
    'login_max_attempts' => 7,
    'mail_mode' => 'sendmail',
    'sendmail_path' => '/usr/sbin/sendmail',
    'mail_from' => 'lern-html-lehrerzugang@markuspiller.de',
    'contact_recipient' => 'markus.piller@jmf-gymnasium.de',
    'security_email' => 'markus.piller@jmf-gymnasium.de',
];
