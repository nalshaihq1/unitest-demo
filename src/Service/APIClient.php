<?php

//File APIClient.php
namespace App\Service;

use App\Entity\APIResponse;

interface APIClient
{
    public function callAPI($orderId): APIResponse;
}