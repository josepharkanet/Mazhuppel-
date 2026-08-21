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
 *     • general / consultation / contact  ->  ho@mazhuppelchits.com
 *     • careers / job applications         ->  hr@mazhuppelchits.com
 */
declare(strict_types=1);

$RECIPIENTS = [
    'consult' => 'ho@mazhuppelchits.com', // general / consultation / contact
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

$replyTo = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $from;

// Optional CV attachment (job applications).
$cvErr = $_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($cvErr !== UPLOAD_ERR_NO_FILE && $cvErr !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Your CV could not be uploaded (it may be too large). Try a smaller file.']);
    exit;
}

if ($cvErr === UPLOAD_ERR_OK) {
    $cv    = $_FILES['cv'];
    $types = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ];
    $ext = strtolower(pathinfo((string) $cv['name'], PATHINFO_EXTENSION));
    if ($cv['size'] > 6 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Your CV is too large (max 6 MB).']);
        exit;
    }
    if (!isset($types[$ext])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'CV must be a PDF, Word document or image.']);
        exit;
    }
    $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $cv['name']);
    $fdata = (string) @file_get_contents($cv['tmp_name']);
    $bnd   = '=_mzc_' . bin2hex(random_bytes(12));

    $body .= "CV      : attached ({$fname})\n";

    $headers  = "From: Mazhuppel Chits Website <{$from}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$bnd}\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $mime  = "--{$bnd}\r\n";
    $mime .= "Content-Type: text/plain; charset=utf-8\r\n";
    $mime .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $mime .= $body . "\r\n";
    $mime .= "--{$bnd}\r\n";
    $mime .= "Content-Type: {$types[$ext]}; name=\"{$fname}\"\r\n";
    $mime .= "Content-Transfer-Encoding: base64\r\n";
    $mime .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
    $mime .= chunk_split(base64_encode($fdata)) . "\r\n";
    $mime .= "--{$bnd}--";

    $sent = @mail($to, $subject, $mime, $headers, "-f{$from}");
} else {
    $headers  = "From: Mazhuppel Chits Website <{$from}>\r\n";
    $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $sent = @mail($to, $subject, $body, $headers, "-f{$from}");
}

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail could not be sent. Please call us instead.']);
}
