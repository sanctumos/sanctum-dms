<?php
/**
 * Database Unit Tests
 * Test database functionality and schema management
 */

require_once __DIR__ . '/../bootstrap.php';

class DatabaseTest extends BaseTest {
    
    public function testDatabaseInitialization() {
        $this->assertNotNull($this->db, 'Database instance should be created');
        
        // Test database connection
        $result = $this->db->fetchOne("SELECT 1 as test");
        $this->assertEquals(1, $result['test'], 'Database should be connected');
    }
    
    public function testSchemaCreation() {
        // Check that all required tables exist
        $requiredTables = ['users', 'dealers', 'vehicles', 'customers', 'sales', 'schema_version'];
        
        foreach ($requiredTables as $table) {
            $result = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [':name' => $table]);
            $this->assertNotNull($result, "Table '$table' should exist");
        }
    }
    
    public function testSchemaVersionTracking() {
        // Check schema version table
        $result = $this->db->fetchOne("SELECT version FROM schema_version ORDER BY applied_at DESC LIMIT 1");
        $this->assertNotNull($result, 'Schema version should be tracked');
        $this->assertEquals(CURRENT_SCHEMA_VERSION, $result['version'], 'Schema version should match current version');
    }
    
    public function testForeignKeyConstraints() {
        // Test that foreign key constraints are enabled
        $result = $this->db->fetchOne("PRAGMA foreign_keys");
        $this->assertEquals(1, $result['foreign_keys'], 'Foreign key constraints should be enabled');
    }
    
    public function testTransactionSupport() {
        // Test transaction functionality
        $this->db->beginTransaction();
        
        try {
            // Insert test data
            $stmt = $this->db->prepare("INSERT INTO dealers (name, code) VALUES (?, ?)");
            $stmt->bindValue(1, 'Test Dealer', SQLITE3_TEXT);
            $stmt->bindValue(2, 'TEST001', SQLITE3_TEXT);
            $stmt->execute();
            
            $dealerId = $this->db->lastInsertRowID();
            $this->assertNotNull($dealerId, 'Dealer should be inserted');
            
            // Rollback transaction
            $this->db->rollback();
            
            // Verify rollback worked
            $result = $this->db->fetchOne("SELECT id FROM dealers WHERE code = ?", [':code' => 'TEST001']);
            $this->assertFalse($result, 'Dealer should not exist after rollback');
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function testPreparedStatements() {
        // Test prepared statement functionality
        $stmt = $this->db->prepare("INSERT INTO dealers (name, code, email) VALUES (?, ?, ?)");
        $stmt->bindValue(1, 'Test Dealer 2', SQLITE3_TEXT);
        $stmt->bindValue(2, 'TEST002', SQLITE3_TEXT);
        $stmt->bindValue(3, 'test2@dealer.local', SQLITE3_TEXT);
        $stmt->execute();
        
        $dealerId = $this->db->lastInsertRowID();
        $this->assertNotNull($dealerId, 'Dealer should be inserted via prepared statement');
        
        // Verify data was inserted correctly
        $result = $this->db->fetchOne("SELECT * FROM dealers WHERE id = ?", [':id' => $dealerId]);
        $this->assertEquals('Test Dealer 2', $result['name'], 'Dealer name should match');
        $this->assertEquals('TEST002', $result['code'], 'Dealer code should match');
        $this->assertEquals('test2@dealer.local', $result['email'], 'Dealer email should match');
    }
    
    public function testDatabaseStats() {
        $stats = $this->db->getStats();
        
        $this->assertArrayHasKey('users', $stats, 'Stats should include users table');
        $this->assertArrayHasKey('dealers', $stats, 'Stats should include dealers table');
        $this->assertArrayHasKey('vehicles', $stats, 'Stats should include vehicles table');
        $this->assertArrayHasKey('file_size', $stats, 'Stats should include file size');
        
        $this->assertTrue($stats['file_size'] > 0, 'Database file should have size > 0');
    }
}
