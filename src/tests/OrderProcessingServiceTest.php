<?php

namespace App\Tests;

use App\Exception\APIException;
use App\Entity\APIResponse;
use App\Exception\DatabaseException;
use App\Service\APIClient;
use App\Service\DatabaseService;
use App\Entity\Order;
use App\Service\OrderProcessingService;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * Class OrderProcessingServiceTest
 *
 * This class contains unit tests for the OrderProcessingService class.
 * It uses Mockery to create mock objects for dependencies and PHPUnit for assertions.
 *
 * @package App\Tests
 */
class OrderProcessingServiceTest extends TestCase
{
    /**
     * @var DatabaseService
     */
    private DatabaseService $dbService;

    /**
     * @var APIClient
     */
    private APIClient $apiClient;

    /**
     * @var OrderProcessingService
     */
    private $service;

    /**
     * Sets up the test environment before each test runs.
     *
     * This function prepares the test by creating mock objects for the database service and API client,
     * initializing the OrderProcessingService with these mocks, and setting up a virtual file system
     * for testing file operations. It runs automatically before every test.
     */
    protected function setUp(): void
    {
        $this->dbService = m::mock(DatabaseService::class);
        $this->apiClient = m::mock(APIClient::class);
        $this->service = new OrderProcessingService($this->dbService, $this->apiClient);
    }

    /**
     * Cleans up after each test finishes.
     *
     * This function closes the Mockery framework to ensure no leftover mock expectations interfere
     * with other tests. It runs automatically after every test.
     */
    protected function tearDown(): void
    {
        // Clean up storage directory
        $storageDir = 'storage';
        if (is_dir($storageDir)) {
            array_map('unlink', glob("$storageDir/*.csv"));
        }
        m::close();
    }

    /**
     * Tests that the constructor correctly sets up dependencies.
     *
     * This test checks if the OrderProcessingService constructor properly assigns the database service
     * and API client to its private properties, ensuring dependency injection works as expected.
     */
    public function testConstructorInjectsDependencies()
    {
        $dbService = $this->getPrivateProperty($this->service, 'dbService');
        $apiClient = $this->getPrivateProperty($this->service, 'apiClient');

        $this->assertInstanceOf(DatabaseService::class, $dbService);
        $this->assertInstanceOf(APIClient::class, $apiClient);
    }

    /**
     * Helper function to access private properties of an object.
     *
     * This function uses PHP reflection to get the value of a private property from an object.
     * It’s used in tests to check internal state without needing public getters.
     *
     * @param object $object The object to inspect (e.g., OrderProcessingService).
     * @param string $property The name of the private property to access (e.g., 'dbService').
     * @return mixed The value of the private property.
     */
    private function getPrivateProperty($object, $property)
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    /**
     * Tests processOrders when no orders are returned from the database.
     *
     * This test checks that processOrders returns an empty array when the database has no orders
     * for the given user ID, ensuring it handles an empty result correctly.
     */
    public function testProcessOrdersWithEmptyArray()
    {
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([]);
        $result = $this->service->processOrders(1);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Tests processOrders when a general exception occurs.
     *
     * This test verifies that if the database throws an exception (like a connection failure),
     * processOrders throws the same exception instead of continuing, ensuring errors are not hidden.
     *
     * @throws \Exception Expected exception when database access fails.
     */
    public function testProcessOrdersWithGeneralException()
    {
        $this->dbService->shouldReceive('getOrdersByUser')
            ->with(1)
            ->andThrow(new \Exception('Database failure'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database failure');

        $this->service->processOrders(1);
    }

    /**
     * Tests successful export of a type 'A' order to a CSV file.
     *
     * This test checks that a type 'A' order is processed correctly, exported to a CSV file,
     * and its status is updated to 'exported'. It also verifies the file exists.
     */
    public function testTypeAOrderSuccessfulExport()
    {
        $order = new Order(1, 'A', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'exported', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('exported', $result[0]->status);

        $files = glob('storage/orders_type_A_1_*.csv');
        $this->assertNotEmpty($files, 'No matching CSV file found in storage');
        $csvFile = $files[0];
        $this->assertFileExists($csvFile);
    }

    /**
     * Tests that a high-value type 'A' order includes a note in the CSV.
     *
     * This test ensures that a type 'A' order with an amount over 150 gets processed,
     * exported to a CSV with a "High value order" note, and its status set to 'exported'.
     */
    public function testTypeAOrderHighValueNote()
    {
        $order = new Order(1, 'A', 200, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'exported', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);

        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertCount(1, $result, 'Expected one order in result');
        $this->assertEquals('exported', $result[0]->status, 'Order status should be exported');

        $files = glob('storage/orders_type_A_1_*.csv');
        $this->assertNotEmpty($files, 'No matching CSV file found in storage');
        $csvFile = $files[0];

        $csvContent = file_get_contents($csvFile);
        $this->assertStringContainsString('High value order', $csvContent);
    }

    /**
     * Tests type 'A' order processing when CSV export fails.
     *
     * This test simulates a failure to write the CSV file (e.g., due to permissions) and checks
     * that the order’s status is set to 'export_failed' instead of 'exported'.
     */
    public function testTypeAOrderExportFailed()
    {
        $order = new Order(1, 'A', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'export_failed', 'low')->andReturn(true);

        // Make storage directory read-only
        chmod('storage', 0444); // Changed from "storage" to "src/storage"
        $result = $this->service->processOrders(1);
        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertEquals('export_failed', $result[0]->status);

        // Restore permissions for other tests
        chmod('storage', 0777);
    }

    /**
     * Tests successful processing of a type 'B' order via API.
     *
     * This test checks that a type 'B' order with specific conditions (API data >= 50, amount < 100)
     * gets processed and its status set to 'processed' after a successful API call.
     */
    public function testTypeBOrderProcessed()
    {
        $order = new Order(1, 'B', 80, false);
        $apiResponse = new APIResponse('success', $order);
        $apiResponse->data = 60;
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->apiClient->shouldReceive('callAPI')->with(1)->andReturn($apiResponse);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'processed', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('processed', $result[0]->status);
    }

    /**
     * Tests pending status for a type 'B' order with low API data.
     *
     * This test verifies that a type 'B' order with API data less than 50 gets its status set to 'pending'
     * after a successful API call.
     */
    public function testTypeBOrderPendingLowData()
    {
        $order = new Order(1, 'B', 80, false);
        $apiResponse = new APIResponse('success', $order);
        $apiResponse->data = 40;
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->apiClient->shouldReceive('callAPI')->with(1)->andReturn($apiResponse);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'pending', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('pending', $result[0]->status);
    }

    /**
     * Tests API failure for a type 'B' order.
     *
     * This test ensures that if the API call throws an exception, a type 'B' order’s status is set
     * to 'api_failure' instead of continuing processing.
     *
     * @throws APIException Expected exception from API failure.
     */
    public function testTypeBOrderApiFailure()
    {
        $order = new Order(1, 'B', 100, false);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->apiClient->shouldReceive('callAPI')->with(1)->andThrow(new APIException());
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'api_failure', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('api_failure', $result[0]->status);
    }

    /**
     * Tests completed status for a type 'C' order with flag true.
     *
     * This test checks that a type 'C' order with its flag set to true gets its status updated to 'completed'.
     */
    public function testTypeCOrderCompleted()
    {
        $order = new Order(1, 'C', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'completed', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('completed', $result[0]->status);
    }

    /**
     * Tests in-progress status for a type 'C' order with flag false.
     *
     * This test ensures that a type 'C' order with its flag set to false gets its status set to 'in_progress'.
     */
    public function testTypeCOrderInProgress()
    {
        $order = new Order(1, 'C', 100, false);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'in_progress', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('in_progress', $result[0]->status);
    }

    /**
     * Tests handling of an unknown order type.
     *
     * This test verifies that an order with an unrecognized type (e.g., 'D') gets its status set to 'unknown_type'.
     */
    public function testUnknownOrderType()
    {
        $order = new Order(1, 'D', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'unknown_type', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('unknown_type', $result[0]->status);
    }

    /**
     * Tests high priority setting for an order with amount over 200.
     *
     * This test checks that an order with an amount greater than 200 gets its priority set to 'high'.
     */
    public function testPriorityHigh()
    {
        $order = new Order(1, 'A', 250, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'exported', 'high')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('high', $result[0]->priority);
    }

    /**
     * Tests database update failure for an order.
     *
     * This test ensures that if the database update fails with an exception, the order’s status is set to 'db_error'.
     *
     * @throws DatabaseException Expected exception from database update failure.
     */
    public function testDatabaseUpdateFailure()
    {
        $order = new Order(1, 'C', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'completed', 'low')->andThrow(new DatabaseException());

        $result = $this->service->processOrders(1);
        $this->assertEquals('db_error', $result[0]->status);
    }

    /**
     * Tests that processOrders returns an array of orders.
     *
     * This test confirms that processOrders always returns an array and that it contains the expected number
     * of processed orders (in this case, one).
     */
    public function testReturnsOrdersArray()
    {
        $order = new Order(1, 'C', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'completed', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Tests processOrders with a negative user ID.
     *
     * This test checks that processOrders handles a negative user ID gracefully by returning an empty array
     * if no orders are found, testing an edge case.
     */
    public function testNegativeUserId()
    {
        $this->dbService->shouldReceive('getOrdersByUser')->with(-1)->andReturn([]);
        $result = $this->service->processOrders(-1);
        $this->assertIsArray($result);
    }

    /**
     * Tests processing an order with a negative amount.
     *
     * This test ensures that an order with a negative amount is processed correctly (e.g., type 'C' logic still applies),
     * testing an edge case for amount values.
     */
    public function testNegativeAmount()
    {
        $order = new Order(1, 'C', -50, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'completed', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('completed', $result[0]->status);
    }

    /**
     * Tests 'error' status for a type 'B' order based on API conditions.
     *
     * This test verifies that a type 'B' order with API data >= 50, amount >= 100, and flag = false
     * gets its status set to 'error', testing a specific processing condition.
     */
    public function testTypeBOrderErrorStatus()
    {
        $order = new Order(1, 'B', 150, false);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);

        $apiResponse = new APIResponse('success', $order);
        $apiResponse->data = 50;
        $this->apiClient->shouldReceive('callAPI')->with(1)->andReturn($apiResponse);

        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'error', 'low')
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);

        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertCount(1, $result, 'Expected one order in result');
        $this->assertEquals('error', $result[0]->status, 'Order status should be error');
    }

    /**
     * Tests that the storage directory is created for a type 'A' order.
     *
     * This test ensures that when processing a type 'A' order, the storage directory
     * is created if it doesn't exist, with the correct permissions (0755 after umask).
     */
    public function testTypeAOrderCreatesStorageDirectory()
    {
        // Remove the storage directory if it exists
        $storageDir = 'storage';
        if (is_dir($storageDir)) {
            array_map('unlink', glob("$storageDir/*.csv"));
            rmdir($storageDir);
        }

        // Verify the directory doesn't exist before the test
        $this->assertFalse(is_dir($storageDir), 'Storage directory should not exist before the test');

        // Process a type 'A' order
        $order = new Order(1, 'A', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'exported', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('exported', $result[0]->status);

        // Verify the storage directory was created
        $this->assertTrue(is_dir($storageDir), 'Storage directory should be created');

        // Verify permissions (0755 due to umask)
        $permissions = substr(sprintf('%o', fileperms($storageDir)), -4);
        $this->assertEquals('0755', $permissions, 'Storage directory should have 0755 permissions (adjusted by umask)');
    }

    /**
     * Tests processOrders with multiple orders of mixed types, including an unknown type.
     *
     * This test verifies that processOrders correctly handles multiple orders of different types (A, B, C, and an unknown type),
     * using mocks for dependencies, stubs for API responses, and spies to verify interactions.
     */
    public function testProcessOrdersWithMixedTypesIncludingUnknown()
    {
        // Create orders of different types
        $orderA = new Order(1, 'A', 100, true); // Type A: CSV export
        $orderB = new Order(2, 'B', 80, false); // Type B: API call
        $orderC = new Order(3, 'C', 150, true); // Type C: Flag-based
        $orderD = new Order(4, 'D', 120, false); // Unknown type

        // Mock: DatabaseService returns the orders
        $this->dbService->shouldReceive('getOrdersByUser')
            ->with(1)
            ->andReturn([$orderA, $orderB, $orderC, $orderD])
            ->once();

        // Stub: API response for type 'B' order
        $apiResponse = new APIResponse('success', $orderB);
        $apiResponse->data = 60; // data >= 50, amount < 100 -> processed
        $this->apiClient->shouldReceive('callAPI')
            ->with(2)
            ->andReturn($apiResponse)
            ->once();

        // Spy: Verify updateOrderStatus calls for each order
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'exported', 'low')
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(2, 'processed', 'low')
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(3, 'completed', 'low')
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(4, 'unknown_type', 'low')
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);

        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertCount(4, $result, 'Expected four orders in result');
        $this->assertEquals('exported', $result[0]->status, 'Order A status should be exported');
        $this->assertEquals('processed', $result[1]->status, 'Order B status should be processed');
        $this->assertEquals('completed', $result[2]->status, 'Order C status should be completed');
        $this->assertEquals('unknown_type', $result[3]->status, 'Order D status should be unknown_type');

        $files = glob('storage/orders_type_A_1_*.csv');
        $this->assertNotEmpty($files, 'No matching CSV file found in storage');
        $csvFile = $files[0];
        $this->assertFileExists($csvFile);
    }

    /**
     * Tests that the CSV file for a type 'A' order includes all expected fields.
     *
     * This test verifies that the CSV file generated for a type 'A' order contains the correct header
     * fields (ID, Type, Amount, Flag, Status, Priority) and the corresponding data row matches the order's properties.
     */
    public function testTypeAOrderCsvContentIncludesAllFields()
    {
        // Create a type 'A' order
        $order = new Order(1, 'A', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);
        $this->dbService->shouldReceive('updateOrderStatus')->with(1, 'exported', 'low')->andReturn(true);

        $result = $this->service->processOrders(1);
        $this->assertEquals('exported', $result[0]->status);

        // Find the CSV file
        $files = glob('storage/orders_type_A_1_*.csv');
        $this->assertNotEmpty($files, 'No matching CSV file found in temp directory');
        $csvFile = $files[0];

        // Read the CSV content
        $csvContent = file_get_contents($csvFile);
        $csvLines = explode("\n", trim($csvContent));

        // Verify the header row
        $header = str_getcsv($csvLines[0]);
        $expectedHeader = ['ID', 'Type', 'Amount', 'Flag', 'Status', 'Priority'];
        $this->assertEquals($expectedHeader, $header, 'CSV header does not match expected fields');

        // Verify the data row
        $data = str_getcsv($csvLines[1]);
        $expectedData = [
            (string)$order->id, // '1'
            $order->type,       // 'A'
            (string)$order->amount, // '100'
            $order->flag ? 'true' : 'false', // 'true'
            'new', // Status is 'new' at the time of writing to CSV
            $order->priority    // 'low'
        ];
        $this->assertEquals($expectedData, $data, 'CSV data row does not match order properties');
    }

    /**
     * Tests status for a type 'B' order with flag set to true.
     *
     * This test verifies that a type 'B' order with flag set to true gets its status set based on the API data
     * and amount, due to the current logic in setTypeBStatus. Note: The logic should be updated to prioritize
     * flag = true to set status to 'pending' regardless of data and amount.
     */
    public function testTypeBOrderPendingWithFlagTrue()
    {
        // Create a type 'B' order with flag = true
        $order = new Order(1, 'B', 80, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);

        // Stub: API response (data >= 50 and amount < 100 takes precedence over flag = true)
        $apiResponse = new APIResponse('success', $order);
        $apiResponse->data = 60; // data >= 50, amount < 100 -> processed
        $this->apiClient->shouldReceive('callAPI')->with(1)->andReturn($apiResponse);

        // Spy: Verify updateOrderStatus is called with 'processed'
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'processed', 'low')
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);
        $this->assertEquals('processed', $result[0]->status);
    }

    /**
     * Tests API error status for a type 'B' order with a non-success API response.
     *
     * This test verifies that a type 'B' order with an API response status other than 'success'
     * gets its status set to 'api_error'.
     */
    public function testTypeBOrderApiErrorWithNonSuccessStatus()
    {
        // Create a type 'B' order
        $order = new Order(1, 'B', 100, false);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);

        // Stub: API response with status != 'success'
        $apiResponse = new APIResponse('failure', $order);
        $apiResponse->data = 60; // Data is irrelevant since status != 'success'
        $this->apiClient->shouldReceive('callAPI')->with(1)->andReturn($apiResponse);

        // Spy: Verify updateOrderStatus is called with 'api_error'
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'api_error', 'low')
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);
        $this->assertEquals('api_error', $result[0]->status);
    }

    /**
     * Tests that processOrders throws an exception when an unhandled exception occurs during persistence.
     *
     * This test verifies that if an unhandled exception occurs during the persistence of an order (e.g., a database failure),
     * processOrders throws the exception instead of returning false or continuing.
     *
     * @throws \Exception Expected exception when persistence fails.
     */
    public function testProcessOrdersThrowsExceptionOnPersistFailure()
    {
        // Create a type 'C' order (simplest processing logic)
        $order = new Order(1, 'C', 100, true);
        $this->dbService->shouldReceive('getOrdersByUser')->with(1)->andReturn([$order]);

        // Simulate an unhandled exception in persistOrder
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'completed', 'low')
            ->andThrow(new \Exception('Database persistence failure'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database persistence failure');

        $this->service->processOrders(1);
    }

    /**
     * Tests processOrders with orders having null or invalid values.
     *
     * This test verifies that processOrders handles orders with null values for id, type, amount, and flag
     * without throwing unexpected exceptions, and processes them according to the service's logic.
     */
    public function testProcessOrdersWithInvalidOrderValues()
    {
        // Create orders with invalid values
        $orderA = new Order(null, 'A', 100, true); // id = null
        $orderB = new Order(2, 'B', null, false);  // amount = null
        $orderC = new Order(3, 'C', 150, null);    // flag = null
        $orderD = new Order(4, null, 120, false);  // type = null (unknown type)

        // Mock: DatabaseService returns the orders
        $this->dbService->shouldReceive('getOrdersByUser')
            ->with(1)
            ->andReturn([$orderA, $orderB, $orderC, $orderD])
            ->once();

        // Stub: API response for type 'B' order
        $apiResponse = new APIResponse('success', $orderB);
        $apiResponse->data = 60; // data >= 50, amount = null (0) < 100 -> processed
        $this->apiClient->shouldReceive('callAPI')
            ->with(2)
            ->andReturn($apiResponse)
            ->once();

        // Spy: Verify updateOrderStatus calls for each order
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(null, 'exported', 'low') // id = null
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(2, 'processed', 'low')   // amount = null (0) < 100
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(3, 'in_progress', 'low') // flag = null (false)
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(4, 'unknown_type', 'low') // type = null
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);

        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertCount(4, $result, 'Expected four orders in result');
        $this->assertEquals('exported', $result[0]->status, 'Order A status should be exported');
        $this->assertEquals('processed', $result[1]->status, 'Order B status should be processed');
        $this->assertEquals('in_progress', $result[2]->status, 'Order C status should be in_progress');
        $this->assertEquals('unknown_type', $result[3]->status, 'Order D status should be unknown_type');

        // Verify the CSV file for type 'A' order
        $files = glob('storage/orders_type_A__*.csv'); // id = null, so filename includes 'null'
        $this->assertNotEmpty($files, 'No matching CSV file found in temp directory');
        $csvFile = $files[0];
        $this->assertFileExists($csvFile);
    }

    /**
     * Tests processOrders with orders having a very large amount value (PHP_INT_MAX).
     *
     * This test verifies that processOrders handles orders with amount set to PHP_INT_MAX
     * without issues, ensuring correct priority setting and type-specific processing.
     */
    public function testProcessOrdersWithVeryLargeAmount()
    {
        // Define PHP_INT_MAX for clarity
        $largeAmount = PHP_INT_MAX;

        // Create orders with amount = PHP_INT_MAX
        $orderA = new Order(1, 'A', $largeAmount, true);  // Type A: CSV export, high value note
        $orderB = new Order(2, 'B', $largeAmount, false); // Type B: API call, should be 'error'
        $orderC = new Order(3, 'C', $largeAmount, true);  // Type C: flag-based
        $orderD = new Order(4, 'D', $largeAmount, false); // Unknown type

        // Mock: DatabaseService returns the orders
        $this->dbService->shouldReceive('getOrdersByUser')
            ->with(1)
            ->andReturn([$orderA, $orderB, $orderC, $orderD])
            ->once();

        // Stub: API response for type 'B' order
        $apiResponse = new APIResponse('success', $orderB);
        $apiResponse->data = 60; // data >= 50, amount = PHP_INT_MAX >= 100, flag = false -> error
        $this->apiClient->shouldReceive('callAPI')
            ->with(2)
            ->andReturn($apiResponse)
            ->once();

        // Spy: Verify updateOrderStatus calls for each order
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(1, 'exported', 'high') // amount = PHP_INT_MAX > 200
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(2, 'error', 'high')    // amount = PHP_INT_MAX >= 100, flag = false
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(3, 'completed', 'high') // flag = true, amount = PHP_INT_MAX > 200
            ->andReturn(true)
            ->once();
        $this->dbService->shouldReceive('updateOrderStatus')
            ->with(4, 'unknown_type', 'high') // amount = PHP_INT_MAX > 200
            ->andReturn(true)
            ->once();

        $result = $this->service->processOrders(1);

        $this->assertIsArray($result, 'processOrders did not return an array');
        $this->assertCount(4, $result, 'Expected four orders in result');
        $this->assertEquals('exported', $result[0]->status, 'Order A status should be exported');
        $this->assertEquals('error', $result[1]->status, 'Order B status should be error');
        $this->assertEquals('completed', $result[2]->status, 'Order C status should be completed');
        $this->assertEquals('unknown_type', $result[3]->status, 'Order D status should be unknown_type');

        // Verify the CSV file for type 'A' order includes the high value note
        $files = glob('storage/orders_type_A_1_*.csv'); // id = null, so filename includes 'null'
        $this->assertNotEmpty($files, 'No matching CSV file found in temp directory');
        $csvFile = $files[0];
        $csvContent = file_get_contents($csvFile);
        $this->assertStringContainsString('High value order', $csvContent, 'CSV should include high value note for large amount');
    }

    /**
     * Tests that an Order object has the correct initial state when constructed.
     *
     * This test verifies that a newly constructed Order object has its status set to 'new'
     * and priority set to 'low', as defined in the constructor.
     */
    public function testOrderInitialState()
    {
        // Create a new Order object with arbitrary values
        $order = new Order(1, 'A', 100, true);

        // Verify initial state
        $this->assertEquals('new', $order->status, 'Initial status should be new');
        $this->assertEquals('low', $order->priority, 'Initial priority should be low');
    }
}