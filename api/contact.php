<?php
// api/contact.php
// Contact form handler (HTML email + plaintext fallback via multipart/alternative).
// Adds basic bot/spam filtering BEFORE sending mail:
//  - Origin/Referer allowlist
//  - URL / spam heuristics (silently drop)
//  - Rate limit by IP + by email

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// ---- Config ----
$TO_EMAIL = 'support@alttek.ca';

// Recommended: a mailbox on your domain. Many hosts reject mail with an "off-domain" From.
$FROM_EMAIL = 'postmaster@alttek.ca';
$FROM_NAME  = 'Alttek Website';

// Basic anti-bot/spam knobs
$ALLOWED_HOSTS = ['alttek.ca', 'www.alttek.ca'];   // allow form submits only from these
$SILENT_DROP_ON_SPAM = true;                       // true = pretend success but do NOT send email

// Heuristic thresholds
$MIN_MESSAGE_CHARS = 20;                           // messages shorter than this are often spam/bots
$MAX_URLS_IN_MESSAGE = 2;                          // drop if message contains more than this many URLs-like patterns

// Rate limit: max 5 messages per 10 minutes per IP
$RATE_LIMIT_WINDOW_SECONDS = 600;
$RATE_LIMIT_MAX_IN_WINDOW  = 5;

// Extra rate limit by email (prevents one bot blasting you from rotating IPs)
$EMAIL_RATE_LIMIT_MAX_IN_WINDOW = 3;               // per 10 min per sender email

$DATA_DIR  = dirname(__DIR__) . '/data';
$RATE_FILE = $DATA_DIR . '/rate_limit.json';

// Ensure data dir exists
if (!is_dir($DATA_DIR)) {
  @mkdir($DATA_DIR, 0755, true);
}

// ---- Helpers ----
function clean_str(string $s, int $maxLen = 2000): string {
  $s = trim($s);
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
  } else {
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
  }
  return $s;
}

// For header values (prevents header injection)
function clean_header_str(string $s, int $maxLen = 180): string {
  $s = trim($s);
  $s = str_replace(["\r", "\n"], ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
  } else {
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
  }
  return $s;
}

function is_valid_email(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function esc_html(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function encode_header(string $s): string {
  $s = clean_header_str($s, 200);
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
  }
  return $s;
}

function safe_boundary(): string {
  if (function_exists('random_bytes')) {
    return 'b1_' . bin2hex(random_bytes(12));
  }
  return 'b1_' . md5(uniqid((string)mt_rand(), true));
}

// Build a multipart/alternative email with both text + HTML
function send_html_mail(string $to, string $subject, string $textBody, string $htmlBody, array $headers): bool {
  $boundary = safe_boundary();

  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

  $message  = "This is a multi-part message in MIME format.\r\n\r\n";

  // Plain text part
  $message .= '--' . $boundary . "\r\n";
  $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $textBody . "\r\n\r\n";

  // HTML part
  $message .= '--' . $boundary . "\r\n";
  $message .= "Content-Type: text/html; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $htmlBody . "\r\n\r\n";

  $message .= '--' . $boundary . "--\r\n";

  // Subject encoding (UTF-8 safe)
  $subj = encode_header($subject);

  return @mail($to, $subj, $message, implode("\r\n", $headers));
}

// Allowlist check (Origin/Referer). Not bulletproof, but blocks a lot of junk.
function request_from_allowed_hosts(array $allowedHosts): bool {
  $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
  $referer = $_SERVER['HTTP_REFERER'] ?? '';
  $source  = $origin !== '' ? $origin : $referer;

  if ($source === '') return false;

  $host = parse_url($source, PHP_URL_HOST);
  if (!is_string($host) || $host === '') return false;

  $host = strtolower($host);
  $allowed = array_map('strtolower', $allowedHosts);

  return in_array($host, $allowed, true);
}

// Simple “spammy content” checks
function count_urls(string $text): int {
  // Counts http(s)://, www., and obvious domain.tld patterns
  $pattern = '/(https?:\/\/|www\.|[a-z0-9.-]+\.[a-z]{2,})(\/\S*)?/i';
  if (preg_match_all($pattern, $text, $m)) return count($m[0]);
  return 0;
}

function looks_spammy(string $name, string $email, string $subject, string $message, int $minChars, int $maxUrls): bool {
  $msg = trim($message);

  // Very short messages are often bots
  if (function_exists('mb_strlen')) {
    if (mb_strlen($msg) < $minChars) return true;
  } else {
    if (strlen($msg) < $minChars) return true;
  }

  // Too many links
  if (count_urls($msg) > $maxUrls) return true;

  // HTML tags inside message (often spam payloads)
  if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $msg)) return true;

  // Common spam keywords (keep this conservative)
  $hay = strtolower($name . ' ' . $email . ' ' . $subject . ' ' . $msg);
  $badWords = ['viagra', 'casino', 'crypto', 'forex', 'loan', 'seo service', 'backlink', 'porn'];
  foreach ($badWords as $w) {
    if (strpos($hay, $w) !== false) return true;
  }

  return false;
}

// Rate limit store (keyed by string, not just IP)
function rate_limit_ok(string $rateFile, string $key, int $window, int $max): bool {
  $now  = time();
  $data = [];

  if (!file_exists($rateFile)) {
    @file_put_contents($rateFile, json_encode(new stdClass()), LOCK_EX);
  }

  $fp = @fopen($rateFile, 'c+');
  if (!$fp) return true; // fail open

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return true; // fail open
  }

  rewind($fp);
  $contents = stream_get_contents($fp);
  if (is_string($contents) && $contents !== '') {
    $decoded = json_decode($contents, true);
    if (is_array($decoded)) $data = $decoded;
  }

  $events = $data[$key] ?? [];
  if (!is_array($events)) $events = [];

  $events = array_values(array_filter($events, function ($ts) use ($now, $window) {
    return is_int($ts) && ($now - $ts) <= $window;
  }));

  if (count($events) >= $max) {
    $data[$key] = $events;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    return false;
  }

  $events[]  = $now;
  $data[$key] = $events;

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data));
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}

function silent_drop_or_error(bool $silentDrop): void {
  if ($silentDrop) {
    // Pretend success so bots don't learn
    respond(200, ['ok' => true]);
  }
  respond(400, ['ok' => false, 'error' => 'Unable to send. Please try again later.']);
}

// ---- Basic anti-spam checks ----

// Honeypot: should be empty
$honeypot = isset($_POST['website']) ? clean_str((string)$_POST['website'], 200) : '';
if ($honeypot !== '') {
  respond(200, ['ok' => true]); // pretend success
}

// Origin/Referer allowlist
if (!request_from_allowed_hosts($ALLOWED_HOSTS)) {
  silent_drop_or_error($SILENT_DROP_ON_SPAM);
}

// Time-based check: require at least 3 seconds since page load (if provided)
$startedAt = isset($_POST['started_at']) ? (int)$_POST['started_at'] : 0;
if ($startedAt > 0) {
  $elapsedMs = (int)(microtime(true) * 1000) - $startedAt;
  if ($elapsedMs < 3000) {
    respond(400, ['ok' => false, 'error' => 'Please wait a moment and try again.']);
  }
}

// Rate limiting by IP
$ip = client_ip();
if (!rate_limit_ok($RATE_FILE, 'ip:' . $ip, $RATE_LIMIT_WINDOW_SECONDS, $RATE_LIMIT_MAX_IN_WINDOW)) {
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

// Rate limiting by email (after validation)
$emailKey = 'em:' . strtolower($email);
if (!rate_limit_ok($RATE_FILE, $emailKey, $RATE_LIMIT_WINDOW_SECONDS, $EMAIL_RATE_LIMIT_MAX_IN_WINDOW)) {
  respond(429, ['ok' => false, 'error' => 'Too many messages from this email. Please try again later.']);
}

// Heuristic spam detection (drop before sending)
if (looks_spammy($name, $email, $subject, $message, $MIN_MESSAGE_CHARS, $MAX_URLS_IN_MESSAGE)) {
  silent_drop_or_error($SILENT_DROP_ON_SPAM);
}

// Header-safe versions
$hdrFromName = encode_header($FROM_NAME);
$hdrName     = encode_header($name);
$hdrSubject  = clean_header_str($subject, 140);
$hdrEmail    = clean_header_str($email, 180);

// ---- Build bodies (text + HTML) ----
$siteHost = $_SERVER['HTTP_HOST'] ?? 'alttek.ca';
$ipLine   = $ip !== '' ? "IP: {$ip}\n" : '';

$notifySubject = "[Alttek Contact] {$hdrSubject}";

// Plaintext notification
$notifyText =
"New message from Alttek website contact form\n\n" .
"Name: {$name}\n" .
"Email: {$email}\n" .
"Phone: {$phone}\n" .
"Subject: {$subject}\n" .
$ipLine .
"Host: {$siteHost}\n\n" .
"Message:\n{$message}\n";

// HTML notification
$notifyHtmlMessage = nl2br(esc_html($message), false);
$notifyHtml =
'<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;line-height:1.5;color:#111;">' .
  '<h2 style="margin:0 0 12px 0;">New message from Alttek website contact form</h2>' .
  '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:720px;">' .
    '<tr><td style="padding:6px 0;width:110px;"><strong>Name:</strong></td><td style="padding:6px 0;">' . esc_html($name) . '</td></tr>' .
    '<tr><td style="padding:6px 0;"><strong>Email:</strong></td><td style="padding:6px 0;">' . esc_html($email) . '</td></tr>' .
    '<tr><td style="padding:6px 0;"><strong>Phone:</strong></td><td style="padding:6px 0;">' . esc_html($phone) . '</td></tr>' .
    '<tr><td style="padding:6px 0;"><strong>Subject:</strong></td><td style="padding:6px 0;">' . esc_html($subject) . '</td></tr>' .
    '<tr><td style="padding:6px 0;"><strong>IP:</strong></td><td style="padding:6px 0;">' . esc_html($ip) . '</td></tr>' .
    '<tr><td style="padding:6px 0;"><strong>Host:</strong></td><td style="padding:6px 0;">' . esc_html($siteHost) . '</td></tr>' .
  '</table>' .
  '<div style="margin-top:14px;padding:12px;border:1px solid #e5e5e5;border-radius:8px;background:#fafafa;max-width:720px;">' .
    '<div style="font-weight:bold;margin-bottom:8px;">Message:</div>' .
    '<div style="white-space:normal;">' . $notifyHtmlMessage . '</div>' .
  '</div>' .
'</body></html>';

// ---- Headers ----
$notifyHeaders = [];
$notifyHeaders[] = "From: {$hdrFromName} <{$FROM_EMAIL}>";
$notifyHeaders[] = "Reply-To: {$hdrName} <{$hdrEmail}>";

// Send notification (multipart: text + html)
$sentNotify = send_html_mail($TO_EMAIL, $notifySubject, $notifyText, $notifyHtml, $notifyHeaders);

// ---- Auto-reply ----
$autoSubject = "Thanks, we got your message";

// Plaintext auto-reply
$autoText =
"Hi {$name},\n\n" .
"Thanks, we got your message and will get back to you as soon as we can.\n\n" .
"— Alttek\n";

// HTML auto-reply
$autoHtml =
'<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;line-height:1.5;color:#111;">' .
  '<p style="margin:0 0 12px 0;">Hi ' . esc_html($name) . ',</p>' .
  '<p style="margin:0 0 12px 0;">Thanks, we got your message and will get back to you as soon as we can.</p>' .
  '<p style="margin:0;">&mdash; Alttek</p>' .
'</body></html>';

$autoHeaders = [];
$autoHeaders[] = "From: {$hdrFromName} <{$FROM_EMAIL}>";
$autoHeaders[] = "Reply-To: {$TO_EMAIL}";

// Send auto-reply (multipart: text + html)
$sentAuto = send_html_mail($email, $autoSubject, $autoText, $autoHtml, $autoHeaders);

if (!$sentNotify) {
  respond(500, ['ok' => false, 'error' => 'Could not send message right now. Please email support@alttek.ca directly.']);
}

respond(200, ['ok' => true]);
