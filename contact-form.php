<?php
/**
 * contact-form.php
 * -----------------
 * Handles the "Contact Us" form submission from contact-session.html.
 *
 * SETUP:
 * 1. Upload this file to the SAME folder as contact-session.html on your
 *    PHP-enabled web host (PHP 7.4+ is fine).
 * 2. Change RECIPIENT_EMAIL below to the address you want messages sent to.
 * 3. Most shared hosts already have PHP's mail() working out of the box.
 *    If messages don't arrive, ask your host whether you need SMTP instead
 *    (e.g. via PHPMailer) — mail() deliverability varies a lot by host.
 *
 * This endpoint only ever returns JSON, e.g.:
 *   { "success": true,  "message": "Message sent!" }
 *   { "success": false, "message": "Please enter a valid email address." }
 */

// ---- Configuration -------------------------------------------------
const RECIPIENT_EMAIL = 'hello@ultimatekids.com'; // <-- change this
const SITE_NAME        = 'Ultimate Kids';

// ---- Boilerplate -----------------------------------------------------
header('Content-Type: application/json');

// Only allow POST requests from this form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

function respond(bool $success, string $message): void {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// ---- Honeypot spam trap ----------------------------------------------
// contact-session.html includes a hidden "website" field real visitors
// never see or fill in. If it arrives non-empty, it's almost certainly a bot.
if (!empty($_POST['website'] ?? '')) {
    // Pretend success so bots don't learn the honeypot was tripped.
    respond(true, 'Message sent!');
}

// ---- Gather + validate input ------------------------------------------
$name    = trim((string)($_POST['name']    ?? ''));
$email   = trim((string)($_POST['email']   ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $subject === '' || $message === '') {
    respond(false, 'Please fill in every field.');
}

if (mb_strlen($name) > 100 || mb_strlen($subject) > 150 || mb_strlen($message) > 5000) {
    respond(false, 'One of the fields is too long. Please shorten your message.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

// Strip anything that looks like an attempt to inject extra mail headers.
$hasHeaderInjection = static function (string $value): bool {
    return (bool) preg_match('/[\r\n]/', $value);
};
if ($hasHeaderInjection($name) || $hasHeaderInjection($email) || $hasHeaderInjection($subject)) {
    respond(false, 'Your message contained invalid characters. Please try again.');
}

// ---- Build + send the email --------------------------------------------
$mailSubject = '[' . SITE_NAME . '] New contact form message: ' . $subject;

$mailBody =
    "You've received a new message from the " . SITE_NAME . " contact form.\n\n" .
    "Name:    {$name}\n" .
    "Email:   {$email}\n" .
    "Subject: {$subject}\n\n" .
    "Message:\n{$message}\n";

// Use a safe "From" address on your own domain; put the sender's address
// in Reply-To so hitting "reply" in your inbox goes straight to them.
$fromAddress = 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$headers   = [];
$headers[] = 'From: ' . SITE_NAME . ' <' . $fromAddress . '>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

$sent = @mail(RECIPIENT_EMAIL, $mailSubject, $mailBody, implode("\r\n", $headers));

if ($sent) {
    respond(true, 'Message sent! We\'ll get back to you soon.');
}

// ---- Fallback: log to disk if mail() isn't available/configured ---------
// This keeps submissions from being silently lost on hosts where mail()
// fails. Check contact-submissions.log periodically, or wire this file
// path into your own alerting.
$logLine = sprintf(
    "[%s] %s <%s> — %s\n%s\n\n",
    date('Y-m-d H:i:s'),
    $name,
    $email,
    $subject,
    $message
);
$logFile = __DIR__ . '/contact-submissions.log';
$logged  = @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

if ($logged !== false) {
    // We didn't manage to email it, but it's safely recorded — still tell
    // the visitor it worked so as not to alarm them; you'll pick it up
    // from the log.
    respond(true, 'Message sent! We\'ll get back to you soon.');
}

respond(false, 'Sorry, something went wrong on our end. Please try again shortly.');
