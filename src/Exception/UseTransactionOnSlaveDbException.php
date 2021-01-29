<?php
namespace Swango\Db\Exception;
class UseTransactionOnSlaveDbException extends \Swango\Db\Exception {
    public function __construct() {
        parent::__construct('Use transaction on slave db');
    }
}