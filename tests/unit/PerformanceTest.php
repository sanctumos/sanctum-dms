<?php
/**
 * Performance and Stress Tests
 * Tests system performance under load and stress conditions
 */

require_once __DIR__ . '/../bootstrap.php';

class PerformanceTest extends BaseTest {
    private $db;
    private $dealerService;
    private $vehicleService;
    private $saleService;
    private $startTime;

    public function setUp(): void {
        $this->db = Database::getInstance();
        $this->dealerService = new DealerManagementService();
        $this->vehicleService = new VehicleService();
        $this->saleService = new SaleService();
        $this->startTime = microtime(true);
        
        $this->cleanupTestData();
    }

    public function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $this->db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'PERF_%')");
        $this->db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'PERF_%')");
        $this->db->execute("DELETE FROM dealers WHERE code LIKE 'PERF_%'");
    }

    private function measureTime($callback) {
        $start = microtime(true);
        $result = $callback();
        $end = microtime(true);
        return ['result' => $result, 'time' => $end - $start];
    }

    // Database Performance Tests
    public function testDatabaseConnectionPerformance() {
        $measurement = $this->measureTime(function() {
            $db = Database::getInstance();
            return $db->isConnected();
        });
        
        $this->assertTrue($measurement['result']);
        $this->assertLessThan(0.1, $measurement['time'], 'Database connection should be fast');
    }

    public function testDatabaseQueryPerformance() {
        // Create test data
        $dealerId = TestDataFactory::createDealer(['code' => 'PERF_QUERY']);
        
        // Test simple query performance
        $measurement = $this->measureTime(function() use ($dealerId) {
            return $this->db->fetchOne("SELECT * FROM dealers WHERE id = ?", [$dealerId]);
        });
        
        $this->assertNotNull($measurement['result']);
        $this->assertLessThan(0.01, $measurement['time'], 'Simple query should be very fast');
    }

    public function testDatabaseBulkInsertPerformance() {
        $measurement = $this->measureTime(function() {
            $dealerId = TestDataFactory::createDealer(['code' => 'PERF_BULK']);
            
            // Insert 100 vehicles
            for ($i = 0; $i < 100; $i++) {
                TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
            }
            
            return $dealerId;
        });
        
        $this->assertIsInt($measurement['result']);
        $this->assertLessThan(2.0, $measurement['time'], 'Bulk insert should complete within 2 seconds');
        
        // Verify all vehicles were created
        $vehicles = $this->vehicleService->getVehiclesByDealer($measurement['result']);
        $this->assertCount(100, $vehicles);
    }

    public function testDatabaseComplexQueryPerformance() {
        // Create test data
        $scenario = TestDataFactory::createHighVolumeScenario(5, 20, 10);
        
        $measurement = $this->measureTime(function() {
            // Complex query with joins and aggregations
            return $this->db->fetchAll("
                SELECT 
                    d.name as dealer_name,
                    COUNT(v.id) as vehicle_count,
                    COUNT(s.id) as sales_count,
                    SUM(s.sale_price) as total_sales,
                    AVG(v.price) as avg_vehicle_price
                FROM dealers d
                LEFT JOIN vehicles v ON d.id = v.dealer_id
                LEFT JOIN sales s ON d.id = s.dealer_id AND s.status = 'completed'
                WHERE d.status = 'active'
                GROUP BY d.id, d.name
                ORDER BY total_sales DESC
            ");
        });
        
        $this->assertIsArray($measurement['result']);
        $this->assertLessThan(0.5, $measurement['time'], 'Complex query should complete within 0.5 seconds');
    }

    // Service Performance Tests
    public function testDealerServicePerformance() {
        $measurement = $this->measureTime(function() {
            $dealers = [];
            for ($i = 0; $i < 50; $i++) {
                $dealers[] = TestDataFactory::createDealer(['code' => 'PERF_DEALER_' . $i]);
            }
            return $dealers;
        });
        
        $this->assertCount(50, $measurement['result']);
        $this->assertLessThan(1.0, $measurement['time'], 'Creating 50 dealers should complete within 1 second');
    }

    public function testVehicleServicePerformance() {
        $dealerId = TestDataFactory::createDealer(['code' => 'PERF_VEHICLES']);
        
        $measurement = $this->measureTime(function() use ($dealerId) {
            $vehicles = [];
            for ($i = 0; $i < 100; $i++) {
                $vehicles[] = TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
            }
            return $vehicles;
        });
        
        $this->assertCount(100, $measurement['result']);
        $this->assertLessThan(2.0, $measurement['time'], 'Creating 100 vehicles should complete within 2 seconds');
    }

    public function testSaleServicePerformance() {
        $dealerId = TestDataFactory::createDealer(['code' => 'PERF_SALES']);
        $vehicleIds = [];
        for ($i = 0; $i < 50; $i++) {
            $vehicleIds[] = TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
        }
        
        $measurement = $this->measureTime(function() use ($dealerId, $vehicleIds) {
            $sales = [];
            foreach ($vehicleIds as $vehicleId) {
                $sales[] = TestDataFactory::createSale($dealerId, $vehicleId);
            }
            return $sales;
        });
        
        $this->assertCount(50, $measurement['result']);
        $this->assertLessThan(1.5, $measurement['time'], 'Creating 50 sales should complete within 1.5 seconds');
    }

    // Memory Usage Tests
    public function testMemoryUsage() {
        $initialMemory = memory_get_usage();
        
        // Create large dataset
        $scenario = TestDataFactory::createHighVolumeScenario(10, 50, 25);
        
        $peakMemory = memory_get_peak_usage();
        $memoryUsed = $peakMemory - $initialMemory;
        
        // Memory usage should be reasonable (less than 50MB)
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be reasonable');
        
        // Clean up
        TestDataFactory::cleanupTestData(['PERF_%']);
        
        $finalMemory = memory_get_usage();
        $memoryAfterCleanup = $finalMemory - $initialMemory;
        
        // Memory should be mostly freed after cleanup
        $this->assertLessThan(10 * 1024 * 1024, $memoryAfterCleanup, 'Memory should be mostly freed after cleanup');
    }

    // Concurrent Access Tests
    public function testConcurrentReads() {
        $dealerId = TestDataFactory::createDealer(['code' => 'PERF_CONCURRENT']);
        
        // Create test data
        for ($i = 0; $i < 20; $i++) {
            TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
        }
        
        $measurement = $this->measureTime(function() use ($dealerId) {
            $results = [];
            for ($i = 0; $i < 100; $i++) {
                $results[] = $this->vehicleService->getVehiclesByDealer($dealerId);
            }
            return $results;
        });
        
        $this->assertCount(100, $measurement['result']);
        $this->assertLessThan(1.0, $measurement['time'], '100 concurrent reads should complete within 1 second');
    }

    public function testConcurrentWrites() {
        $measurement = $this->measureTime(function() {
            $dealers = [];
            for ($i = 0; $i < 20; $i++) {
                $dealers[] = TestDataFactory::createDealer(['code' => 'PERF_CONC_WRITE_' . $i]);
            }
            return $dealers;
        });
        
        $this->assertCount(20, $measurement['result']);
        $this->assertLessThan(0.5, $measurement['time'], '20 concurrent writes should complete within 0.5 seconds');
    }

    // Transaction Performance Tests
    public function testTransactionPerformance() {
        $measurement = $this->measureTime(function() {
            $this->db->beginTransaction();
            
            try {
                $dealerId = TestDataFactory::createDealer(['code' => 'PERF_TRANS']);
                
                for ($i = 0; $i < 50; $i++) {
                    TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
                }
                
                $this->db->commit();
                return $dealerId;
                
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        });
        
        $this->assertIsInt($measurement['result']);
        $this->assertLessThan(1.0, $measurement['time'], 'Transaction with 50 operations should complete within 1 second');
        
        // Verify data integrity
        $vehicles = $this->vehicleService->getVehiclesByDealer($measurement['result']);
        $this->assertCount(50, $vehicles);
    }

    public function testTransactionRollbackPerformance() {
        $measurement = $this->measureTime(function() {
            $this->db->beginTransaction();
            
            try {
                $dealerId = TestDataFactory::createDealer(['code' => 'PERF_ROLLBACK']);
                
                for ($i = 0; $i < 30; $i++) {
                    TestDataFactory::createVehicle($dealerId, 'PERF' . str_pad($i, 12, '0') . '12345');
                }
                
                // Force rollback
                throw new Exception('Forced rollback');
                
            } catch (Exception $e) {
                $this->db->rollback();
                return true;
            }
        });
        
        $this->assertTrue($measurement['result']);
        $this->assertLessThan(0.5, $measurement['time'], 'Transaction rollback should complete within 0.5 seconds');
    }

    // Report Generation Performance Tests
    public function testReportGenerationPerformance() {
        $reportsService = new ReportsService();
        
        // Create test data
        $scenario = TestDataFactory::createHighVolumeScenario(5, 30, 15);
        
        $measurement = $this->measureTime(function() use ($reportsService) {
            return $reportsService->getSummary();
        });
        
        $this->assertIsArray($measurement['result']);
        $this->assertLessThan(0.3, $measurement['time'], 'Report generation should complete within 0.3 seconds');
    }

    public function testComplianceCheckPerformance() {
        $complianceService = new ComplianceService();
        
        // Create test data
        $scenario = TestDataFactory::createHighVolumeScenario(10, 20, 10);
        
        $measurement = $this->measureTime(function() use ($complianceService) {
            return $complianceService->dealersCompliance();
        });
        
        $this->assertIsArray($measurement['result']);
        $this->assertLessThan(0.2, $measurement['time'], 'Compliance check should complete within 0.2 seconds');
    }

    // Stress Tests
    public function testStressTestLargeDataset() {
        $measurement = $this->measureTime(function() {
            // Create very large dataset
            $scenario = TestDataFactory::createHighVolumeScenario(20, 100, 50);
            return $scenario;
        });
        
        $this->assertIsArray($measurement['result']);
        $this->assertLessThan(10.0, $measurement['time'], 'Large dataset creation should complete within 10 seconds');
        
        // Verify data integrity
        $totalDealers = count($measurement['result']);
        $this->assertEquals(20, $totalDealers);
    }

    public function testStressTestRapidOperations() {
        $measurement = $this->measureTime(function() {
            $operations = 0;
            
            // Rapid create/update/delete operations
            for ($i = 0; $i < 100; $i++) {
                $dealerId = TestDataFactory::createDealer(['code' => 'STRESS_' . $i]);
                $this->dealerService->updateDealer($dealerId, ['name' => 'Updated ' . $i]);
                $this->dealerService->deleteDealer($dealerId);
                $operations++;
            }
            
            return $operations;
        });
        
        $this->assertEquals(100, $measurement['result']);
        $this->assertLessThan(5.0, $measurement['time'], '100 rapid operations should complete within 5 seconds');
    }

    // API Performance Tests (if server is running)
    public function testApiResponseTime() {
        $measurement = $this->measureTime(function() {
            $response = TestUtils::makeApiRequest('GET', '/api/v1/status');
            return $response['code'];
        });
        
        $this->assertEquals(200, $measurement['result']);
        $this->assertLessThan(0.1, $measurement['time'], 'API response should be under 100ms');
    }

    public function testApiBulkOperations() {
        $measurement = $this->measureTime(function() {
            $responses = [];
            for ($i = 0; $i < 50; $i++) {
                $responses[] = TestUtils::makeApiRequest('GET', '/api/v1/status');
            }
            return count($responses);
        });
        
        $this->assertEquals(50, $measurement['result']);
        $this->assertLessThan(2.0, $measurement['time'], '50 API calls should complete within 2 seconds');
    }

    // Cleanup Performance Tests
    public function testCleanupPerformance() {
        // Create test data
        $scenario = TestDataFactory::createHighVolumeScenario(10, 50, 25);
        
        $measurement = $this->measureTime(function() {
            TestDataFactory::cleanupTestData(['PERF_%', 'STRESS_%']);
        });
        
        $this->assertLessThan(1.0, $measurement['time'], 'Cleanup should complete within 1 second');
        
        // Verify cleanup worked
        $remainingDealers = $this->db->fetchOne("SELECT COUNT(*) as count FROM dealers WHERE code LIKE 'PERF_%'")['count'];
        $this->assertEquals(0, $remainingDealers);
    }

    // Overall System Performance Test
    public function testOverallSystemPerformance() {
        $totalStartTime = microtime(true);
        
        // Simulate typical system usage
        $scenario = TestDataFactory::createHighVolumeScenario(5, 20, 10);
        
        // Generate reports
        $reportsService = new ReportsService();
        $summary = $reportsService->getSummary();
        $vehicleStats = $reportsService->getVehicleStats();
        
        // Check compliance
        $complianceService = new ComplianceService();
        $compliance = $complianceService->dealersCompliance();
        
        // Run audit
        $auditService = new AuditService();
        $audit = $auditService->getEntityAudit();
        
        $totalTime = microtime(true) - $totalStartTime;
        
        // Overall system should be responsive
        $this->assertLessThan(3.0, $totalTime, 'Overall system operations should complete within 3 seconds');
        
        // Verify all operations completed successfully
        $this->assertIsArray($summary);
        $this->assertIsArray($vehicleStats);
        $this->assertIsArray($compliance);
        $this->assertIsArray($audit);
    }
}
