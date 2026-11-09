<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    exit('Configuration missing. Copy config.example.php to config.php and update it.');
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Hong_Kong');
set_exception_handler(function (Throwable $exception): void { serverError($exception); });

ini_set('display_errors', '0');
require_once __DIR__ . '/security.php';
applicationKey();
$config['base_url'] = rtrim(trim($config['base_url']), '/');
if (!filter_var($config['base_url'], FILTER_VALIDATE_URL) || preg_match('/\s/', $config['base_url']) || !in_array(parse_url($config['base_url'], PHP_URL_SCHEME), ['http','https'], true)) throw new RuntimeException('Invalid base_url');
if (($config['app_env'] ?? '') === 'production' && (parse_url($config['base_url'], PHP_URL_SCHEME) !== 'https' || ($config['mail_transport'] ?? '') !== 'mail')) throw new RuntimeException('Production requires HTTPS and configured mail');
startSecureSession($config);
sendSecurityHeaders($config);
// A single guard protects every POST, including login, registration and logout.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrf();
} elseif (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

try {
    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    if (!empty($config['db_port'])) {
        $dsn = 'mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $sslCa = (string) ($config['db_ssl_ca'] ?? '');
    if (defined('Pdo\Mysql::ATTR_SSL_CA')) {
        $sslCaAttr = \Pdo\Mysql::ATTR_SSL_CA;
        $sslVerifyAttr = \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT;
    } elseif (defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $sslCaAttr = constant('PDO::MYSQL_ATTR_SSL_CA');
        $sslVerifyAttr = constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT');
    } else {
        $sslCaAttr = null;
        $sslVerifyAttr = null;
    }
    if ($sslCa !== '' && is_readable($sslCa) && $sslCaAttr !== null) {
        $options[$sslCaAttr] = $sslCa;
    }
    if (!empty($config['db_ssl_verify']) && $sslVerifyAttr !== null) {
        $options[$sslVerifyAttr] = true;
    }
    if ($sslCa !== '' && !is_readable($sslCa)) throw new RuntimeException('SSL CA is unreadable.');
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
    $offset = (new DateTimeImmutable())->format('P');
    $pdo->exec("SET time_zone = " . $pdo->quote($offset));
} catch (PDOException $exception) {
    serverError($exception);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/mfa.php';
require_once __DIR__ . '/tickets.php';

// A session used while MFA is paused must not retain proof of a second factor.
if (!mfaFeatureEnabled()) {
    unset($_SESSION['mfa_verified'], $_SESSION['mfa_pending'], $_SESSION['mfa_setup']);
}

// Refresh roles on each request so a database role change takes effect immediately.
if (isLoggedIn()) {
    try {
        $statement = $pdo->prepare('SELECT user_id,name,email,role,email_verified_at,is_school_email,school_email_verified,is_active,auth_version,must_change_password FROM users WHERE user_id=?');
        $statement->execute([currentUser()['user_id']]);
        $user = $statement->fetch();
        if (!$user || !(int)$user['is_active'] || (int)($_SESSION['auth_version'] ?? 0) !== (int)$user['auth_version']) {
            resetSession();
        } else {
            $_SESSION['user'] = $user;
        }
    } catch (Throwable $exception) {
        serverError($exception);
    }
}

if (isLoggedIn()) {
    $hasMfa = mfaFeatureEnabled() && mfaEnabled($pdo, (int)currentUser()['user_id']);
    if ($hasMfa && empty($_SESSION['mfa_verified'])) { resetSession(); redirect('login.php'); }
    if (!empty(currentUser()['must_change_password'])) {
        if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['change_initial_password.php','logout.php'], true)) redirect('change_initial_password.php');
        return; // Password replacement precedes enrollment; staff actions remain unavailable.
    }
    if (mfaFeatureEnabled() && !$hasMfa && in_array(currentUser()['role'], ['admin','staff'], true)
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['mfa.php','logout.php'], true)) redirect('mfa.php');
}
