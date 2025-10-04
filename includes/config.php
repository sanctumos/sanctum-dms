<?php
/**
 * DMS Configuration
 * Environment-aware configuration following CRM patterns
 */

// Prevent direct access
if (!defined('DMS_INITIALIZED')) {
    die('Direct access not allowed');
}

// Application constants
define('APP_NAME', 'Sanctum DMS');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'Dealer Management System');

// Environment detection (Windows = dev, Linux = prod)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', PHP_OS_FAMILY === 'Windows');
}

// Database configuration
define('DB_PATH', __DIR__ . '/../db/dms.db');
define('DB_TEST_PATH', __DIR__ . '/../db/test_dms.db');

// API configuration
define('API_VERSION', 'v1');
define('API_RATE_LIMIT', 1000); // requests per hour
define('API_TIMEOUT', 30); // seconds

// Security configuration
define('SESSION_LIFETIME', 3600); // 1 hour
define('API_KEY_LENGTH', 32);
define('PASSWORD_MIN_LENGTH', 8);

// File paths
define('SCHEMA_DEFINITIONS_PATH', __DIR__ . '/schema_definitions.php');
define('MIGRATIONS_LOG_PATH', __DIR__ . '/../db/migrations.log');

// Error reporting based on environment
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
}

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', !DEBUG_MODE ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

// Security headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer-when-downgrade');
    
    if (!DEBUG_MODE) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Utility functions
 */

/**
 * Sanitize input data
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate cryptographically secure random string
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Format API response
 */
function formatApiResponse($success = true, $data = null, $error = null, $code = 200) {
    $response = [
        'success' => $success,
        'timestamp' => date('c'),
        'version' => API_VERSION
    ];
    
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
        $response['code'] = $code;
    }
    
    return $response;
}

/**
 * Log message with timestamp
 */
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logFile = __DIR__ . '/../logs/app.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Check if running in test environment
 */
function isTestEnvironment() {
    return defined('DMS_TESTING') && DMS_TESTING === true;
}

/**
 * Get current environment
 */
function getEnvironment() {
    return DEBUG_MODE ? 'development' : 'production';
}

// Initialize application
if (!defined('DMS_INITIALIZED')) {
    define('DMS_INITIALIZED', true);
    logMessage('DMS Configuration loaded - Environment: ' . getEnvironment());
}
