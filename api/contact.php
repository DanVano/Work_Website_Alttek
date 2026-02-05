<?php
// api/contact.php
// Simple contact form handler (no external services required).
// Sends:
//  1) Notification email to support@alttek.ca
//  2) Auto-reply to the sender ("Thanks, we got your message")

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// ---- Config ----
$TO_EMAIL = 'support@alttek.ca';

// Recommended: a mailbox on your domain. Many hosts reject mail with an "off-domain" From.
$FROM_EMAIL = 'no-reply@alttek.ca';
$FROM_NAME  = 'Alttek Website';

// Rate limit: max 5 messages per 10 minutes per IP
$RATE_LIMIT_WINDOW_SECONDS = 600;
$RATE_LIMIT_MAX_IN_WINDOW  = 5;

$DATA_DIR = dirname(__DIR__) . '/data';
$RATE_FILE = $DATA_DIR . '/rate_limit.json';

// Ensure data dir exists
if (!is_dir($DATA_DIR)) {
  @mkdir($DATA_DIR, 0755, true);
}

// ---- Helpers ----
function clean_str(string $s, int $maxLen = 2000): string {
  $s = trim($s);
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  if (mb_strlen($s) > $maxLen) {
    $s = mb_substr($s, 0, $maxLen);
  }
  return $s;
}

function is_valid_email(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function client_ip(): string {
  // On shared hosting, REMOTE_ADDR is usually sufficient
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rate_limit_ok(string $rateFile, string $ip, int $window, int $max): bool {
  $now = time();
  $data = [];

  // Create file if missing
  if (!file_exists($rateFile)) {
    @file_put_contents($rateFile, json_encode(new stdClass()), LOCK_EX);
  }

  $fp = @fopen($rateFile, 'c+');
  if (!$fp) return true; // fail open

  // Lock file
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return true; // fail open
  }

  // Read existing JSON
  $contents = stream_get_contents($fp);
  if (is_string($contents) && strlen($contents) > 0) {
    $decoded = json_decode($contents, true);
    if (is_array($decoded)) $data = $decoded;
  }

  $events = $data[$ip] ?? [];
  if (!is_array($events)) $events = [];

  // Keep only recent events
  $events = array_values(array_filter($events, function($ts) use ($now, $window) {
    return is_int($ts) && ($now - $ts) <= $window;
  }));

  if (count($events) >= $max) {
    // write back cleaned list
    $data[$ip] = $events;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    return false;
  }

  $events[] = $now;
  $data[$ip] = $events;

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data));
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}

// ---- Basic anti-spam checks ----
// Honeypot: should be empty
$honeypot = isset($_POST['website']) ? clean_str((string)$_POST['website'], 200) : '';
if ($honeypot !== '') {
  // Pretend success to avoid tipping off bots
  respond(200, ['ok' => true]);
}

// Time-based check: require at least 3 seconds since page load
$startedAt = isset($_POST['started_at']) ? (int)$_POST['started_at'] : 0;
if ($startedAt > 0) {
  $elapsedMs = (int)(microtime(true) * 1000) - $startedAt;
  if ($elapsedMs < 3000) {
    respond(400, ['ok' => false, 'error' => 'Please wait a moment and try again.']);
  }
}

// Rate limiting
$ip = client_ip();
if (!rate_limit_ok($RATE_FILE, $ip, $RATE_LIMIT_WINDOW_SECONDS, $RATE_LIMIT_MAX_IN_WINDOW)) {
  respond(429, ['ok' => false, 'error' => 'Too many messages. Please try again later.']);
}

// ---- Validate inputs ----
$name    = isset($_POST['name']) ? clean_str((string)$_POST['name'], 120) : '';
$email   = isset($_POST['email']) ? clean_str((string)$_POST['email'], 180) : '';
$phone   = isset($_POST['phone']) ? clean_str((string)$_POST['phone'], 60) : '';
$subject = isset($_POST['subject']) ? clean_str((string)$_POST['subject'], 140) : '';
$message = isset($_POST['message']) ? clean_str((string)$_POST['message'], 5000) : '';

if ($name === '' || $email === '' || $subject === '' || $message === '') {
  respond(400, ['ok' => false, 'error' => 'Please fill in Name, Email, Subject, and Message.']);
}

if (!is_valid_email($email)) {
  respond(400, ['ok' => false, 'error' => 'Please enter a valid email address.']);
}

// ---- Send notification email ----
$siteHost = $_SERVER['HTTP_HOST'] ?? 'alttek.ca';
$ipLine = $ip !== '' ? "IP: {$ip}\n" : '';

$notifySubject = "[Alttek Contact] {$subject}";
$notifyBody =
"New message from Alttek website contact form\n\n" .
"Name: {$name}\n" .
"Email: {$email}\n" .
"Phone: {$phone}\n" .
"Subject: {$subject}\n" .
$ipLine .
"Host: {$siteHost}\n\n" .
"Message:\n{$message}\n";

$headers = [];
$headers[] = "From: {$FROM_NAME} <{$FROM_EMAIL}>";
$headers[] = "Reply-To: {$name} <{$email}>";
$headers[] = "Content-Type: text/plain; charset=UTF-8";

$sentNotify = @mail($TO_EMAIL, $notifySubject, $notifyBody, implode("\r\n", $headers));

// ---- Send auto-reply ----
$autoSubject = "Thanks, we got your message";
$autoBody =
"Hi {$name},\n\n" .
"Thanks, we got your message and will get back to you as soon as we can.\n\n" .
"— Alttek\n";

$autoHeaders = [];
$autoHeaders[] = "From: {$FROM_NAME} <{$FROM_EMAIL}>";
$autoHeaders[] = "Reply-To: {$TO_EMAIL}";
$autoHeaders[] = "Content-Type: text/plain; charset=UTF-8";

$sentAuto = @mail($email, $autoSubject, $autoBody, implode("\r\n", $autoHeaders));

if (!$sentNotify) {
  // Auto-reply might still send; but we should tell the user something went wrong.
  respond(500, ['ok' => false, 'error' => 'Could not send message right now. Please email support@alttek.ca directly.']);
}

respond(200, ['ok' => true]);
