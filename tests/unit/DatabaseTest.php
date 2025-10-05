<?php
/**
 * Unit Tests - Database Layer
 * Tests the core database functionality, connection, and query execution
 */

require_once __DIR__ . '/../bootstrap.php';

class DatabaseTest extends BaseTest {
    protected $db;
    protected $testDbPath;

    public function setUp(): void {
        $this->testDbPath = __DIR__ . '/../fixtures/test_database.db';
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        
        // Override database path for testing
        if (!defined('DB_PATH')) {
            define('DB_PATH', $this->testDbPath);
        }
        
        $this->db = Database::getInstance();
        $this->db->initializeSchema();
    }

    public function tearDown(): void {
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
    }

    public function testDatabaseConnection() {
        $this->assertInstanceOf(Database::class, $this->db);
        $this->assertTrue($this->db->isConnected());
    }

    public function testSchemaInitialization() {
        $tables = $this->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table'");
        $tableNames = array_column($tables, 'name');
        
        $expectedTables = ['schema_version', 'users', 'dealers', 'vehicles', 'customers', 'sales', 'db_migrations'];
        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tableNames, "Table $table should exist");
        }
    }

    public function testPreparedStatements() {
        // Test named parameters
        $uniqueId = time();
        $this->db->execute("INSERT INTO dealers (name, code, email) VALUES (:name, :code, :email)", [
            ':name' => "Test Dealer {$uniqueId}",
            ':code' => "TEST{$uniqueId}",
            ':email' => "test{$uniqueId}@example.com"
        ]);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = :code", [':code' => "TEST{$uniqueId}"]);
        $this->assertEquals("Test Dealer {$uniqueId}", $dealer['name']);
        $this->assertEquals("TEST{$uniqueId}", $dealer['code']);
        $this->assertEquals("test{$uniqueId}@example.com", $dealer['email']);
    }

    public function testPositionalParameters() {
        $uniqueId = time() + 1;
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Test Dealer {$uniqueId}", "TEST{$uniqueId}"]);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ["TEST{$uniqueId}"]);
        $this->assertEquals("Test Dealer {$uniqueId}", $dealer['name']);
    }

    public function testFetchAll() {
        $uniqueId = time();
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Dealer 1 {$uniqueId}", "D001{$uniqueId}"]);
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Dealer 2 {$uniqueId}", "D002{$uniqueId}"]);
        
        $dealers = $this->db->fetchAll("SELECT * FROM dealers WHERE code LIKE ? ORDER BY code", ["D%{$uniqueId}"]);
        $this->assertCount(2, $dealers);
        $this->assertEquals("D001{$uniqueId}", $dealers[0]['code']);
        $this->assertEquals("D002{$uniqueId}", $dealers[1]['code']);
    }

    public function testTransactionSupport() {
        $uniqueId = time();
        $this->db->beginTransaction();
        
        try {
            $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Trans Dealer {$uniqueId}", "TRANS{$uniqueId}"]);
            $dealerId = $this->db->fetchOne("SELECT id FROM dealers WHERE code = ?", ["TRANS{$uniqueId}"])['id'];
            $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year) VALUES (?, ?, ?, ?, ?)", 
                [$dealerId, "VIN{$uniqueId}123456789012", 'Toyota', 'Camry', 2020]);
            
            $this->db->commit();
            
            $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ["TRANS{$uniqueId}"]);
            $vehicle = $this->db->fetchOne("SELECT * FROM vehicles WHERE vin = ?", ["VIN{$uniqueId}123456789012"]);
            
            $this->assertNotNull($dealer);
            $this->assertNotNull($vehicle);
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function testTransactionRollback() {
        $uniqueId = time();
        $this->db->beginTransaction();
        
        try {
            $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Rollback Dealer {$uniqueId}", "ROLL{$uniqueId}"]);
            
            // Force an error by inserting duplicate code
            $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Duplicate Dealer {$uniqueId}", "ROLL{$uniqueId}"]);
            
            $this->fail('Expected unique constraint error');
            
        } catch (Exception $e) {
            $this->db->rollback();
            
            // Verify rollback worked - dealer should not exist
            $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ["ROLL{$uniqueId}"]);
            $this->assertFalse($dealer);
        }
    }

    public function testComputedColumns() {
        $uniqueId = time() + 4;
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["Test Dealer {$uniqueId}", "COMP{$uniqueId}"]);
        $dealerId = $this->db->lastInsertId();
        
        // Use a specific date to avoid non-deterministic julianday issues
        $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year, price, cost, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
            [$dealerId, "VIN{$uniqueId}123456789012", 'Toyota', 'Camry', 2020, 30000, 20000, '2023-01-01']);
        
        $vehicle = $this->db->fetchOne("SELECT *, margin, profit_class FROM vehicles WHERE vin = ?", ["VIN{$uniqueId}123456789012"]);
        
        $this->assertNotNull($vehicle['margin']);
        $this->assertEquals(10000, $vehicle['margin']);
        $this->assertEquals('high', $vehicle['profit_class']);
    }

    public function testForeignKeyConstraints() {
        // This should fail due to foreign key constraint
        $uniqueId = time() + 3;
        try {
            $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year) VALUES (?, ?, ?, ?, ?)", 
                [999, "VIN{$uniqueId}123456789012", 'Toyota', 'Camry', 2020]);
            $this->fail('Expected foreign key constraint error');
        } catch (Exception $e) {
            // Expected - foreign key constraint should prevent this
            $this->assertStringContains('constraint', $e->getMessage());
        }
    }

    public function testLastInsertId() {
        $uniqueId = time() + 2;
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ["ID Test Dealer {$uniqueId}", "ID{$uniqueId}"]);
        $id = $this->db->lastInsertId();
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE id = ?", [$id]);
        $this->assertEquals("ID Test Dealer {$uniqueId}", $dealer['name']);
    }
}