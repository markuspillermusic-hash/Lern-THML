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
            'from_name' => Security::clean($input['from_name'] ?? 'LernHTML', 100),
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

    public static function recipient(): string
    {
        $mode = Settings::get('mail.mode', (string)Config::get('mail_mode', 'sendmail'));
        if ($mode === 'smtp') {
            $config = self::smtpConfig();
            return (string)($config['notify_to'] ?? '');
        }
        return (string)Config::get('contact_recipient', '');
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
        $from = (string)Config::get('mail_from', 'noreply@example.invalid');
        $message = self::message($to, $subject, $text, $from, (string)Config::get('mail_from_name', 'LernHTML'));
        $process = proc_open([$path, '-t', '-i'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
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
        $helo = (string)Config::get('mail_helo', 'example.invalid');
        self::command($socket, 'EHLO ' . $helo, [250]);
        if ($encryption === 'tls') {
            self::command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new \RuntimeException('SMTP-TLS konnte nicht aktiviert werden.');
            }
            self::command($socket, 'EHLO ' . $helo, [250]);
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
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (string)Config::get('message_id_domain', 'example.invalid') . '>',
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
