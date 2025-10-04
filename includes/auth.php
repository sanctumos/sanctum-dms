<?php
/**
 * DMS Authentication System
 * Dual authentication strategy: API keys + session-based auth
 */

// Prevent direct access
if (!defined('DMS_INITIALIZED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class Auth {
    private $db;
    private $user = null;
    private $isAuthenticated = false;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->initializeSession();
        $this->authenticate();
    }
    
    /**
     * Initialize session
     */
    private function initializeSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Authenticate user via API key or session
     */
    private function authenticate() {
        // Try API key authentication first
        if ($this->authenticateApiKey()) {
            return;
        }
        
        // Fall back to session authentication
        $this->authenticateSession();
    }
    
    /**
     * Authenticate via API key
     */
    private function authenticateApiKey() {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return false;
        }
        
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') !== 0) {
            return false;
        }
        
        $apiKey = substr($auth, 7);
        
        try {
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE api_key = ? AND status = 'active'",
                [':api_key' => $apiKey]
            );
            
            if ($user) {
                $this->user = $user;
                $this->isAuthenticated = true;
                
                // Update last login
                $this->updateLastLogin($user['id']);
                
                logMessage("API authentication successful for user: " . $user['username']);
                return true;
            }
        } catch (Exception $e) {
            logMessage("API authentication failed: " . $e->getMessage(), 'ERROR');
        }
        
        return false;
    }
    
    /**
     * Authenticate via session
     */
    private function authenticateSession() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        try {
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE id = ? AND status = 'active'",
                [':id' => $_SESSION['user_id']]
            );
            
            if ($user) {
                $this->user = $user;
                $this->isAuthenticated = true;
                
                // Update last login
                $this->updateLastLogin($user['id']);
                
                logMessage("Session authentication successful for user: " . $user['username']);
                return true;
            }
        } catch (Exception $e) {
            logMessage("Session authentication failed: " . $e->getMessage(), 'ERROR');
        }
        
        // Clear invalid session
        $this->logout();
        return false;
    }
    
    /**
     * Update last login timestamp
     */
    private function updateLastLogin($userId) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->execute();
        } catch (Exception $e) {
            logMessage("Failed to update last login: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated() {
        return $this->isAuthenticated;
    }
    
    /**
     * Get current user
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Get user ID
     */
    public function getUserId() {
        return $this->user ? $this->user['id'] : null;
    }
    
    /**
     * Get user role
     */
    public function getUserRole() {
        return $this->user ? $this->user['role'] : null;
    }
    
    /**
     * Check if user has specific role
     */
    public function hasRole($role) {
        return $this->getUserRole() === $role;
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->hasRole('admin');
    }
    
    /**
     * Login with username and password
     */
    public function login($username, $password) {
        try {
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE username = ? AND status = 'active'",
                [':username' => $username]
            );
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                logMessage("Login failed for username: $username", 'WARNING');
                return false;
            }
            
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            $this->user = $user;
            $this->isAuthenticated = true;
            
            $this->updateLastLogin($user['id']);
            
            logMessage("Login successful for user: $username");
            return true;
            
        } catch (Exception $e) {
            logMessage("Login error: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            logMessage("Logout for user: " . ($_SESSION['username'] ?? 'unknown'));
        }
        
        session_destroy();
        $this->user = null;
        $this->isAuthenticated = false;
    }
    
    /**
     * Create new user
     */
    public function createUser($username, $email, $password, $role = 'user') {
        try {
            // Check if username or email already exists
            $existing = $this->db->fetchOne(
                "SELECT id FROM users WHERE username = ? OR email = ?",
                [':username' => $username, ':email' => $email]
            );
            
            if ($existing) {
                throw new Exception('Username or email already exists');
            }
            
            // Generate API key
            $apiKey = generateSecureToken(API_KEY_LENGTH);
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $this->db->prepare("
                INSERT INTO users (username, email, password_hash, role, api_key) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->bindValue(1, $username, SQLITE3_TEXT);
            $stmt->bindValue(2, $email, SQLITE3_TEXT);
            $stmt->bindValue(3, $passwordHash, SQLITE3_TEXT);
            $stmt->bindValue(4, $role, SQLITE3_TEXT);
            $stmt->bindValue(5, $apiKey, SQLITE3_TEXT);
            
            $stmt->execute();
            
            $userId = $this->db->lastInsertRowID();
            
            logMessage("User created: $username (ID: $userId)");
            
            return [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'api_key' => $apiKey
            ];
            
        } catch (Exception $e) {
            logMessage("User creation failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Generate new API key for user
     */
    public function generateApiKey($userId) {
        try {
            $apiKey = generateSecureToken(API_KEY_LENGTH);
            
            $stmt = $this->db->prepare("UPDATE users SET api_key = ? WHERE id = ?");
            $stmt->bindValue(1, $apiKey, SQLITE3_TEXT);
            $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt->execute();
            
            logMessage("API key generated for user ID: $userId");
            return $apiKey;
            
        } catch (Exception $e) {
            logMessage("API key generation failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Check rate limit
     */
    public function checkRateLimit() {
        $userId = $this->getUserId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rate_limit:$userId:$ip";
        
        // Simple in-memory rate limiting (in production, use Redis or similar)
        $currentTime = time();
        $window = 3600; // 1 hour
        $maxRequests = API_RATE_LIMIT;
        
        // For now, just log the request
        logMessage("Rate limit check for user $userId from IP $ip");
        
        return true; // Always allow for now
    }
    
    /**
     * Require authentication
     */
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(formatApiResponse(false, null, 'Authentication required', 401));
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole($role) {
        $this->requireAuth();
        
        if (!$this->hasRole($role)) {
            http_response_code(403);
            echo json_encode(formatApiResponse(false, null, 'Insufficient permissions', 403));
            exit;
        }
    }
    
    /**
     * Require admin role
     */
    public function requireAdmin() {
        $this->requireRole('admin');
    }
}
