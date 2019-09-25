<?php
namespace Swango\Db\Db;
class master extends \Swango\Db\Db {
    public $in_adapter = false;
    public function __destruct() {
        parent::__destruct();
        \Swango\Db\Pool\master::subCountor();
    }
    public function setDefer($defer = NULL) {
        if ($this->defer)
            return;
        if ($this->inTransaction())
            throw new \Exception('Cannot set defer in transaction');
        parent::setDefer();
        $this->defer = true;
    }
}