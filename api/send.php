<?php
/**
 * Mazhuppel Chits — website form mailer.
 * Receives the consultation / application form and emails it to your
 * cPanel mailbox using HostArmada's local mail server. No database.
 *
 * SETUP:
 *   Enquiries are routed by form type to the mailboxes in $RECIPIENTS below.
 *   Both must be REAL mailboxes on this domain (cPanel → Email Accounts) so that
 *   mail sends and isn't marked as spam:
 *     • general / consultation / contact  ->  info@mazhuppelchits.com
 *     • careers / job applications         ->  hr@mazhuppelchits.com
 */
declare(strict_types=1);

$RECIPIENTS = [
    'consult' => 'info@mazhuppelchits.com', // general / consultation / contact
    'job'     => 'hr@mazhuppelchits.com',   // careers / job applications
];

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Honeypot: real users leave this empty; bots fill it.
if (!empty($_POST['company'])) {
    echo json_encode(['ok' => true]); // pretend success, drop silently
    exit;
}

$clean = static function (string $key, int $max = 300): string {
    $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $v = trim(str_replace(["\r", "\n"], ' ', $v)); // strip header-injection chars
    return mb_substr($v, 0, $max);
};

$name    = $clean('name', 120);
$phone   = $clean('phone', 40);
$email   = $clean('email', 160);
$message = $clean('message', 2000);
$page    = $clean('page', 300);
$about   = $clean('subject', 200); // which kuri / job this enquiry is about
$ftype   = ($_POST['ftype'] ?? '') === 'job' ? 'job' : 'consult';
$to      = $RECIPIENTS[$ftype];
$from    = $to; // send from the same on-domain mailbox for good deliverability

if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid phone number.']);
    exit;
}

$label   = $ftype === 'job' ? 'Job Application' : 'Enquiry';
$subject = ($about !== '' ? "New {$about}" : "New {$label}") . " — mazhuppelchits.com";

$body  = "You have a new {$label} from the website:\n\n";
if ($about !== '') { $body .= "Regarding: {$about}\n"; }
$body .= "Name    : " . ($name !== '' ? $name : '(not given)') . "\n";
$body .= "Phone   : {$phone}\n";
$body .= "Email   : " . ($email !== '' ? $email : '(not given)') . "\n";
$body .= "Message : " . ($message !== '' ? $message : '(none)') . "\n";
$body .= "Page    : {$page}\n";
$body .= "Time    : " . date('Y-m-d H:i:s') . "\n";

$replyTo  = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $from;
$headers  = "From: Mazhuppel Chits Website <{$from}>\r\n";
$headers .= "Reply-To: {$replyTo}\r\n";
$headers .= "Content-Type: text/plain; charset=utf-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$sent = @mail($to, $subject, $body, $headers, "-f{$from}");

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail could not be sent. Please call us instead.']);
}
