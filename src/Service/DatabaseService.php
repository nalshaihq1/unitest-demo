<?php

//File DatabaseService.php
namespace App\Service;

interface DatabaseService
{
    public function getOrdersByUser($userId): array;
    public function updateOrderStatus($orderId, $status, $priority): bool;
}