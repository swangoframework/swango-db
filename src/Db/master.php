<?php
namespace Swango\Db\Db;
class master extends \Swango\Db\Db {
    public $in_adapter = false;
    public function __destruct() {
        \Swango\Db\Pool\master::subCountor();
    }
    public function setDefer() {
        if ($this->defer)
            return;
        if ($this->inTransaction())
            throw new \Exception('Cannot set defer in transaction');
        $this->swoole_db->setDefer();
        $this->defer = true;
    }
}