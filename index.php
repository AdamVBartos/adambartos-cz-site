<?php
/**
 * Vstupní bod pro "/" a pro neexistující adresy.
 *
 * Proč PHP u jinak statického webu: hosting (OpenLiteSpeed) vyhodnocuje direktivy
 * z .htaccess (Header, přesměrování) jen u požadavků, které procházejí přes PHP.
 * Přímo servírované statické soubory je nedostanou. Tenhle skript jen vypíše
 * hotové HTML, takže stránka dostane bezpečnostní hlavičky (CSP, HSTS, ...).
 *
 * Neexistující adresa musí vrátit skutečné 404 (ne hlavní stránku se stavem 200).
 */
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

if ($path === '/' || $path === '' || $path === '/index.php') {
    readfile(__DIR__ . '/index.html');
    exit;
}

http_response_code(404);
header('X-Robots-Tag: noindex');
readfile(__DIR__ . '/404.html');
