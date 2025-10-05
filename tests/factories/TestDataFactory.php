<?php
/**
 * Test Data Factory
 * Creates test data for all test suites
 */

class TestDataFactory {
    private static $db;

    public static function init() {
        self::$db = Database::getInstance();
    }

    public static function createDealer($overrides = []) {
        $defaults = [
            'name' => 'Test Dealer ' . uniqid(),
            'code' => 'TEST_' . uniqid(),
            'email' => 'test@dealer.com',
            'phone' => '555-0123',
            'address' => '123 Test Street',
            'max_sales_per_year' => 4,
            'status' => 'active'
        ];

        $data = array_merge($defaults, $overrides);
        
        $sql = "INSERT INTO dealers (name, code, email, phone, address, max_sales_per_year, status) 
                VALUES (:name, :code, :email, :phone, :address, :max_sales_per_year, :status)";
        
        self::$db->execute($sql, $data);
        return self::$db->lastInsertId();
    }

    public static function createVehicle($dealerId, $overrides = []) {
        $defaults = [
            'dealer_id' => $dealerId,
            'vin' => 'VIN' . uniqid() . '12345',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'color' => 'Silver',
            'price' => 25000,
            'cost' => 20000,
            'mileage' => 50000,
            'condition' => 'good',
            'status' => 'available'
        ];

        $data = array_merge($defaults, $overrides);
        
        $sql = "INSERT INTO vehicles (dealer_id, vin, make, model, year, color, price, cost, mileage, condition, status) 
                VALUES (:dealer_id, :vin, :make, :model, :year, :color, :price, :cost, :mileage, :condition, :status)";
        
        self::$db->execute($sql, $data);
        return self::$db->lastInsertId();
    }

    public static function createCustomer($overrides = []) {
        $defaults = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-0123',
            'address' => '123 Customer Street',
            'city' => 'Test City',
            'state' => 'TS',
            'zip_code' => '12345'
        ];

        $data = array_merge($defaults, $overrides);
        
        $sql = "INSERT INTO customers (first_name, last_name, email, phone, address, city, state, zip_code) 
                VALUES (:first_name, :last_name, :email, :phone, :address, :city, :state, :zip_code)";
        
        self::$db->execute($sql, $data);
        return self::$db->lastInsertId();
    }

    public static function createSale($dealerId, $vehicleId, $overrides = []) {
        $defaults = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 25000,
            'sale_date' => date('Y-m-d'),
            'salesperson' => 'Test Salesperson',
            'commission_rate' => 0.05,
            'status' => 'completed',
            'payment_method' => 'cash'
        ];

        $data = array_merge($defaults, $overrides);
        
        // Calculate commission
        $data['commission'] = $data['sale_price'] * $data['commission_rate'];
        
        $sql = "INSERT INTO sales (dealer_id, vehicle_id, sale_price, sale_date, salesperson, commission, commission_rate, status, payment_method) 
                VALUES (:dealer_id, :vehicle_id, :sale_price, :sale_date, :salesperson, :commission, :commission_rate, :status, :payment_method)";
        
        self::$db->execute($sql, $data);
        
        // Update vehicle status to sold
        self::$db->execute("UPDATE vehicles SET status = 'sold' WHERE id = :id", [':id' => $vehicleId]);
        
        return self::$db->lastInsertId();
    }

    public static function createUser($overrides = []) {
        $defaults = [
            'username' => 'testuser_' . uniqid(),
            'email' => 'test@user.com',
            'password_hash' => password_hash('testpass123', PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => 'active'
        ];

        $data = array_merge($defaults, $overrides);
        
        // Generate API key if not provided
        if (!isset($data['api_key'])) {
            $data['api_key'] = bin2hex(random_bytes(32));
        }
        
        $sql = "INSERT INTO users (username, email, password_hash, role, api_key, status) 
                VALUES (:username, :email, :password_hash, :role, :api_key, :status)";
        
        self::$db->execute($sql, $data);
        return self::$db->lastInsertId();
    }

    public static function createCompleteDealerScenario($dealerOverrides = [], $vehicleCount = 3, $saleCount = 2) {
        // Create dealer
        $dealerId = self::createDealer($dealerOverrides);
        
        // Create vehicles
        $vehicleIds = [];
        for ($i = 0; $i < $vehicleCount; $i++) {
            $vehicleOverrides = [
                'vin' => 'VIN' . str_pad($i, 12, '0') . '12345',
                'make' => ['Toyota', 'Honda', 'Ford'][$i % 3],
                'model' => ['Camry', 'Civic', 'Focus'][$i % 3],
                'year' => 2020 + ($i % 2),
                'price' => 20000 + ($i * 5000),
                'cost' => 15000 + ($i * 4000)
            ];
            $vehicleIds[] = self::createVehicle($dealerId, $vehicleOverrides);
        }
        
        // Create sales
        $saleIds = [];
        for ($i = 0; $i < min($saleCount, $vehicleCount); $i++) {
            $saleOverrides = [
                'sale_price' => 20000 + ($i * 5000),
                'commission_rate' => 0.05 + ($i * 0.01)
            ];
            $saleIds[] = self::createSale($dealerId, $vehicleIds[$i], $saleOverrides);
        }
        
        return [
            'dealer_id' => $dealerId,
            'vehicle_ids' => $vehicleIds,
            'sale_ids' => $saleIds
        ];
    }

    public static function createHighVolumeScenario($dealerCount = 5, $vehiclesPerDealer = 10, $salesPerDealer = 3) {
        $scenarios = [];
        
        for ($i = 0; $i < $dealerCount; $i++) {
            $dealerOverrides = [
                'name' => 'High Volume Dealer ' . ($i + 1),
                'code' => 'HVD_' . str_pad($i, 3, '0'),
                'max_sales_per_year' => 10
            ];
            
            $scenario = self::createCompleteDealerScenario($dealerOverrides, $vehiclesPerDealer, $salesPerDealer);
            $scenarios[] = $scenario;
        }
        
        return $scenarios;
    }

    public static function createComplianceTestScenario() {
        // Create dealer with low sales limit
        $dealerId = self::createDealer(['max_sales_per_year' => 2]);
        
        // Create vehicles
        $vehicleIds = [];
        for ($i = 0; $i < 5; $i++) {
            $vehicleIds[] = self::createVehicle($dealerId, ['vin' => 'COMP' . str_pad($i, 12, '0') . '12345']);
        }
        
        // Create sales up to limit
        $saleIds = [];
        for ($i = 0; $i < 2; $i++) {
            $saleIds[] = self::createSale($dealerId, $vehicleIds[$i]);
        }
        
        return [
            'dealer_id' => $dealerId,
            'vehicle_ids' => $vehicleIds,
            'sale_ids' => $saleIds,
            'remaining_vehicles' => array_slice($vehicleIds, 2) // Vehicles that can't be sold due to limit
        ];
    }

    public static function createProfitClassTestScenario() {
        $dealerId = self::createDealer();
        
        $vehicles = [
            ['price' => 30000, 'cost' => 20000], // High profit
            ['price' => 25000, 'cost' => 22000], // Medium profit
            ['price' => 20000, 'cost' => 19000], // Low profit
            ['price' => 15000, 'cost' => 15000], // No profit
        ];
        
        $vehicleIds = [];
        foreach ($vehicles as $i => $vehicle) {
            $vehicleIds[] = self::createVehicle($dealerId, [
                'vin' => 'PROF' . str_pad($i, 12, '0') . '12345',
                'price' => $vehicle['price'],
                'cost' => $vehicle['cost']
            ]);
        }
        
        return [
            'dealer_id' => $dealerId,
            'vehicle_ids' => $vehicleIds
        ];
    }

    public static function cleanupTestData($pattern = 'TEST_%') {
        $patterns = is_array($pattern) ? $pattern : [$pattern];
        
        foreach ($patterns as $p) {
            self::$db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE :pattern)", [':pattern' => $p]);
            self::$db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE :pattern)", [':pattern' => $p]);
            self::$db->execute("DELETE FROM dealers WHERE code LIKE :pattern", [':pattern' => $p]);
        }
        
        // Clean up test users
        self::$db->execute("DELETE FROM users WHERE username LIKE 'testuser_%'");
        
        // Clean up test customers
        self::$db->execute("DELETE FROM customers WHERE email LIKE '%@example.com'");
    }

    public static function getTestDataStats() {
        $stats = [];
        
        $stats['dealers'] = self::$db->fetchOne("SELECT COUNT(*) as count FROM dealers WHERE code LIKE 'TEST_%'")['count'];
        $stats['vehicles'] = self::$db->fetchOne("SELECT COUNT(*) as count FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')")['count'];
        $stats['sales'] = self::$db->fetchOne("SELECT COUNT(*) as count FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')")['count'];
        $stats['users'] = self::$db->fetchOne("SELECT COUNT(*) as count FROM users WHERE username LIKE 'testuser_%'")['count'];
        
        return $stats;
    }
}

// Initialize the factory
TestDataFactory::init();
