<?php
/**
 * DMS CLI Initialization Script
 * Initialize database and create default admin user
 */

// Allow CLI execution
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

// Define DMS initialization flag
define('DMS_INITIALIZED', true);
define('DMS_TESTING', false);

// Load core components
require_once __DIR__ . '/public/includes/config.php';
require_once __DIR__ . '/public/includes/database.php';
require_once __DIR__ . '/public/includes/auth.php';

/**
 * Initialize DMS database and create default admin user
 */
function initializeDMS() {
    echo "Initializing Sanctum DMS...\n";
    
    try {
        // Initialize database (this will create schema)
        echo "Initializing database...\n";
        $db = Database::getInstance();
        echo "✓ Database initialized successfully\n";
        
        // Check if admin user exists
        $adminExists = $db->fetchOne("SELECT id FROM users WHERE role = 'admin'");
        
        if ($adminExists) {
            echo "✓ Admin user already exists\n";
        } else {
            echo "Creating default admin user...\n";
            
            // Create default admin user
            $auth = new Auth();
            $adminData = $auth->createUser(
                'admin',
                'admin@sanctum-dms.local',
                'admin123',
                'admin'
            );
            
            echo "✓ Admin user created:\n";
            echo "  Username: admin\n";
            echo "  Password: admin123\n";
            echo "  API Key: " . $adminData['api_key'] . "\n";
            echo "  ⚠️  Please change the default password immediately!\n";
        }
        
        // Display database stats
        $stats = $db->getStats();
        echo "\nDatabase Statistics:\n";
        foreach ($stats as $table => $count) {
            if ($table !== 'file_size') {
                echo "  $table: $count records\n";
            }
        }
        
        echo "\n✓ DMS initialization completed successfully!\n";
        echo "You can now start the web server and access the API.\n";
        
    } catch (Exception $e) {
        echo "✗ Initialization failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Show help information
 */
function showHelp() {
    echo "Sanctum DMS CLI Tools\n\n";
    echo "Usage: php init.php [command]\n\n";
    echo "Commands:\n";
    echo "  init     Initialize database and create admin user (default)\n";
    echo "  help     Show this help message\n\n";
    echo "Examples:\n";
    echo "  php init.php init\n";
    echo "  php init.php help\n";
}

// Parse command line arguments
$command = $argv[1] ?? 'init';

switch ($command) {
    case 'init':
        initializeDMS();
        break;
        
    case 'help':
        showHelp();
        break;
        
    default:
        echo "Unknown command: $command\n\n";
        showHelp();
        exit(1);
}
