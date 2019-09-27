<?php
abstract class Gateway extends \Swango\Db\BaseGateway {
    private static $master_pool, $slave_pool, $slave_adapter;
    public const MASTER_DB = 0, SLAVE_DB = 1;
    /**
     *
     * @param int $type
     * @param bool $give_additional_db
     *            默认为false；若传true，则会新建一个连接
     * @return \Coroutine\Db
     */
    public static function getAdapter(int $type = self::SLAVE_DB): \Swango\Db\Adapter {
        if ($type === self::SLAVE_DB) {
            if (! isset(self::$slave_pool)) {
                $config = include CONFIGSHAREDIR . 'DB/slave.php';
                self::$slave_pool = new \Swango\Db\Pool\slave($config['hostname'], $config['username'],
                    $config['password'], $config['database'], $config['port'], $config['charset']);
            }
            if (! isset(self::$slave_adapter))
                self::$slave_adapter = new \Swango\Db\Adapter\slave(self::$slave_pool);
            return self::$slave_adapter;
        } else {
            if (! isset(self::$master_pool)) {
                $config = include CONFIGSHAREDIR . 'DB/master.php';
                self::$master_pool = new \Swango\Db\Pool\master($config['hostname'], $config['username'],
                    $config['password'], $config['database'], $config['port'], $config['charset']);
            }
            $adapter = \SysContext::get('master_adapter');
            if (isset($adapter))
                return $adapter;
            $adapter = new \Swango\Db\Adapter\master(self::$master_pool);
            \SysContext::set('master_adapter', $adapter);
            return $adapter;
        }
    }
    public static function getDbPool(int $type = self::SLAVE_DB): ?\Swango\Db\Pool {
        if ($type === self::SLAVE_DB)
            return self::$slave_pool;
        else
            return self::$master_pool;
    }
    /**
     * 底层方法，不要调用！
     *
     * @param \Coroutine\Db $db
     */
    public static function pushDb(\Swango\Db\Db $db): void {
        if ($db instanceof \Swango\Db\Db\master)
            self::$master_pool->push($db);
        else
            self::$slave_pool->push($db);
    }
    public static function getTransactionSerial(): ?int {
        return self::getAdapter(self::MASTER_DB)->getTransactionSerial();
    }
    public static function inTransaction(): bool {
        return self::getAdapter(self::MASTER_DB)->inTransaction();
    }
    /**
     * 若当前处于事务中则直接执行；否则开启事务，并在执行后提交事务
     *
     * @param callable $func
     * @param mixed ...$parameter
     * @return mixed $func返回值
     */
    public static function runInTransaction(callable $callback, ...$parameter) {
        if (self::inTransaction())
            return $callback(...$parameter);
        else {
            self::getAdapter(self::MASTER_DB)->beginTransaction();
            $ret = $callback(...$parameter);
            self::getAdapter(self::MASTER_DB)->submit();
            return $ret;
        }
    }
    /**
     * 注册一个函数在提交事务之后执行（若发生了回滚，则会清空所有注册的方法），若当前不在事务中就直接执行
     *
     * @param callable $func
     * @param mixed ...$parameter
     * @return bool 当前是否在事务中
     */
    public static function registerSubmitFunction(callable $func, ...$parameter): bool {
        if (self::inTransaction()) {
            \SysContext::push('SBTAC-func',
                [
                    false,
                    $func,
                    $parameter
                ]);
            return true;
        } else {
            $func(...$parameter);
            return false;
        }
    }
    /**
     * 注册一个函数在提交事务之后新建协程执行（若发生了回滚，则会清空所有注册的方法），若当前不在事务中就直接新建协程执行
     *
     * @param callable $func
     * @param mixed ...$parameter
     * @return bool 当前是否在事务中
     */
    public static function registerCoroutineSubmitFunction(callable $func, ...$parameter): bool {
        if (self::inTransaction()) {
            \SysContext::push('SBTAC-func',
                [
                    true,
                    $func,
                    $parameter
                ]);
            return true;
        } else {
            \Swlib\Archer::task($func, $parameter);
            return false;
        }
    }
    protected static function runSubmitFunction() {
        $funcs = \SysContext::getAndDelete('SBTAC-func');
        if (isset($funcs))
            foreach ($funcs as [
                $new_coroutine,
                $func,
                $parameter
            ])
                if ($new_coroutine)
                    \Swlib\Archer::task($func, $parameter);
                else
                    try {
                        $func(...$parameter);
                    } catch(\Throwable $e) {
                        \FileLog::logThrowable($e, LOGDIR . 'error/', 'SubmitFunction');
                    }
    }
    public static function beginTransaction(): bool {
        return self::getAdapter(self::MASTER_DB)->beginTransaction();
    }
    public static function submitTransaction(): bool {
        $ret = self::getAdapter(self::MASTER_DB)->submit();
        self::runSubmitFunction();
        return $ret;
    }
    public static function rollbackTransaction(): bool {
        $adapter = \SysContext::get('master_adapter');
        if (isset($adapter))
            $ret = $adapter->rollback();
        \SysContext::del('SBTAC-func');
        return $ret ?? false;
    }
}