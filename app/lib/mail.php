<?php
declare(strict_types=1);

/*
 * Sending email through the team's own mailbox (e.g. hello@previouslynoted.com) over SMTP.
 * Mail sent through the domain's real mail server, with SPF and DKIM set up for the domain,
 * is far less likely to land in spam than PHP's mail().
 *
 * Settings live in data/connections.php (smtp_*); the password is never sent to the browser.
 */

class MailError extends RuntimeException {}

function mail_settings(): array
{
    $c = conn_reload();
    return [
        'host' => (string)($c['smtp_host'] ?? ''),
        'port' => (int)($c['smtp_port'] ?? 465),
        'security' => (string)($c['smtp_security'] ?? 'ssl'),   // ssl (465) | starttls (587)
        'user' => (string)($c['smtp_user'] ?? ''),
        'pass' => (string)($c['smtp_pass'] ?? ''),
        'from' => (string)($c['smtp_from'] ?? ''),
        'from_name' => (string)($c['smtp_from_name'] ?? ''),
        // Test-only overrides, never offered in the app.
        'allow_plain' => !empty($c['smtp_allow_plain']),
        'ca_file' => (string)($c['smtp_ca_file'] ?? ''),
    ];
}

function mail_configured(): bool
{
    $m = mail_settings();
    return $m['host'] !== '' && $m['from'] !== '' && $m['user'] !== '' && $m['pass'] !== '';
}

/** Send one message. $to: [email, name]. Throws MailError with a readable reason. */
function mail_send(array $to, string $subject, string $text, ?string $html = null, ?array $replyTo = null, ?array $m = null): void
{
    $m ??= mail_settings();
    if ($m['host'] === '' || $m['from'] === '') {
        throw new MailError('Email isn’t set up yet. An admin can set it up in Settings → Email.');
    }
    $fromDomain = substr(strrchr($m['from'], '@') ?: '@localhost', 1);
    $boundary = 'pn-' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mail_address($m['from'], $m['from_name'] ?: (app_config()['app_name'] ?? 'Previously Noted')),
        'To: ' . mail_address($to[0], $to[1] ?? ''),
        'Subject: ' . mail_header_text($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $fromDomain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
    ];
    if ($replyTo && valid_email($replyTo[0])) {
        $headers[] = 'Reply-To: ' . mail_address($replyTo[0], $replyTo[1] ?? '');
    }
    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = quoted_printable_encode(mail_crlf($text));
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(mail_crlf($text))
            . "\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(mail_crlf($html))
            . "\r\n--$boundary--";
    }
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    smtp_deliver($m, $m['from'], $to[0], $data);
}

function mail_crlf(string $s): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $s);
}

function mail_header_text(string $s): string
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function mail_address(string $email, string $name): string
{
    if (!valid_email($email)) {
        throw new MailError("“$email” isn’t a valid email address.");
    }
    $name = trim(str_replace(["\r", "\n", '"'], ' ', $name));
    if ($name === '') {
        return "<$email>";
    }
    return (preg_match('/[^\x20-\x7e]/', $name) ? mail_header_text($name) : '"' . $name . '"') . " <$email>";
}

/* ---------- SMTP ---------- */

function smtp_deliver(array $m, string $from, string $to, string $data): void
{
    $host = $m['host'];
    $port = $m['port'] ?: ($m['security'] === 'starttls' ? 587 : 465);
    $tls = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true];
    if ($m['ca_file'] !== '') {
        $tls['cafile'] = $m['ca_file'];
    }
    $ctx = stream_context_create(['ssl' => $tls]);
    $scheme = $m['security'] === 'ssl' ? 'ssl' : 'tcp';
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        if ($errstr === '') {
            $last = error_get_last()['message'] ?? '';
            $errstr = str_contains($last, 'certificate') ? 'certificate check failed' : (preg_replace('/^.*?\): /', '', $last) ?: 'no answer');
        }
        $hint = str_contains(strtolower($errstr), 'certificate') || str_contains(strtolower($errstr), 'ssl')
            ? ' The mail server’s security certificate didn’t match. On Namecheap hosting, try the server name from cPanel → Email Accounts → Connect Devices (e.g. server123.web-hosting.com) instead of mail.yourdomain.'
            : ' Check the server name and port.';
        throw new MailError("Couldn’t reach the mail server $host:$port ($errstr).$hint");
    }
    stream_set_timeout($fp, 30);
    try {
        smtp_expect($fp, [220]);
        $ehloName = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'localhost';
        $ext = smtp_cmd($fp, "EHLO $ehloName", [250]);
        if ($m['security'] === 'starttls') {
            if (!preg_match('/^250[ -]STARTTLS/mi', $ext)) {
                throw new MailError('The mail server doesn’t offer STARTTLS on this port. Try port 465 with SSL.');
            }
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailError('Couldn’t start a secure connection with the mail server (its certificate may not match the server name).');
            }
            $ext = smtp_cmd($fp, "EHLO $ehloName", [250]);
        } elseif ($m['security'] !== 'ssl' && !$m['allow_plain']) {
            throw new MailError('Choose SSL (port 465) or STARTTLS (port 587).');
        }
        if ($m['user'] !== '') {
            if (preg_match('/^250[ -]AUTH[ =](.*)$/mi', $ext, $am) && stripos($am[1], 'PLAIN') !== false) {
                smtp_cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $m['user'] . "\0" . $m['pass']), [235], true);
            } else {
                smtp_cmd($fp, 'AUTH LOGIN', [334]);
                smtp_cmd($fp, base64_encode($m['user']), [334], true);
                smtp_cmd($fp, base64_encode($m['pass']), [235], true);
            }
        }
        smtp_cmd($fp, "MAIL FROM:<$from>", [250]);
        smtp_cmd($fp, "RCPT TO:<$to>", [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        // Dot-stuff lines that start with a full stop.
        $data = preg_replace('/^\./m', '..', $data);
        smtp_cmd($fp, $data . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}

function smtp_cmd($fp, string $line, array $ok, bool $secret = false): string
{
    if (@fwrite($fp, $line . "\r\n") === false) {
        throw new MailError('The mail server closed the connection.');
    }
    try {
        return smtp_expect($fp, $ok);
    } catch (MailError $e) {
        if ($secret) {
            throw new MailError('The mail server didn’t accept the username and password. Use the full email address as the username, and the mailbox’s password.');
        }
        throw $e;
    }
}

function smtp_expect($fp, array $ok): string
{
    $all = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $all .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    if ($all === '') {
        $meta = stream_get_meta_data($fp);
        throw new MailError($meta['timed_out'] ? 'The mail server stopped answering.' : 'The mail server closed the connection.');
    }
    $code = (int)substr($all, 0, 3);
    if (!in_array($code, $ok, true)) {
        $msg = trim(preg_replace('/^\d{3}[ -]/m', '', $all));
        throw new MailError('The mail server said: ' . mb_substr($msg, 0, 300));
    }
    return $all;
}

/* ---------- The messages the app sends ---------- */

function mail_html(string $heading, string $intro, string $button, string $link, string $after): string
{
    $app = h(app_config()['app_name'] ?? 'Previously Noted');
    $icon = h(app_base_url() . 'assets/icons/png/icon-192.png');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . h($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef0f2;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f2;"><tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;">'
        . '<tr><td style="padding:32px 32px 8px;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:12px;"><img src="' . $icon . '" width="40" height="40" alt="" style="display:block;border:0;border-radius:9px;"></td>'
        . '<td style="font:bold 17px Arial,Helvetica,sans-serif;color:#1d2228;">' . $app . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:16px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#1d2228;">'
        . '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;">' . h($heading) . '</h1>'
        . '<p style="margin:0 0 24px;font-size:16px;line-height:1.5;color:#3d444c;">' . $intro . '</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:6px;background:#1c2025;">'
        . '<a href="' . h($link) . '" style="display:inline-block;padding:13px 22px;font:bold 16px Arial,Helvetica,sans-serif;color:#ebe6dc;text-decoration:none;border-radius:6px;">' . h($button) . '</a></td></tr></table>'
        . '<p style="margin:24px 0 0;font-size:14px;line-height:1.5;color:#59616b;">' . $after . '</p>'
        . '<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#59616b;word-break:break-all;">If the button doesn’t work, copy this link into your browser:<br><a href="' . h($link) . '" style="color:#1d2228;">' . h($link) . '</a></p>'
        . '</td></tr><tr><td style="padding:24px 32px 28px;font:12px/1.5 Arial,Helvetica,sans-serif;color:#6b737c;">'
        . 'Sent by ' . $app . ', the review notes tool your team uses. If you weren’t expecting this, you can ignore it.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Email an invite or a password-reset link. $by is the admin sending it. */
/**
 * Email an invite or a password reset link.
 * $person: email, name, and for invites org_name, role_label and has_password. $by: who sent it (null when the
 * person asked for a reset themselves on the sign-in page).
 */
function mail_account_link(array $person, string $kind, string $link, string $expires, ?array $by): void
{
    $app = app_config()['app_name'] ?? 'Previously Noted';
    if ($kind === 'invite') {
        $org = (string)($person['org_name'] ?? $app);
        $role = (string)($person['role_label'] ?? '');
        $as = $role !== '' ? ' as <b>' . h($role) . '</b>' : '';
        $asText = $role !== '' ? " as $role" : '';
        $subject = "{$by['name']} invited you to $org on $app";
        $heading = "Join $org";
        if (!empty($person['has_password'])) {
            $intro = h($by['name']) . ' invited you to <b>' . h($org) . '</b> on ' . h($app) . $as . '. You already have an account, so just sign in to join.';
            $button = "Join $org";
            $after = 'This link works once and expires in ' . h($expires) . '. Sign in with ' . h($person['email']) . '.';
            $text = "Hi {$person['name']},\n\n{$by['name']} invited you to $org on $app$asText. You already have an account, so just sign in to join:\n$link\n\nThe link works once and expires in $expires. Sign in with {$person['email']}.\n\nIf you weren’t expecting this, you can ignore it.\n";
        } else {
            $intro = h($by['name']) . ' invited you to <b>' . h($org) . '</b> on ' . h($app) . $as . ', to follow the review notes on the film. Choose a password to get started.';
            $button = 'Choose your password';
            $after = 'This link works once and expires in ' . h($expires) . '. You’ll sign in with ' . h($person['email']) . '.';
            $text = "Hi {$person['name']},\n\n{$by['name']} invited you to $org on $app$asText, to follow the review notes on the film.\n\nChoose your password here (the link works once and expires in $expires):\n$link\n\nYou’ll sign in with {$person['email']}.\n\nIf you weren’t expecting this, you can ignore it.\n";
        }
    } else {
        $who = $by ? h($by['name']) . ' sent you' : 'Someone (hopefully you) asked for';
        $whoText = $by ? "{$by['name']} sent you" : 'Someone (hopefully you) asked for';
        $subject = "Reset your $app password";
        $heading = 'Choose a new password';
        $intro = $who . ' a link to choose a new password for ' . h($app) . '. Your current password keeps working until you use it.';
        $button = 'Choose a new password';
        $after = 'This link works once and expires in ' . h($expires) . '. Using it signs you out of ' . h($app) . ' on your other devices.';
        $text = "Hi {$person['name']},\n\n$whoText a link to choose a new password for $app. Your current password keeps working until you use it.\n\nChoose a new password here (the link works once and expires in $expires):\n$link\n\nIf you weren’t expecting this, you can ignore it.\n";
    }
    mail_send([$person['email'], $person['name']], $subject, $text, mail_html($heading, $intro, $button, $link, $after), $by ? [$by['email'], $by['name']] : null);
}
