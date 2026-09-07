<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Mailer
{
    public static function configured(): bool
    {
        $mode = Settings::get('mail.mode', (string)Config::get('mail_mode', 'sendmail'));
        if ($mode === 'smtp') return Vault::has('system', 0, 'smtp_config');
        if ($mode === 'sendmail') return is_executable((string)Config::get('sendmail_path', '/usr/sbin/sendmail'));
        return false;
    }

    public static function saveSmtp(array $input): void
    {
        $config = [
            'host' => Security::clean($input['host'] ?? '', 190),
            'port' => max(1, min(65535, (int)($input['port'] ?? 587))),
            'encryption' => in_array(($input['encryption'] ?? ''), ['tls', 'ssl', 'none'], true) ? $input['encryption'] : 'tls',
            'username' => Security::clean($input['username'] ?? '', 190),
            'password' => (string)($input['password'] ?? ''),
            'from_email' => strtolower(Security::clean($input['from_email'] ?? '', 190)),
            'from_name' => Security::clean($input['from_name'] ?? 'Religionsunterricht', 100),
            'notify_to' => strtolower(Security::clean($input['notify_to'] ?? '', 190)),
        ];
        if ($config['host'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL) || !filter_var($config['notify_to'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('SMTP-Host, Absender und Benachrichtigungsadresse müssen vollständig sein.');
        }
        if ($config['password'] === '' && Vault::has('system', 0, 'smtp_config')) {
            $old = json_decode((string)Vault::get('system', 0, 'smtp_config'), true);
            if (is_array($old)) $config['password'] = (string)($old['password'] ?? '');
        }
        Vault::put('system', 0, 'smtp_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Settings::set('mail.mode', 'smtp');
    }

    public static function saveIdentity(array $input): void
    {
        $from = strtolower(Security::clean($input['from_email'] ?? '', 190));
        $name = Security::clean($input['from_name'] ?? '', 100);
        $notify = strtolower(Security::clean($input['notify_to'] ?? '', 190));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || $name === '' || !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Absender, Absendername und Benachrichtigungsadresse müssen vollständig und gültig sein.');
        }
        Settings::set('mail.from_email', $from);
        Settings::set('mail.from_name', $name);
        Settings::set('mail.notify_to', $notify);
        if (Vault::has('system', 0, 'smtp_config')) {
            $smtp = self::smtpConfig();
            $smtp['from_email'] = $from;
            $smtp['from_name'] = $name;
            $smtp['notify_to'] = $notify;
            Vault::put('system', 0, 'smtp_config', json_encode($smtp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public static function recipient(): string
    {
        $mode = Settings::get('mail.mode', (string)Config::get('mail_mode', 'sendmail'));
        if ($mode === 'smtp' && Vault::has('system', 0, 'smtp_config')) {
            try {
                $config = self::smtpConfig();
                return (string)($config['notify_to'] ?? '');
            } catch (\Throwable) {
                return (string)Config::get('contact_recipient', '');
            }
        }
        return (string)Settings::get('mail.notify_to', (string)Config::get('contact_recipient', ''));
    }

    /** @return array<string,mixed> */
    public static function summary(): array
    {
        $mode = Settings::get('mail.mode', (string)Config::get('mail_mode', 'sendmail'));
        if ($mode === 'smtp' && Vault::has('system', 0, 'smtp_config')) {
            $config = self::smtpConfig();
            unset($config['password']);
            $config['mode'] = 'smtp';
            $config['has_password'] = true;
            return $config;
        }
        return [
            'mode' => $mode,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'from_email' => (string)Settings::get('mail.from_email', (string)Config::get('mail_from', 'lern-html-lehrerzugang@markuspiller.de')),
            'from_name' => (string)Settings::get('mail.from_name', 'Religionsunterricht · Lehrerplattform'),
            'notify_to' => (string)Settings::get('mail.notify_to', (string)Config::get('contact_recipient', 'markus.piller@jmf-gymnasium.de')),
            'has_password' => false,
        ];
    }

    public static function send(string $to, string $subject, string $text): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Die Empfängeradresse ist nicht konfiguriert.');
        $subject = Security::clean($subject, 180);
        $text = Security::cleanMultiline($text, 12000);
        $mode = Settings::get('mail.mode', (string)Config::get('mail_mode', 'sendmail'));
        if ($mode === 'smtp') {
            self::smtpSend($to, $subject, $text);
            return;
        }
        if ($mode !== 'sendmail') throw new \RuntimeException('Der Mailversand ist deaktiviert.');
        self::sendmailSend($to, $subject, $text);
    }

    private static function sendmailSend(string $to, string $subject, string $text): void
    {
        $path = (string)Config::get('sendmail_path', '/usr/sbin/sendmail');
        if (!is_executable($path)) throw new \RuntimeException('Sendmail ist nicht verfügbar.');
        $from = (string)Settings::get('mail.from_email', (string)Config::get('mail_from', 'lern-html-lehrerzugang@markuspiller.de'));
        $fromName = (string)Settings::get('mail.from_name', 'Religionsunterricht · Lehrerplattform');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Die Absenderadresse ist ungültig.');
        $message = self::message($to, $subject, $text, $from, $fromName);
        $process = proc_open([$path, '-t', '-i', '-f', $from], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Der Mailprozess konnte nicht gestartet werden.');
        fwrite($pipes[0], $message);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new \RuntimeException('Die Nachricht konnte nicht versendet werden: ' . Security::clean($stderr, 200));
    }

    private static function smtpConfig(): array
    {
        $decoded = json_decode((string)Vault::get('system', 0, 'smtp_config'), true);
        if (!is_array($decoded)) throw new \RuntimeException('SMTP ist noch nicht eingerichtet.');
        return $decoded;
    }

    private static function smtpSend(string $to, string $subject, string $text): void
    {
        $config = self::smtpConfig();
        $host = (string)$config['host'];
        $port = (int)$config['port'];
        $encryption = (string)$config['encryption'];
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) throw new \RuntimeException('SMTP-Verbindung fehlgeschlagen.');
        stream_set_timeout($socket, 15);
        self::expect($socket, [220]);
        self::command($socket, 'EHLO markuspiller.de', [250]);
        if ($encryption === 'tls') {
            self::command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new \RuntimeException('SMTP-TLS konnte nicht aktiviert werden.');
            }
            self::command($socket, 'EHLO markuspiller.de', [250]);
        }
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        if ($username !== '') {
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode($username), [334]);
            self::command($socket, base64_encode($password), [235]);
        }
        $from = (string)$config['from_email'];
        self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        self::command($socket, 'DATA', [354]);
        $message = self::message($to, $subject, $text, $from, (string)$config['from_name']);
        $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
        fwrite($socket, $message . "\r\n.\r\n");
        self::expect($socket, [250]);
        self::command($socket, 'QUIT', [221]);
        fclose($socket);
    }

    private static function command($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return self::expect($socket, $expected);
    }

    private static function expect($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) throw new \RuntimeException('SMTP-Server meldet Fehler ' . $code . '.');
        return $response;
    }

    private static function message(string $to, string $subject, string $text, string $from, string $fromName): string
    {
        $headers = [
            'From: ' . self::encoded($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encoded($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@markuspiller.de>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $text);
    }

    private static function encoded(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
