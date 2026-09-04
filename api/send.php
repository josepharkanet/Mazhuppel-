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
    'consult'   => 'ho@mazhuppelchits.com', // general / consultation / contact
    'job'       => 'hr@mazhuppelchits.com', // careers / job applications
    'agreement' => 'ho@mazhuppelchits.com', // filled / signed agreement uploads
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
$ftype   = in_array($_POST['ftype'] ?? '', ['job', 'agreement'], true) ? (string) $_POST['ftype'] : 'consult';
$to      = $RECIPIENTS[$ftype];
$from    = $to; // send from the same on-domain mailbox for good deliverability

if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid phone number.']);
    exit;
}

$label   = $ftype === 'job' ? 'Job Application' : ($ftype === 'agreement' ? 'Filled Agreement' : 'Enquiry');
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
    if ($cv['size'] > 8 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'The file is too large (max 8 MB).']);
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

// Store job applications & filled-form uploads privately (outside public_html) for the CMS inbox.
if ($ftype === 'job' || $ftype === 'agreement') {
    $appDir   = __DIR__ . '/../../applications';
    $cvStored = '';
    $cvName   = '';
    if ($cvErr === UPLOAD_ERR_OK && isset($fdata, $ext, $fname) && $fdata !== '') {
        if (!is_dir("{$appDir}/cv")) { @mkdir("{$appDir}/cv", 0700, true); }
        $candidate = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@file_put_contents("{$appDir}/cv/{$candidate}", $fdata) !== false) {
            @chmod("{$appDir}/cv/{$candidate}", 0600);
            $cvStored = $candidate;
            $cvName   = $fname;
        }
    }
    if (!is_dir($appDir)) { @mkdir($appDir, 0700, true); }
    $logFile = "{$appDir}/applications.json";
    $log     = is_file($logFile) ? json_decode((string) file_get_contents($logFile), true) : [];
    if (!is_array($log)) { $log = []; }
    $log[]   = [
        'id'      => date('YmdHis') . bin2hex(random_bytes(3)),
        'kind'    => $ftype === 'job' ? 'application' : 'agreement',
        'date'    => date('Y-m-d H:i:s'),
        'role'    => $about,
        'name'    => $name,
        'phone'   => $phone,
        'email'   => $email,
        'message' => $message,
        'cv'      => $cvStored,
        'cvName'  => $cvName,
        'page'    => $page,
    ];
    @file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail could not be sent. Please call us instead.']);
}
