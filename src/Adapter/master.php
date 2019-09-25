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
    public function __construct(\Swango\Db\Pool\master $pool) {
        $this->pool = $pool;
    }
    public function __destruct() {
        if (isset($this->db)) {
            $db = $this->db;
            $this->db = null;
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
        if ($sql instanceof \Sql\Update || $sql instanceof \Sql\Insert || $sql instanceof \Sql\InsertMulti ||
             $sql instanceof \Sql\Delete)
            $this->prepareDb();
        if (isset($this->db))
            return $this->db->betterQuery($sql, ...$params);
        return parent::query($sql, ...$params);
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
        if (isset($this->db))
            return $this->db->selectWith($sql, ...$params);
        return parent::selectWith($sql, ...$params);
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
        return $this->db->beginTransaction();
    }
    public function submit(): bool {
        return $this->db->submit();
    }
    public function rollback(): bool {
        if (! isset($this->db))
            return false;
        return $this->db->rollback();
    }
    public function __get(string $key) {
        if ($key === 'db') {
            $db = $this->pool->pop();
            $this->db = $db;
            $db->in_adapter = true;
            return $db;
        }
        if ($key !== 'affected_rows' && $key !== 'insert_id')
            return null;
        if (! isset($this->db))
            return 0;
        return $this->db->{$key};
    }
    public function prepareDb(): self {
        if (! isset($this->db)) {
            $db = $this->pool->pop();
            $this->db = $db;
            $db->in_adapter = true;
        }
        return $this;
    }
}