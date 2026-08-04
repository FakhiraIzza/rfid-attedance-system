<?php
function mail_cfg(): array {
  $path = __DIR__ . '/../config/mail.php';
  if (file_exists($path)) {
    return require $path;
  }
  return [
    'host' => '',
    'port' => 587,
    'encryption' => 'tls',
    'username' => '',
    'password' => '',
    'from_email' => '',
    'from_name' => 'Presensi RFID',
  ];
}

function smtp_send(string $to, string $subject, string $html): bool {
  $cfg = mail_cfg();
  $host = $cfg['host'] ?? '';
  $port = (int)($cfg['port'] ?? 587);
  $enc = $cfg['encryption'] ?? 'tls';
  $user = $cfg['username'] ?? '';
  $pass = $cfg['password'] ?? '';
  $fromEmail = $cfg['from_email'] ?? $user;
  $fromName = $cfg['from_name'] ?? 'Presensi RFID';

  if ($host === '' || $user === '' || $pass === '' || $fromEmail === '') {
    return false;
  }

  $errno = 0;
  $errstr = '';
  $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
  if (!$fp) return false;

  stream_set_timeout($fp, 10);

  $read = function() use ($fp) {
    $data = '';
    while (!feof($fp)) {
      $line = fgets($fp, 515);
      if ($line === false) break;
      $data .= $line;
      if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
  };
  $send = function($cmd) use ($fp, $read) {
    if ($cmd !== null) {
      fwrite($fp, $cmd . "\r\n");
    }
    return $read();
  };

  $read();
  $send('EHLO presensi');
  if ($enc === 'tls') {
    $send('STARTTLS');
    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $send('EHLO presensi');
  }

  $send('AUTH LOGIN');
  $send(base64_encode($user));
  $send(base64_encode($pass));

  $send("MAIL FROM:<{$fromEmail}>");
  $send("RCPT TO:<{$to}>");
  $send('DATA');

  $headers = [];
  $headers[] = "From: {$fromName} <{$fromEmail}>";
  $headers[] = "To: <{$to}>";
  $headers[] = "Subject: {$subject}";
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/html; charset=UTF-8";
  $headersStr = implode("\r\n", $headers);

  $message = $headersStr . "\r\n\r\n" . $html;
  $message = str_replace("\n.", "\n..", $message);
  fwrite($fp, $message . "\r\n.\r\n");
  $read();
  $send('QUIT');
  fclose($fp);
  return true;
}
