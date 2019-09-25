<?php
abstract class Gateway extends \Swango\Db\BaseGateway {
    public function beginTransaction(): bool {
        return self::getAdapter(self::MASTER_DB)->beginTransaction();
    }
    public function submitTransaction(): bool {
        $ret = self::getAdapter(self::MASTER_DB)->submit();
        self::runSubmitFunction();
        return $ret;
    }
    public function rollbackTransaction(): bool {
        $adapter = \SysContext::get('master_adapter');
        if (isset($adapter))
            $ret = $adapter->rollback();
        \SysContext::del('SBTAC-func');
        return $ret ?? false;
    }
}