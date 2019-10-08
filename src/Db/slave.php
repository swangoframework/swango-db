<?php
namespace Swango\Db\Db;
class slave extends \Swango\Db\Db {
    public function __destruct() {
        \Swango\Db\Pool\slave::subCountor();
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
    public function rollback($timeout = NULL): bool {
        throw new \Swango\Db\Exception\UseTransactionOnSlaveDbException();
    }
    /**
     * 开启延迟收包模式。使得query、prepare、和Statement->fetch/fetchAll之前必须执行recv
     */
    public function setDefer() {
        if ($this->defer)
            return;
        $this->swoole_db->setDefer();
        $this->defer = true;
    }
}