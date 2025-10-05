<?php
/**
 * Unit Tests - Database Layer
 * Tests the core database functionality, connection, and query execution
 */

require_once __DIR__ . '/../bootstrap.php';

class DatabaseTest extends PHPUnit\Framework\TestCase {
    private $db;
    private $testDbPath;

    protected function setUp(): void {
        $this->testDbPath = __DIR__ . '/../fixtures/test_database.db';
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        
        // Override database path for testing
        define('DB_PATH', $this->testDbPath);
        
        $this->db = Database::getInstance();
        $this->db->initializeSchema();
    }

    protected function tearDown(): void {
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
        $this->db->execute("INSERT INTO dealers (name, code, email) VALUES (:name, :code, :email)", [
            ':name' => 'Test Dealer',
            ':code' => 'TEST001',
            ':email' => 'test@example.com'
        ]);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = :code", [':code' => 'TEST001']);
        $this->assertEquals('Test Dealer', $dealer['name']);
        $this->assertEquals('TEST001', $dealer['code']);
        $this->assertEquals('test@example.com', $dealer['email']);
    }

    public function testPositionalParameters() {
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Test Dealer 2', 'TEST002']);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ['TEST002']);
        $this->assertEquals('Test Dealer 2', $dealer['name']);
    }

    public function testFetchAll() {
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Dealer 1', 'D001']);
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Dealer 2', 'D002']);
        
        $dealers = $this->db->fetchAll("SELECT * FROM dealers ORDER BY code");
        $this->assertCount(2, $dealers);
        $this->assertEquals('D001', $dealers[0]['code']);
        $this->assertEquals('D002', $dealers[1]['code']);
    }

    public function testTransactionSupport() {
        $this->db->beginTransaction();
        
        try {
            $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Trans Dealer', 'TRANS001']);
            $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year) VALUES (?, ?, ?, ?, ?)", 
                [1, 'VIN123456789012345', 'Toyota', 'Camry', 2020]);
            
            $this->db->commit();
            
            $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ['TRANS001']);
            $vehicle = $this->db->fetchOne("SELECT * FROM vehicles WHERE vin = ?", ['VIN123456789012345']);
            
            $this->assertNotNull($dealer);
            $this->assertNotNull($vehicle);
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function testTransactionRollback() {
        $this->db->beginTransaction();
        
        try {
            $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Rollback Dealer', 'ROLL001']);
            
            // Force an error
            $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year) VALUES (?, ?, ?, ?, ?)", 
                [999, 'VIN123456789012345', 'Toyota', 'Camry', 2020]); // Invalid dealer_id
            
            $this->fail('Expected foreign key constraint error');
            
        } catch (Exception $e) {
            $this->db->rollback();
            
            // Verify rollback worked
            $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ['ROLL001']);
            $this->assertNull($dealer);
        }
    }

    public function testComputedColumns() {
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Test Dealer', 'COMP001']);
        $dealerId = $this->db->lastInsertId();
        
        $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year, price, cost, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
            [$dealerId, 'VIN123456789012345', 'Toyota', 'Camry', 2020, 25000, 20000, '2023-01-01']);
        
        $vehicle = $this->db->fetchOne("SELECT *, days_in_inventory, margin, profit_class FROM vehicles WHERE vin = ?", ['VIN123456789012345']);
        
        $this->assertNotNull($vehicle['days_in_inventory']);
        $this->assertEquals(5000, $vehicle['margin']);
        $this->assertEquals('high', $vehicle['profit_class']);
    }

    public function testForeignKeyConstraints() {
        // This should fail due to foreign key constraint
        $this->expectException(Exception::class);
        $this->db->execute("INSERT INTO vehicles (dealer_id, vin, make, model, year) VALUES (?, ?, ?, ?, ?)", 
            [999, 'VIN123456789012345', 'Toyota', 'Camry', 2020]);
    }

    public function testLastInsertId() {
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['ID Test Dealer', 'ID001']);
        $id = $this->db->lastInsertId();
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE id = ?", [$id]);
        $this->assertEquals('ID Test Dealer', $dealer['name']);
    }
}