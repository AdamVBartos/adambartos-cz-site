<?php
/**
 * Kontaktní formulář → e-mail.
 *
 * Běží na sdíleném WEDOS webhostingu (jen PHP + mail()). Next.js web je statický,
 * takže tohle je jediná „serverová“ část. Psáno kompatibilně s PHP 7.4+.
 *
 * NASTAVENÍ: upravte jen konstanty hned pod tímto komentářem.
 * V administraci WEDOS musí existovat schránka (nebo přeposílání) TO_EMAIL
 * a adresa FROM_EMAIL musí patřit k této doméně, jinak WEDOS zprávu zahodí.
 */

const TO_EMAIL      = 'ahoj@adambartos.cz';
const FROM_EMAIL    = 'ahoj@adambartos.cz';
const ALLOWED_HOSTS = array('adambartos.cz', 'www.adambartos.cz');
const MIN_SECONDS   = 2;     // rychleji než za 2 s formulář člověk nevyplní
const RATE_LIMIT    = 5;     // nejvýše 5 zpráv z jedné IP adresy...
const RATE_WINDOW   = 3600;  // ...za hodinu

date_default_timezone_set('Europe/Prague');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond($status, $ok, $error = '')
{
    http_response_code($status);
    echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => $error));
    exit;
}

function text_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Vyhodí řídicí znaky; u hodnot do hlaviček i zalomení řádků (ochrana proti header injection). */
function clean($s, $singleLine)
{
    $s = is_string($s) ? $s : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    if ($singleLine && $s !== null) {
        $s = preg_replace('/\s+/u', ' ', $s); // null při neplatném UTF-8 → níže se změní na ''
    }
    return $s === null ? '' : trim($s);
}

function mime_header($s)
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

// --- 1) jen POST ---------------------------------------------------------------
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'method');
}

// --- 2) původ požadavku: musí přijít z tohoto webu -------------------------------
$source = '';
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $source = $_SERVER['HTTP_ORIGIN'];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $source = $_SERVER['HTTP_REFERER'];
}
if ($source !== '') {
    $host = parse_url($source, PHP_URL_HOST);
    if (!$host || !in_array(strtolower($host), ALLOWED_HOSTS, true)) {
        respond(403, false, 'origin');
    }
}

// --- 3) honeypot: pole, které člověk nevidí. Vyplnil ho robot → tváříme se, že vše OK.
if (!empty($_POST['website'])) {
    respond(200, true);
}

// --- 4) časová past: formulář odeslaný „okamžitě“ je robot -----------------------
$elapsedMs = isset($_POST['t']) ? (int) $_POST['t'] : 0;
if ($elapsedMs < MIN_SECONDS * 1000) {
    respond(400, false, 'too_fast');
}

// --- 5) omezení počtu zpráv z jedné IP (selže-li zápis, nic se neblokuje) --------
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rateFile = rtrim(sys_get_temp_dir(), '/') . '/abform_' . sha1('adambartos-cz|' . $ip) . '.json';
$now = time();
$hits = array();
if (is_readable($rateFile)) {
    $stored = json_decode((string) @file_get_contents($rateFile), true);
    if (is_array($stored)) {
        foreach ($stored as $t) {
            if (is_int($t) && $t > $now - RATE_WINDOW) {
                $hits[] = $t;
            }
        }
    }
}
if (count($hits) >= RATE_LIMIT) {
    respond(429, false, 'rate');
}

// --- 6) validace -----------------------------------------------------------------
$name    = clean(isset($_POST['name']) ? $_POST['name'] : '', true);
$company = clean(isset($_POST['company']) ? $_POST['company'] : '', true);
$email   = clean(isset($_POST['email']) ? $_POST['email'] : '', true);
$message = clean(isset($_POST['message']) ? $_POST['message'] : '', false);
$consent = isset($_POST['consent']) ? $_POST['consent'] : '';

if ($name === '' || text_len($name) > 100
    || text_len($company) > 120
    || text_len($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || text_len($message) < 10 || text_len($message) > 4000
    || $consent !== '1') {
    respond(422, false, 'invalid');
}

// --- 7) sestavení a odeslání e-mailu ---------------------------------------------
$subject = 'Nová poptávka z webu: ' . $name;
$body = "Jméno: $name\n"
    . 'Společnost: ' . ($company !== '' ? $company : '-') . "\n"
    . "E-mail: $email\n\n"
    . "Zpráva:\n" . str_replace(array("\r\n", "\r"), "\n", $message) . "\n\n"
    . "--\nOdesláno z formuláře na adambartos.cz, " . date('j. n. Y H:i') . "\n";

$headers = implode("\r\n", array(
    'From: ' . mime_header('Web adambartos.cz') . ' <' . FROM_EMAIL . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
));

// -f = obálková adresa odesílatele na naší doméně (WEDOS ji u mail() vyžaduje)
$sent = @mail(TO_EMAIL, mime_header($subject), $body, $headers, '-f' . FROM_EMAIL);

if (!$sent) {
    error_log('kontakt.php: mail() selhal');
    respond(500, false, 'send');
}

// zápis do počítadla až po úspěšném odeslání
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits), LOCK_EX);

respond(200, true);
