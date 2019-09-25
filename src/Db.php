<?php
namespace Swango\Db;
/**
 * 数据库连接的最小单位，主库连接每个请求最多只能有一个，从库连接每次请求都切换
 *
 * @author fdrea
 *
 */
abstract class Db extends \Swoole\Coroutine\MySQL {
    public const DEFAULT_QUERY_TIMEOUT = 25;
    public $in_pool = true;
    protected $transaction_serial, $in_transaction = false, $defer = false, $need_to_run_recv = false, $timeout = self::DEFAULT_QUERY_TIMEOUT;
    public function inDeferMode(): bool {
        return $this->defer;
    }
    public function setNeedToRunRecv($need_to_run_recv): void {
        if (! $this->defer)
            return;
        $this->need_to_run_recv = $need_to_run_recv;
    }
    public function needToRunRecv(): bool {
        if (! $this->defer)
            return false;
        return $this->need_to_run_recv;
    }
    public function recv() {
        $this->need_to_run_recv = false;
        return parent::recv();
    }
    public function connect(array $serverInfo = null): bool {
        $ret = parent::connect($serverInfo);
        if ($ret === false) {
            if ($this->connect_errno === 1040)
                throw new Exception\TooManyConnectionsException();
            throw new Exception\ConnectErrorException($this->connect_errno, $this->connect_error, $this->errno,
                $this->error);
        }
        return true;
    }
    public function setTimeout(int $timeout): self {
        $this->timeout = $timeout;
        return $this;
    }
    /**
     * 立即返回所有数据
     *
     * @param string|\Sql\AbstractSql $sql
     * @return array 若为查询，则以数组形式返回查询结果；其他情况返回true
     */
    public function betterQuery($sql, ...$params) {
        if ($sql instanceof \Sql\AbstractSql) {
            $sql = $sql->getSqlString(new \Sql\Adapter\Platform\Mysql($this));
        } elseif (! is_string($sql))
            throw new \Exception('Wrong type: ' . gettype($sql));

        if (empty($params)) {
            $res = $this->query($sql, $this->timeout);
            if ($res === false)
                throw new Exception\QueryErrorException($this->errno, $this->error);
            if ($this->defer) {
                $this->recv();
                // $ret = $this->fetchAll();
            }
            $ret = $this->fetchAll();
            if (! isset($ret))
                return true;
            return $ret;
        }

        $result = $this->prepare($sql, $this->timeout);
        if ($result === false)
            throw new Exception\QueryErrorException($this->errno, $this->error);
        if ($this->defer) {
            // 开启了defer特性，需要recv获取Statement
            $statement = $this->recv();
            $this->need_to_run_recv = true;
        } else
            $statement = $result;

        $result = $statement->execute($params, $this->timeout);

        if ($result === false)
            throw new Exception\QueryErrorException($this->errno, $this->error);

        if ($this->defer) {
            $statement->recv();
            $this->setNeedToRunRecv(false);
        }

        $ret = $statement->fetchAll();
        if (! isset($ret))
            return true;
        return $ret;
    }
    /**
     * 返回迭代器
     *
     * @param string|\Sql\Select $sql
     * @param unknown ...$params
     * @throws \DbErrorException\QueryErrorException
     * @return \Coroutine\Db\Statement 可以直接对其执行 foreach
     */
    public function selectWith($sql, ...$params): Statement {
        if ($sql instanceof \Sql\Select) {
            $sql = $sql->getSqlString(new \Sql\Adapter\Platform\Mysql($this));
        } elseif (! is_string($sql))
            throw new \Exception('Wrong type: ' . gettype($sql));

        $result = $this->prepare($sql, $this->timeout);
        if ($result === false)
            throw new Exception\QueryErrorException($this->errno, $this->error);
        if ($this->defer) {
            // 开启了defer特性，要传入本对象，因为execute之后，statement需要再recv一下才能正常fetch
            $statement = $this->recv();
            if (is_bool($statement))
                throw new Exception\QueryErrorException($this->errno, $this->error);

            $this->need_to_run_recv = true;
            $ret = new Statement($statement, $this);
        } else
            // 说明没有开启defer特性
            $ret = new Statement($result, $this);

        if ($ret->execute($this->timeout, ...$params) === false)
            throw new Exception\QueryErrorException($this->errno, $this->error);
        return $ret;
    }
    public function getTransactionSerial(): ?int {
        return $this->transaction_serial;
    }
    public function inTransaction(): bool {
        return $this->in_transaction;
    }
    public function beginTransaction(): bool {
        if ($this->defer)
            throw new \Exception('Cannot begin transaction when in defer mode');
        if ($this->in_transaction)
            return false;
        $this->betterQuery('SET AUTOCOMMIT=0');
        $this->betterQuery('BEGIN WORK');
        $this->in_transaction = true;
        if (! isset($this->transaction_serial))
            $this->transaction_serial = 1;
        else
            ++ $this->transaction_serial;
        \FinishFunc::register([
            $this,
            'rollback'
        ]);
        return true;
    }
    public function submit(): bool {
        if (! $this->in_transaction)
            return false;
        $this->betterQuery('COMMIT WORK');
        $this->betterQuery('SET AUTOCOMMIT=1');
        $this->in_transaction = false;
        return true;
    }
    public function rollback($timeout = NULL): bool {
        if (! $this->in_transaction)
            return false;
        $this->betterQuery('ROLLBACK WORK');
        $this->betterQuery('SET AUTOCOMMIT=1');
        $this->in_transaction = false;
        return true;
    }
}