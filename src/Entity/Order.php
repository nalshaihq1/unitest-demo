<?php
//File Order.php
namespace App\Entity;

class Order
{
    public $id;
    public $type;
    public $amount;
    public $flag;
    public $status;
    public $priority;

    public function __construct($id, $type, $amount, $flag)
    {
        $this->id = $id;
        $this->type = $type;
        $this->amount = $amount;
        $this->flag = $flag;
        $this->status = 'new';
        $this->priority = 'low';
    }
}