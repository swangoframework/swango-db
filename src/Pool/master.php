<?php
namespace Swango\Db\Pool;
class master extends \Swango\Db\Pool {
    protected static $atomic, $max_conntection, $too_many_conntection_lock, $count = 0;
    protected function _newDb(): \Swango\Db\Db {
        $db = new \Swango\Db\Db\master();
        $db->connect($this->server_info);
        return $db;
    }
    protected function _push(\Swango\Db\Db\master $db): bool {
        if ($db->inTransaction())
            try {
                $db->rollback();
            } catch(\Throwable $e) {
                trigger_error('Master DB conntection error when trying to rollback. Discard. ' . "{$e->getMessage()}");
                return false;
            }
        if (! $db->inDeferMode()) {
            return true;
        } else
            trigger_error('Master DB conntection in defer mode when pushing. Discard');
        return false;
    }
}