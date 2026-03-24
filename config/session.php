<?php
// /config/session.php

// Session configuration - MUST be loaded before any session_start()
return [
    'name' => 'NkoranzaSESSID',
    'lifetime' => 86400, // 24 hours
    'path' => '/',
    'domain' => parse_url(BASE_URL, PHP_URL_HOST),
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
];