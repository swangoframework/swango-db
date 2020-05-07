<?php
namespace Swango\Db\Adapter;
/**
 *
 * @author fdrea
 *
 * @property int $affected_rows 影响的行数
 * @property int $insert_id 最后一个插入的记录id
 * @property \Coroutine\Db\master $db
 */
class master extends \Swango\Db\Adapter {
    private $db_used = false;
    public function __construct(\Swango\Db\Pool\master $pool) {
        $this->pool = $pool;
    }
    public function __destruct() {
        if (isset($this->db)) {
            $db = $this->db;
            unset($this->db);
            $db->in_adapter = false;
            $this->pool->push($db);
        }
    }
    /**
     * 立即返回所有数据
     * 若没有固定db，则会一查询一轮换；否则协程内将使用固定的db。通过事务操作或prepareDb()方法来固定db
     *
     * @param string|\Sql\AbstractSql $sql
     * @return array 若为查询，则以数组形式返回查询结果；其他情况返回true
     */
    public function query($sql, ...$params) {
        if (isset($this->db)) {
            if ($this->db_used) {
                return $this->db->query($sql, ...$params);
            } else {
                for($i = 0; $i < 2; ++ $i) {
                    try {
                        $ret = $this->db->query($sql, ...$params);
                        $this->db_used = true;
                        return $ret;
                    } catch(\Swango\Db\Exception\QueryErrorException $e) {
                        // 2002 Connection reset by peer or Transport endpoint is not connected
                        // 2006 MySQL server has gone away
                        if ($e->errno !== 2002 && $e->errno !== 2006)
                            throw $e;

                        // 抛弃出现问题的连接
                        $this->db->in_adapter = false;
                        unset($this->db);
                    }
                }
                throw $e;
            }
        } else {
            // 进入下面代码，说明是初次绑定，则需要处理连接断开的情况
            if ($sql instanceof \Sql\Update || $sql instanceof \Sql\Insert || $sql instanceof \Sql\InsertMulti ||
                 $sql instanceof \Sql\Delete || (is_string($sql) && strtoupper(substr(ltrim($sql), 0, 6)) !== 'SELECT')) {
                // 最多尝试两次
                for($i = 0; $i < 2; ++ $i) {
                    try {
                        $ret = $this->injectDb()->query($sql, ...$params);
                        $this->db_used = true;
                        return $ret;
                    } catch(\Swango\Db\Exception\QueryErrorException $e) {
                        // 2002 Connection reset by peer or Transport endpoint is not connected
                        // 2006 MySQL server has gone away
                        if ($e->errno !== 2002 && $e->errno !== 2006)
                            throw $e;

                        // 抛弃出现问题的连接
                        $this->db->in_adapter = false;
                        unset($this->db);
                    }
                }
                throw $e;
            }
            return parent::query($sql, ...$params);
        }
    }
    /**
     * 返回迭代器
     * 若没有固定db，则会一查询一轮换；否则协程内将使用固定的db。通过事务操作或prepareDb()方法来固定db
     *
     * @param string|\Sql\Select $sql
     * @param unknown ...$params
     * @throws \DbErrorException\QueryErrorException
     * @return \Coroutine\Db\Statement 可以直接对其执行 foreach
     */
    public function selectWith($sql, ...$params): \Swango\Db\Statement {
        if (isset($this->db)) {
            if ($this->db_used) {
                return $this->db->selectWith($sql, ...$params);
            } else {
                // 最多尝试两次
                for($i = 0; $i < 2; ++ $i) {
                    try {
                        $ret = $this->db->selectWith($sql, ...$params);
                        $this->db_used = true;
                        return $ret;
                    } catch(\Swango\Db\Exception\QueryErrorException $e) {
                        // 2002 Connection reset by peer or Transport endpoint is not connected
                        // 2006 MySQL server has gone away
                        if ($e->errno !== 2002 && $e->errno !== 2006)
                            throw $e;

                        // 抛弃出现问题的连接
                        $this->db->in_adapter = false;
                        unset($this->db);
                    }
                }
                throw $e;
            }
        } else {
            return parent::selectWith($sql, ...$params);
        }
    }
    public function getTransactionSerial(): ?int {
        return $this->db->getTransactionSerial();
    }
    public function inTransaction(): bool {
        if (! isset($this->db))
            return false;
        return $this->db->inTransaction();
    }
    public function beginTransaction(): bool {
        for($i = 0; $i < 2; ++ $i) {
            try {
                $ret = $this->db->beginTransaction();
                $this->db_used = true;
                return $ret;
            } catch(\Swango\Db\Exception\QueryErrorException $e) {
                // 2002 Connection reset by peer or Transport endpoint is not connected
                // 2006 MySQL server has gone away
                if ($e->errno !== 2002 && $e->errno !== 2006)
                    throw $e;

                // 抛弃出现问题的连接
                $this->db->in_adapter = false;
                unset($this->db);
            }
        }
        throw $e;
    }
    public function submit(): bool {
        for($i = 0; $i < 2; ++ $i) {
            try {
                $ret = $this->db->submit();
                $this->db_used = true;
                return $ret;
            } catch(\Swango\Db\Exception\QueryErrorException $e) {
                // 2002 Connection reset by peer or Transport endpoint is not connected
                // 2006 MySQL server has gone away
                if ($e->errno !== 2002 && $e->errno !== 2006)
                    throw $e;

                // 抛弃出现问题的连接
                $this->db->in_adapter = false;
                unset($this->db);
            }
        }
        throw $e;
    }
    public function rollback(): bool {
        if (! isset($this->db))
            return false;
        return $this->db->rollback();
    }
    public function __get(string $key) {
        if ($key === 'db')
            return $this->injectDb();
        if ($key !== 'affected_rows' && $key !== 'insert_id')
            return null;
        if (! isset($this->db))
            return 0;
        return $this->db->{$key};
    }
    public function prepareDb(): self {
        if (! isset($this->db))
            $this->injectDb();
        return $this;
    }
    private function injectDb(): \Swango\Db\Db\master {
        $db = $this->pool->pop();
        $this->db = $db;
        $db->in_adapter = true;
        return $db;
    }
}