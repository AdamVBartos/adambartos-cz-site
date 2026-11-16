<?php
/**
 * Stejný důvod jako v ../index.php: průchod přes PHP zajistí, že stránka dostane
 * bezpečnostní hlavičky z .htaccess. Obsah je hotové HTML z Next.js exportu.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/index.html');
