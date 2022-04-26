<?php
namespace Swango\Db;
/**
 * 数据库连接的最小单位，主库连接每个请求最多只能有一个，从库连接每次请求都切换
 *
 *
 * @property array $serverInfo 连接信息，保存的是传递给构造函数的数组
 * @property string $sock 连接使用的文件描述符
 * @property bool $connected 是否连接上了MySQL服务器
 * @property string $connect_error 发生在sock上的连接错误信息
 * @property int $connect_errno 发生在sock上的连接错误码
 * @property string $error MySQL服务器返回的错误信息
 * @property int $errno MySQL服务器返回的错误码
 * @property int $affected_rows 影响的行数
 * @property int $insert_id 最后一个插入的记录id
 *
 * @author fdrea
 *
 */
abstract class Db {
    public const DEFAULT_QUERY_TIMEOUT = 25;
    public bool $in_pool = true;
    protected \Swoole\Coroutine\MySQL $swoole_db;
    protected bool $in_transaction = false, $defer = false, $need_to_run_recv = false;
    protected int $timeout = self::DEFAULT_QUERY_TIMEOUT;
    protected ?int $transaction_serial = null;
    public function __construct(protected Pool $pool) {
        $this->swoole_db = new \Swoole\Coroutine\MySQL();
    }
    public function __get(string $key) {
        return $this->swoole_db->{$key};
    }
    abstract public function setDefer(): void;
    abstract public function pushSelfIntoPoolOnStatementDestruct(): void;
    public function getPool(): Pool {
        return $this->pool;
    }
    public function escape(string $str): string {
        return $this->swoole_db->escape($str);
    }
    public function inDeferMode(): bool {
        return $this->defer;
    }
    public function setNeedToRunRecv($need_to_run_recv): void {
        if (! $this->defer) {
            return;
        }
        $this->need_to_run_recv = $need_to_run_recv;
    }
    public function needToRunRecv(): bool {
        if (! $this->defer) {
            return false;
        }
        return $this->need_to_run_recv;
    }
    public function recv() {
        $this->need_to_run_recv = false;
        return $this->swoole_db->recv();
    }
    public function connect(array $serverInfo = null): bool {
        $ret = $this->swoole_db->connect($serverInfo);
        if ($ret === false) {
            if ($this->connect_errno === 1040) {
                throw new Exception\TooManyConnectionsException();
            }
            throw new Exception\ConnectErrorException($this->connect_errno,
                $this->connect_error,
                $this->errno,
                $this->error
            );
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
     * @param mixed ...$params
     * @return array 若为查询，则以数组形式返回查询结果；其他情况返回true
     */
    public function query(string|\Sql\AbstractSql                                             $sql,
                          \BackedEnum|\Swango\Model\IdIndexedModel|string|int|float|bool|null ...$params) {
        if ($sql instanceof \Sql\AbstractSql) {
            $params = [];
            $sql = $sql->getSqlString(new \Sql\Adapter\Platform\Mysql($this));
        } else {
            // 该处循环不能使用引用语法，否则null值swoole会报错，原因未知
            foreach ($params as $k => $param)
                if ($param instanceof \BackedEnum) {
                    $params[$k] = $param->value;
                } elseif ($param instanceof \Swango\Model\IdIndexedModel) {
                    $params[$k] = $param->getId();
                }
        }
        if (defined('SQL_DEBUG')) {
            echo '==========query===========', PHP_EOL, $sql, PHP_EOL, implode(PHP_EOL, $params), PHP_EOL;
        }
        if (empty($params)) {
            $res = $this->swoole_db->query($sql, $this->timeout);
            if ($res === false) {
                throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
            }
            if ($this->defer) {
                $this->recv();
                // $ret = $this->fetchAll();
            }
            $ret = $this->swoole_db->fetchAll();
            if (! isset($ret)) {
                return true;
            }
            return $ret;
        }

        $result = $this->swoole_db->prepare($sql, $this->timeout);
        if ($result === false) {
            throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
        }
        if ($this->defer) {
            // 开启了defer特性，需要recv获取Statement
            $statement = $this->swoole_db->recv();
            $this->need_to_run_recv = true;
        } else {
            $statement = $result;
        }

        $result = $statement->execute($params, $this->timeout);

        if ($result === false) {
            throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
        }

        if ($this->defer) {
            $statement->recv();
            $this->setNeedToRunRecv(false);
        }

        $ret = $statement->fetchAll();
        if (! isset($ret)) {
            return true;
        }
        return $ret;
    }
    /**
     * 返回迭代器
     *
     * @param string|\Sql\Select $sql
     * @param mixed ...$params
     * @return \Swango\Db\Statement 可以直接对其执行 foreach
     */
    public function selectWith(string|\Sql\Select                                                  $sql,
                               \BackedEnum|\Swango\Model\IdIndexedModel|string|int|float|bool|null ...$params): Statement {
        if ($sql instanceof \Sql\Select) {
            $params = [];
            $sql = $sql->getSqlString(new \Sql\Adapter\Platform\Mysql($this));
        } else {
            // 该处循环不能使用引用语法，否则null值swoole会报错，原因未知
            foreach ($params as $k => $param)
                if ($param instanceof \BackedEnum) {
                    $params[$k] = $param->value;
                } elseif ($param instanceof \Swango\Model\IdIndexedModel) {
                    $params[$k] = $param->getId();
                }
        }
        if (defined('SQL_DEBUG')) {
            echo '==========selectWith===========', PHP_EOL, $sql, PHP_EOL, implode(PHP_EOL, $params), PHP_EOL;
        }
        $result = $this->swoole_db->prepare($sql, $this->timeout);
        if ($result === false) {
            throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
        }
        if ($this->defer) {
            // 开启了defer特性，要传入本对象，因为execute之后，statement需要再recv一下才能正常fetch
            $statement = $this->swoole_db->recv();
            if (is_bool($statement)) {
                throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
            }

            $this->need_to_run_recv = true;
            $ret = new Statement($statement, $this);
        } else // 说明没有开启defer特性
        {
            $ret = new Statement($result, $this);
        }

        if ($ret->execute($this->timeout, ...$params) === false) {
            throw new Exception\QueryErrorException($this->errno, $this->error, $sql, $params);
        }
        return $ret;
    }
    public function getTransactionSerial(): ?int {
        return $this->transaction_serial;
    }
    public function inTransaction(): bool {
        return $this->in_transaction;
    }
    public function beginTransaction(): bool {
        if ($this->defer) {
            throw new \Exception('Cannot begin transaction when in defer mode');
        }
        if ($this->in_transaction) {
            return false;
        }
        $this->query('SET AUTOCOMMIT=0');
        $this->query('BEGIN WORK');
        $this->in_transaction = true;
        if (! isset($this->transaction_serial)) {
            $this->transaction_serial = 1;
        } else {
            ++$this->transaction_serial;
        }
        \FinishFunc::register([
            $this,
            'rollback'
        ]);
        return true;
    }
    public function submit(): bool {
        if (! $this->in_transaction) {
            return false;
        }
        $this->query('COMMIT WORK');
        $this->query('SET AUTOCOMMIT=1');
        $this->in_transaction = false;
        return true;
    }
    public function rollback($timeout = null): bool {
        if (! $this->in_transaction) {
            return false;
        }
        $this->query('ROLLBACK WORK');
        $this->query('SET AUTOCOMMIT=1');
        $this->in_transaction = false;
        return true;
    }
}
