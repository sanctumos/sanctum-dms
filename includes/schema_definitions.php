<?php
/**
 * DMS Schema Definitions
 * Canonical schema definitions for idempotent database management
 */

// Prevent direct access
if (!defined('DMS_INITIALIZED')) {
    die('Direct access not allowed');
}

/**
 * Core DMS Schema Definitions
 * Each table definition includes:
 * - Table structure
 * - Indexes
 * - Foreign key constraints
 * - Default values
 */
$DMS_SCHEMA_DEFINITIONS = [
    'schema_version' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'version' => 'VARCHAR(20) NOT NULL',
            'applied_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'description' => 'TEXT'
        ],
        'indexes' => [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_schema_version ON schema_version(version)'
        ]
    ],
    
    'users' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'VARCHAR(50) UNIQUE NOT NULL',
            'email' => 'VARCHAR(100) UNIQUE NOT NULL',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'role' => 'VARCHAR(20) DEFAULT "user"',
            'api_key' => 'VARCHAR(64) UNIQUE',
            'status' => 'VARCHAR(20) DEFAULT "active"',
            'last_login' => 'DATETIME',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
            'CREATE INDEX IF NOT EXISTS idx_users_api_key ON users(api_key)',
            'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)'
        ]
    ],
    
    'dealers' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'name' => 'VARCHAR(200) NOT NULL',
            'code' => 'VARCHAR(50) UNIQUE NOT NULL',
            'address' => 'TEXT',
            'phone' => 'VARCHAR(20)',
            'email' => 'VARCHAR(100)',
            'contact_person' => 'VARCHAR(100)',
            'status' => 'VARCHAR(20) DEFAULT "active"',
            'license_number' => 'VARCHAR(50)',
            'max_sales_per_year' => 'INTEGER DEFAULT 4',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_dealers_code ON dealers(code)',
            'CREATE INDEX IF NOT EXISTS idx_dealers_status ON dealers(status)',
            'CREATE INDEX IF NOT EXISTS idx_dealers_email ON dealers(email)'
        ]
    ],
    
    'vehicles' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'dealer_id' => 'INTEGER NOT NULL',
            'vin' => 'VARCHAR(17) UNIQUE NOT NULL',
            'make' => 'VARCHAR(50) NOT NULL',
            'model' => 'VARCHAR(50) NOT NULL',
            'year' => 'INTEGER NOT NULL',
            'color' => 'VARCHAR(50)',
            'price' => 'DECIMAL(10,2)',
            'cost' => 'DECIMAL(10,2)',
            'status' => 'VARCHAR(20) DEFAULT "available"',
            'mileage' => 'INTEGER',
            'condition' => 'VARCHAR(20) DEFAULT "good"',
            'notes' => 'TEXT',
            'date_added' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'margin' => 'DECIMAL(10,2) GENERATED ALWAYS AS (price - COALESCE(cost, 0)) STORED',
            'profit_class' => 'VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN (price - COALESCE(cost, 0)) > 5000 THEN "high" WHEN (price - COALESCE(cost, 0)) > 2000 THEN "medium" ELSE "low" END) STORED',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_vehicles_vin ON vehicles(vin)',
            'CREATE INDEX IF NOT EXISTS idx_vehicles_dealer_id ON vehicles(dealer_id)',
            'CREATE INDEX IF NOT EXISTS idx_vehicles_status ON vehicles(status)',
            'CREATE INDEX IF NOT EXISTS idx_vehicles_make_model ON vehicles(make, model)',
            'CREATE INDEX IF NOT EXISTS idx_vehicles_profit_class ON vehicles(profit_class)'
        ],
        'foreign_keys' => [
            'FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE'
        ]
    ],
    
    'customers' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'first_name' => 'VARCHAR(100) NOT NULL',
            'last_name' => 'VARCHAR(100) NOT NULL',
            'email' => 'VARCHAR(100)',
            'phone' => 'VARCHAR(20)',
            'address' => 'TEXT',
            'city' => 'VARCHAR(100)',
            'state' => 'VARCHAR(50)',
            'zip_code' => 'VARCHAR(20)',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'CREATE INDEX IF NOT EXISTS idx_customers_email ON customers(email)',
            'CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone)',
            'CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(last_name, first_name)'
        ]
    ],
    
    'sales' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'dealer_id' => 'INTEGER NOT NULL',
            'vehicle_id' => 'INTEGER NOT NULL',
            'customer_id' => 'INTEGER',
            'sale_price' => 'DECIMAL(10,2) NOT NULL',
            'sale_date' => 'DATE NOT NULL',
            'salesperson' => 'VARCHAR(100)',
            'commission' => 'DECIMAL(10,2)',
            'commission_rate' => 'DECIMAL(5,2)',
            'status' => 'VARCHAR(20) DEFAULT "completed"',
            'payment_method' => 'VARCHAR(50)',
            'notes' => 'TEXT',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'indexes' => [
            'CREATE INDEX IF NOT EXISTS idx_sales_dealer_id ON sales(dealer_id)',
            'CREATE INDEX IF NOT EXISTS idx_sales_vehicle_id ON sales(vehicle_id)',
            'CREATE INDEX IF NOT EXISTS idx_sales_customer_id ON sales(customer_id)',
            'CREATE INDEX IF NOT EXISTS idx_sales_date ON sales(sale_date)',
            'CREATE INDEX IF NOT EXISTS idx_sales_status ON sales(status)'
        ],
        'foreign_keys' => [
            'FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE',
            'FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE',
            'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL'
        ]
    ],
    
    'db_migrations' => [
        'columns' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'migration_name' => 'VARCHAR(100) NOT NULL',
            'applied_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'description' => 'TEXT',
            'checksum' => 'VARCHAR(64)'
        ],
        'indexes' => [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_migrations_name ON db_migrations(migration_name)'
        ]
    ]
];

/**
 * Schema version tracking
 */
define('CURRENT_SCHEMA_VERSION', '1.1.0');

/**
 * Get schema definitions
 */
function getSchemaDefinitions() {
    global $DMS_SCHEMA_DEFINITIONS;
    return $DMS_SCHEMA_DEFINITIONS;
}

/**
 * Get table definition
 */
function getTableDefinition($tableName) {
    $definitions = getSchemaDefinitions();
    return $definitions[$tableName] ?? null;
}

/**
 * Get all table names
 */
function getAllTableNames() {
    return array_keys(getSchemaDefinitions());
}

/**
 * Validate schema definition
 */
function validateSchemaDefinition($tableName, $definition) {
    $requiredKeys = ['columns'];
    $optionalKeys = ['indexes', 'foreign_keys'];
    
    foreach ($requiredKeys as $key) {
        if (!isset($definition[$key])) {
            throw new Exception("Table '$tableName' definition missing required key: $key");
        }
    }
    
    return true;
}
