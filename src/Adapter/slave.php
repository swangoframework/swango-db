<?php
namespace Swango\Db\Adapter;
class slave extends \Swango\Db\Adapter {
    public function __construct(\Swango\Db\Pool\slave $pool) {
        $this->pool = $pool;
    }
    public function getTransactionSerial(): ?int {
        return null;
    }
    public function inTransaction(): bool {
        return false;
    }
    public function beginTransaction(): bool {
        throw new \Swango\Db\Exception\UseTransactionOnSlaveDbException();
    }
    public function submit(): bool {
        throw new \Swango\Db\Exception\UseTransactionOnSlaveDbException();
    }
    public function rollback(): bool {
        throw new \Swango\Db\Exception\UseTransactionOnSlaveDbException();
    }
}