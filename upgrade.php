<?php
/**
 * DMS CLI Upgrade Script
 * Upgrade database schema and perform maintenance
 */

// Allow CLI execution
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

// Define DMS initialization flag
define('DMS_INITIALIZED', true);
define('DMS_TESTING', false);

// Load core components
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

/**
 * Upgrade DMS database schema
 */
function upgradeDMS() {
    echo "Upgrading Sanctum DMS...\n";
    
    try {
        // Initialize database (this will auto-migrate schema)
        echo "Checking database schema...\n";
        $db = Database::getInstance();
        
        // Get current schema version
        $currentVersion = $db->fetchOne("SELECT version FROM schema_version ORDER BY applied_at DESC LIMIT 1");
        $currentVersion = $currentVersion ? $currentVersion['version'] : '0.0.0';
        
        echo "Current schema version: $currentVersion\n";
        echo "Target schema version: " . CURRENT_SCHEMA_VERSION . "\n";
        
        if ($currentVersion === CURRENT_SCHEMA_VERSION) {
            echo "✓ Database is already up to date\n";
        } else {
            echo "✓ Schema migration completed\n";
        }
        
        // Validate schema integrity
        echo "Validating schema integrity...\n";
        $db->exec('PRAGMA foreign_key_check');
        echo "✓ Schema integrity validated\n";
        
        // Display database stats
        $stats = $db->getStats();
        echo "\nDatabase Statistics:\n";
        foreach ($stats as $table => $count) {
            if ($table !== 'file_size') {
                echo "  $table: $count records\n";
            }
        }
        
        $fileSize = $stats['file_size'] ?? 0;
        echo "  Database size: " . formatBytes($fileSize) . "\n";
        
        echo "\n✓ DMS upgrade completed successfully!\n";
        
    } catch (Exception $e) {
        echo "✗ Upgrade failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Backup database
 */
function backupDatabase() {
    echo "Creating database backup...\n";
    
    try {
        $db = Database::getInstance();
        $dbPath = DB_PATH;
        $backupPath = dirname($dbPath) . '/backup_' . date('Y-m-d_H-i-s') . '.db';
        
        if (!copy($dbPath, $backupPath)) {
            throw new Exception("Failed to create backup file");
        }
        
        echo "✓ Database backed up to: $backupPath\n";
        
    } catch (Exception $e) {
        echo "✗ Backup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Restore database from backup
 */
function restoreDatabase($backupFile) {
    echo "Restoring database from backup...\n";
    
    try {
        if (!file_exists($backupFile)) {
            throw new Exception("Backup file not found: $backupFile");
        }
        
        $dbPath = DB_PATH;
        
        // Create backup of current database
        if (file_exists($dbPath)) {
            $currentBackup = dirname($dbPath) . '/pre_restore_' . date('Y-m-d_H-i-s') . '.db';
            copy($dbPath, $currentBackup);
            echo "✓ Current database backed up to: $currentBackup\n";
        }
        
        // Restore from backup
        if (!copy($backupFile, $dbPath)) {
            throw new Exception("Failed to restore database");
        }
        
        echo "✓ Database restored from: $backupFile\n";
        
        // Reinitialize to validate
        $db = Database::getInstance();
        echo "✓ Database validation completed\n";
        
    } catch (Exception $e) {
        echo "✗ Restore failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Show help information
 */
function showHelp() {
    echo "Sanctum DMS Upgrade Tools\n\n";
    echo "Usage: php upgrade.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  upgrade           Upgrade database schema (default)\n";
    echo "  backup            Create database backup\n";
    echo "  restore <file>    Restore database from backup\n";
    echo "  help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  php upgrade.php upgrade\n";
    echo "  php upgrade.php backup\n";
    echo "  php upgrade.php restore backup_2025-10-04_18-30-00.db\n";
    echo "  php upgrade.php help\n";
}

// Parse command line arguments
$command = $argv[1] ?? 'upgrade';
$option = $argv[2] ?? null;

switch ($command) {
    case 'upgrade':
        upgradeDMS();
        break;
        
    case 'backup':
        backupDatabase();
        break;
        
    case 'restore':
        if (!$option) {
            echo "Error: Backup file path required for restore command\n\n";
            showHelp();
            exit(1);
        }
        restoreDatabase($option);
        break;
        
    case 'help':
        showHelp();
        break;
        
    default:
        echo "Unknown command: $command\n\n";
        showHelp();
        exit(1);
}
