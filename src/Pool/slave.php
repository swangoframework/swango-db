<?php
namespace Swango\Db\Pool;
class slave extends \Swango\Db\Pool {
    protected static $atomic, $max_conntection, $too_many_conntection_lock, $count = 0;
    protected function _newDb(): \Swango\Db\Db {
        $db = new \Swango\Db\Db\slave();
        $db->connect($this->server_info);
        $db->setDefer();
        return $db;
    }
    protected function _push(\Swango\Db\Db\slave $db): bool {
        return true;
    }
}