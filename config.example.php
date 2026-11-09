<?php
// Copy to config.php. Never commit credentials. See README.md for XAMPP / Ubuntu.
return [
    'app_env' => 'development',
    'mfa_enabled' => true, // false pauses MFA for testing only when app_env is development
    'app_key' => '', // Generate: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'eventlink',
    'db_user' => 'eventlink', 'db_pass' => '',
    'db_ssl_ca' => '', 'db_ssl_verify' => true,
    'base_url' => 'http://localhost/EventMangement',
    'timezone' => 'Asia/Hong_Kong',
    'session_secure' => false, // true for HTTPS, including a trusted TLS reverse proxy
    'session_idle_timeout' => 1800, 'session_absolute_timeout' => 28800,
    'hsts_max_age' => 0,
    'enable_developer_tools' => false,
    'developer_user_ids' => [], // Explicit admin user IDs; development environment only
    'school_email_domains' => ['stu.vtc.edu.hk', 'vtc.edu.hk'],
    'mail_transport' => 'log', // development: private files; production: mail with a configured MTA
    'cover_owner_bytes' => 50 * 1024 * 1024,
    'cover_total_bytes' => 512 * 1024 * 1024,
    'mail_from' => 'noreply@example.com',
    'checkin_before_seconds' => 7200, 'checkin_after_seconds' => 43200,
];
