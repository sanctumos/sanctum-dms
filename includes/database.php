<?php
/**
 * DMS Database Handler
 * Idempotent database management with auto-migration and self-healing schema
 */

// Prevent direct access
if (!defined('DMS_INITIALIZED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema_definitions.php';

class Database {
    private static $instance = null;
    private $db = null;
    private $isTestMode = false;
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->isTestMode = isTestEnvironment();
        $this->initializeDatabase();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize database connection and schema
     */
    private function initializeDatabase() {
        $dbPath = $this->isTestMode ? DB_TEST_PATH : DB_PATH;
        
        // Ensure database directory exists
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        try {
            $this->db = new SQLite3($dbPath);
            $this->db->enableExceptions(true);
            
            // Enable foreign key constraints
            $this->db->exec('PRAGMA foreign_keys = ON');
            
            // Set SQLite optimizations
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA synchronous = NORMAL');
            $this->db->exec('PRAGMA cache_size = 10000');
            $this->db->exec('PRAGMA temp_store = MEMORY');
            
            // Initialize schema
            $this->initializeSchema();
            
            logMessage("Database initialized: $dbPath");
            
        } catch (Exception $e) {
            logMessage("Database initialization failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Initialize database schema with idempotent migrations
     */
    private function initializeSchema() {
        // Create schema version table first
        $this->createSchemaVersionTable();
        
        // Get current schema version
        $currentVersion = $this->getCurrentSchemaVersion();
        $targetVersion = CURRENT_SCHEMA_VERSION;
        
        if ($currentVersion !== $targetVersion) {
            logMessage("Schema migration needed: $currentVersion -> $targetVersion");
            $this->migrateSchema($currentVersion, $targetVersion);
        }
        
        // Validate and repair schema
        $this->validateAndRepairSchema();
    }
    
    /**
     * Create schema version tracking table
     */
    private function createSchemaVersionTable() {
        $sql = "CREATE TABLE IF NOT EXISTS schema_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version VARCHAR(20) NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            description TEXT
        )";
        
        $this->db->exec($sql);
        
        // Create unique index
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_schema_version ON schema_version(version)');
    }
    
    /**
     * Get current schema version
     */
    private function getCurrentSchemaVersion() {
        try {
            $stmt = $this->db->prepare("SELECT version FROM schema_version ORDER BY applied_at DESC LIMIT 1");
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            return $row ? $row['version'] : '0.0.0';
        } catch (Exception $e) {
            return '0.0.0';
        }
    }
    
    /**
     * Migrate schema to target version
     */
    private function migrateSchema($fromVersion, $toVersion) {
        logMessage("Starting schema migration from $fromVersion to $toVersion");
        
        try {
            $this->beginTransaction();
            
            // Apply all schema definitions
            $this->applySchemaDefinitions();
            
            // Update schema version
            $this->updateSchemaVersion($toVersion, "Migration from $fromVersion to $toVersion");
            
            $this->commit();
            logMessage("Schema migration completed successfully");
            
        } catch (Exception $e) {
            $this->rollback();
            logMessage("Schema migration failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Apply all schema definitions idempotently
     */
    private function applySchemaDefinitions() {
        $definitions = getSchemaDefinitions();
        
        foreach ($definitions as $tableName => $definition) {
            if ($tableName === 'schema_version') {
                continue; // Already created
            }
            
            $this->createTableIfNotExists($tableName, $definition);
        }
    }
    
    /**
     * Create table if it doesn't exist
     */
    private function createTableIfNotExists($tableName, $definition) {
        // Check if table exists
        $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->bindValue(1, $tableName, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result->fetchArray()) {
            // Table exists, check for missing columns
            $this->addMissingColumns($tableName, $definition);
        } else {
            // Create new table
            $this->createTable($tableName, $definition);
        }
        
        // Create indexes
        if (isset($definition['indexes'])) {
            foreach ($definition['indexes'] as $indexSql) {
                $this->db->exec($indexSql);
            }
        }
    }
    
    /**
     * Create new table
     */
    private function createTable($tableName, $definition) {
        $columns = [];
        
        foreach ($definition['columns'] as $columnName => $columnDef) {
            $columns[] = "$columnName $columnDef";
        }
        
        $sql = "CREATE TABLE $tableName (" . implode(', ', $columns);
        
        // Add foreign key constraints
        if (isset($definition['foreign_keys'])) {
            $sql .= ', ' . implode(', ', $definition['foreign_keys']);
        }
        
        $sql .= ')';
        
        $this->db->exec($sql);
        logMessage("Created table: $tableName");
    }
    
    /**
     * Add missing columns to existing table
     */
    private function addMissingColumns($tableName, $definition) {
        $existingColumns = $this->getTableColumns($tableName);
        
        foreach ($definition['columns'] as $columnName => $columnDef) {
            if (!in_array($columnName, $existingColumns)) {
                $sql = "ALTER TABLE $tableName ADD COLUMN $columnName $columnDef";
                $this->db->exec($sql);
                logMessage("Added column $columnName to table $tableName");
            }
        }
    }
    
    /**
     * Get existing table columns
     */
    private function getTableColumns($tableName) {
        $stmt = $this->db->prepare("PRAGMA table_info($tableName)");
        $result = $stmt->execute();
        
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        return $columns;
    }
    
    /**
     * Update schema version
     */
    private function updateSchemaVersion($version, $description = '') {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO schema_version (version, description) VALUES (?, ?)");
        $stmt->bindValue(1, $version, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    /**
     * Validate and repair schema integrity
     */
    private function validateAndRepairSchema() {
        // Check foreign key constraints
        $this->db->exec('PRAGMA foreign_key_check');
        
        // Verify all required tables exist
        $requiredTables = getAllTableNames();
        foreach ($requiredTables as $tableName) {
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->bindValue(1, $tableName, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if (!$result->fetchArray()) {
                throw new Exception("Required table '$tableName' is missing");
            }
        }
        
        logMessage("Schema validation completed successfully");
    }
    
    /**
     * Execute SQL query
     */
    public function exec($sql) {
        try {
            return $this->db->exec($sql);
        } catch (Exception $e) {
            logMessage("SQL execution failed: $sql - " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Prepare SQL statement
     */
    public function prepare($sql) {
        try {
            return $this->db->prepare($sql);
        } catch (Exception $e) {
            logMessage("SQL preparation failed: $sql - " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $result = $stmt->execute();
        $rows = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        
        return $rows;
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertRowID() {
        return $this->db->lastInsertRowID();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        $this->db->exec('BEGIN TRANSACTION');
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        $this->db->exec('COMMIT');
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        $this->db->exec('ROLLBACK');
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    /**
     * Get database statistics
     */
    public function getStats() {
        $stats = [];
        
        // Get table counts
        $tables = getAllTableNames();
        foreach ($tables as $table) {
            $result = $this->fetchOne("SELECT COUNT(*) as count FROM $table");
            $stats[$table] = $result['count'];
        }
        
        // Get database size
        $dbPath = $this->isTestMode ? DB_TEST_PATH : DB_PATH;
        $stats['file_size'] = file_exists($dbPath) ? filesize($dbPath) : 0;
        
        return $stats;
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}
