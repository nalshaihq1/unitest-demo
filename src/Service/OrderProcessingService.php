<?php

namespace App\Service;

use App\Entity\Order;
use App\Exception\DatabaseException;
use App\Exception\APIException;

/**
 * Class OrderProcessingService
 *
 * This class handles the processing of orders for a user, including fetching orders from the database,
 * processing them based on their type, and updating their status and priority.
 *
 * @package App\Service
 */
class OrderProcessingService
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
     * @var object
     */
    private $fileSystem;

    /**
     * Sets up the OrderProcessingService with necessary dependencies.
     *
     * This constructor prepares the service by taking a database service and an API client.
     * It also allows an optional file system handler for writing files, defaulting to basic PHP file functions if none is provided.
     *
     * @param DatabaseService $dbService The service to interact with the database (e.g., fetch or update orders).
     * @param APIClient $apiClient The client to call an external API for order processing.
     * @param object|null $fileSystem An optional object to handle file operations (open, write, close). If not provided, uses default PHP functions.
     */
    public function __construct(DatabaseService $dbService, APIClient $apiClient, $fileSystem = null)
    {
        $this->dbService = $dbService;
        $this->apiClient = $apiClient;
        $this->fileSystem = $fileSystem ?? new class {
                public function open($filename, $mode) { return fopen($filename, $mode); }
                public function writeCsv($handle, $fields) { return fputcsv($handle, $fields); }
                public function close($handle) { return fclose($handle); }
            };
    }

    /**
     * Processes all orders for a given user and updates their status and priority.
     *
     * This function gets a list of orders for a user from the database, processes each one based on its type,
     * updates its priority, and saves the changes back to the database. It returns the updated list of orders.
     *
     * @param int $userId The ID of the user whose orders need processing.
     * @return array The list of orders after processing, with updated status and priority.
     * @throws \Exception If there's an issue fetching orders from the database or processing them.
     */
    public function processOrders(int $userId): array
    {
        $orders = $this->dbService->getOrdersByUser($userId);

        foreach ($orders as $order) {
            $order = $this->processOrder($order);
            $order = $this->updateOrderPriority($order);
            $this->persistOrder($order);
        }

        return $orders;
    }

    /**
     * Handles the processing of a single order based on its type.
     *
     * This function looks at the order's type (A, B, C, or something else) and calls the right method to process it.
     * If the type isn’t recognized, it marks the order as 'unknown_type'.
     *
     * @param Order $order The order object to process (must have a 'type' property).
     * @throws APIException
     */
    private function processOrder($order)
    {
        switch ($order->type) {
            case 'A':
                $order = $this->processTypeA($order);
                break;
            case 'B':
                $order = $this->processTypeB($order);
                break;
            case 'C':
                $order = $this->processTypeC($order);
                break;
            default:
                $order->status = 'unknown_type';
                break;
        }

        return $order;
    }

    /**
     * Processes an order of type 'A' by saving it to a CSV file.
     *
     * This function creates a CSV file with the order details. If the file can’t be created or written to,
     * it sets the order status to 'export_failed'. If successful, it sets the status to 'exported'.
     *
     * @param Order $order The order object to process (type 'A').
     * @return Order The updated order object with the new status.
     */
    private function processTypeA($order)
    {
        // Ensure the storage directory exists
        $storageDir = __DIR__ . '/../../storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $csvFile = "orders_type_A_{$order->id}_" . time() . '.csv';
        $filePath = $storageDir . '/' . $csvFile; // Write to storage/
        $fileHandle = $this->fileSystem->open($filePath, 'w');

        if ($fileHandle !== false) {
            $this->writeOrderToCsv($fileHandle, $order);
            $this->fileSystem->close($fileHandle);
            $order->status = 'exported';
        } else {
            $order->status = 'export_failed';
        }

        return $order;
    }

    /**
     * Writes order details to a CSV file.
     *
     * This function adds the order’s information (like ID, type, amount, etc.) to a CSV file.
     * If the order amount is over 150, it also adds a special note saying "High value order".
     *
     * @param resource $fileHandle The open file where the CSV data will be written.
     * @param Order $order The order object whose details will be written to the file.
     */
    private function writeOrderToCsv($fileHandle, $order): void
    {
        $this->fileSystem->writeCsv($fileHandle, ['ID', 'Type', 'Amount', 'Flag', 'Status', 'Priority']);
        $this->fileSystem->writeCsv($fileHandle, [
            $order->id,
            $order->type,
            $order->amount,
            $order->flag ? 'true' : 'false',
            $order->status,
            $order->priority
        ]);

        if ($order->amount > 150) {
            $this->fileSystem->writeCsv($fileHandle, ['', '', '', '', 'Note', 'High value order']);
        }
    }

    /**
     * Sets the status for a type 'B' order based on API data.
     *
     * This function decides the order’s status using the API response data and the order’s amount and flag.
     * - 'processed': If data is 50 or more and amount is less than 100.
     * - 'pending': If data is less than 50 or the flag is true.
     * - 'error': If none of the above conditions are met.
     *
     * @param Order $order The order object to update.
     * @param mixed $apiData The data returned from the API (usually a number).
     * @return Order The updated order object with the new status.
     */
    private function setTypeBStatus($order, $apiData)
    {
        if ($apiData >= 50 && $order->amount < 100) {
            $order->status = 'processed';
        } elseif ($apiData < 50 || $order->flag) {
            $order->status = 'pending';
        } else {
            $order->status = 'error';
        }

        return $order;
    }

    /**
     * Processes an order of type 'B' by calling an API.
     *
     * This function calls an external API with the order’s ID. If the API call works, it updates the order’s status
     * based on the response. If the API fails or throws an error, it sets the status to 'api_error' or 'api_failure'.
     *
     * @param Order $order The order object to process (type 'B').
     * @return Order The updated order object with the new status.
     */
    private function processTypeB($order): Order
    {
        try {
            $apiResponse = $this->apiClient->callAPI($order->id);

            if ($apiResponse->status === 'success') {
                $order = $this->setTypeBStatus($order, $apiResponse->data);
            } else {
                $order->status = 'api_error';
            }
        } catch (APIException $e) {
            $order->status = 'api_failure';
        }

        return $order;
    }

    /**
     * Processes an order of type 'C' based on its flag.
     *
     * This function checks the order’s flag (true or false). If the flag is true, it sets the status to 'completed'.
     * If false, it sets the status to 'in_progress'.
     *
     * @param Order $order The order object to process (type 'C').
     * @return Order The updated order object with the new status.
     */
    private function processTypeC($order)
    {
        $order->status = $order->flag ? 'completed' : 'in_progress';

        return $order;
    }

    /**
     * Updates the priority of an order based on its amount.
     *
     * This function sets the order’s priority to 'high' if the amount is over 200, or 'low' if it’s 200 or less.
     *
     * @param Order $order The order object to update.
     * @return Order The updated order object with the new priority.
     */
    private function updateOrderPriority($order)
    {
        $order->priority = $order->amount > 200 ? 'high' : 'low';

        return $order;
    }

    /**
     * Saves the order’s updated status and priority to the database.
     *
     * This function tries to update the order in the database with its new status and priority.
     * If the database update fails, it changes the order’s status to 'db_error'.
     *
     * @param Order $order The order object to save.
     * @return Order The updated order object with the new status.
     */
    private function persistOrder($order)
    {
        try {
            $this->dbService->updateOrderStatus($order->id, $order->status, $order->priority);
        } catch (DatabaseException $e) {
            $order->status = 'db_error';
        }

        return $order;
    }
}