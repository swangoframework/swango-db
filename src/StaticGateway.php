<?php
abstract class Gateway extends \Swango\Db\BaseGateway {
    public static function beginTransaction(): bool {
        echo "DB Gateway: transaction begin\n";
        return self::getAdapter(self::MASTER_DB)->beginTransaction();
    }
    public static function submitTransaction(): bool {
        echo "DB Gateway: transaction submit\n";
        $ret = self::getAdapter(self::MASTER_DB)->submit();
        self::runSubmitFunction();
        return $ret;
    }
    public static function rollbackTransaction(): bool {
        echo "DB Gateway: transaction rollback\n";
        $adapter = \SysContext::get('master_adapter');
        if (isset($adapter))
            $ret = $adapter->rollback();
        \SysContext::del('SBTAC-func');
        return $ret ?? false;
    }
}