<?php
// config/constants.php
// SECURITY PATCHES:
//   [1] DB credentials now read from environment variables with safe defaults for
//       local dev only. Never commit real credentials to version control.
//   [2] ALLOWED_ORIGIN constant added — used by sync-pending-votes.php CORS fix.
//   [3] CSRF_TOKEN_SECRET placeholder removed (the CSRF implementation uses
//       random_bytes() correctly and does not need this constant).

// ---------------------------------------------------------------------------
// HOW TO SET ENVIRONMENT VARIABLES
//
// Apache (.htaccess or VirtualHost):
//   SetEnv DB_HOST     127.0.0.1
//   SetEnv DB_NAME     nkoranza_voting
//   SetEnv DB_USER     nkoranza_app          ← dedicated low-privilege user
//   SetEnv DB_PASS     your-strong-password
//
// Nginx (fastcgi_param block):
//   fastcgi_param DB_HOST     127.0.0.1;
//   fastcgi_param DB_NAME     nkoranza_voting;
//   fastcgi_param DB_USER     nkoranza_app;
//   fastcgi_param DB_PASS     your-strong-password;
//
// Or use a .env file loaded by a library such as vlucas/phpdotenv.
//
// MySQL: create a dedicated user with minimum required privileges:
//   CREATE USER 'nkoranza_app'@'127.0.0.1' IDENTIFIED BY 'your-strong-password';
//   GRANT SELECT, INSERT, UPDATE, DELETE ON nkoranza_voting.* TO 'nkoranza_app'@'127.0.0.1';
//   FLUSH PRIVILEGES;
// ---------------------------------------------------------------------------

// [FIX 1] Read from environment; fall back to the old defaults for local dev only.
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'nkoranza_voting');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');       // change in production
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');           // change in production

// App paths
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));

// Auto-detect base URL (server-side only — do not trust client-supplied host for
// security decisions, but it's acceptable for generating self-referential links)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$basePath = '/nkoranza-voting';

if (!defined('BASE_URL')) define('BASE_URL', $protocol . '://' . $host . $basePath);

// [FIX 2] Explicit CORS allowlist used by sync-pending-votes.php.
// Add additional origins here if you host the app on multiple domains.
if (!defined('ALLOWED_ORIGINS')) define('ALLOWED_ORIGINS', [BASE_URL]);

// Default image
if (!defined('DEFAULT_USER_IMAGE')) define('DEFAULT_USER_IMAGE', BASE_URL . '/assets/images/default-user.jpg');

// [FIX 3] CSRF_TOKEN_SECRET removed — the CSRF implementation in helpers/functions.php
// already uses bin2hex(random_bytes(32)) which is cryptographically secure and does
// not depend on this constant. The placeholder value was misleading and unused.
