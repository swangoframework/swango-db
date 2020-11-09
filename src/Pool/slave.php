<?php
namespace Swango\Db\Pool;
class slave extends \Swango\Db\Pool {
    protected static int $max_connection, $count = 0;
    protected static \Swoole\Atomic $atomic, $too_many_connection_lock;
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