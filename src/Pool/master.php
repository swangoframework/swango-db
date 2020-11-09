<?php
namespace Swango\Db\Pool;
class master extends \Swango\Db\Pool {
    protected static int $max_connection, $count = 0;
    protected static \Swoole\Atomic $atomic, $too_many_connection_lock;
    protected function _newDb(): \Swango\Db\Db {
        $db = new \Swango\Db\Db\master();
        $db->connect($this->server_info);
        return $db;
    }
    protected function _push(\Swango\Db\Db\master $db): bool {
        if ($db->inTransaction()) {
            try {
                $db->rollback();
            } catch (\Throwable $e) {
                trigger_error('Master DB connection error when trying to rollback. Discard. ' . "{$e->getMessage()}");
                return false;
            }
        }
        if (! $db->inDeferMode()) {
            return true;
        } else {
            trigger_error('Master DB connection in defer mode when pushing. Discard');
        }
        return false;
    }
}