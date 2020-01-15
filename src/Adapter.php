<?php
namespace Swango\Db;
/**
 * master每个请求只存在一个，slave整个进程只有一个
 *
 * @author fdrea
 *
 */
abstract class Adapter {
    /**
     *
     * @var DbPool $pool
     */
    protected $pool;
    /**
     * 因兼容性原因，保留此函数
     *
     * @deprecated
     *
     * @return Adapter
     */
    public function getAdapter(): Adapter {
        return $this;
    }
    /**
     * 立即返回所有数据
     *
     * @param string|\Sql\AbstractSql $sql
     * @return array 若为查询，则以数组形式返回查询结果；其他情况返回true
     */
    public function query($sql, ...$params) {
        // 最多尝试两次
        for($i = 0; $i < 2; ++ $i) {
            try {
                $db = $this->pool->pop();
                $ret = $db->query($sql, ...$params);
                $this->pool->push($db);
                return $ret;
            } catch(Exception\QueryErrorException $e) {
                // 2002 Connection reset by peer or Transport endpoint is not connected
                // 2006 MySQL server has gone away
                if ($e->errno !== 2002 && $e->errno !== 2006)
                    throw $e;
                // 抛弃出现问题的连接
                unset($db);
            }
        }
        throw $e;
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
        // 最多尝试两次
        for($i = 0; $i < 2; ++ $i) {
            try {
                $db = $this->pool->pop();
                return $db->selectWith($sql, ...$params);
                // DB会在Statement销毁时push回pool
                // if (! $db->inDeferMode())
                // $this->pool->push($db);
            } catch(Exception\QueryErrorException $e) {
                // 2002 Connection reset by peer or Transport endpoint is not connected
                // 2006 MySQL server has gone away
                if ($e->errno !== 2002 && $e->errno !== 2006)
                    throw $e;
                // 抛弃出现问题的连接
                unset($db);
            }
        }
        throw $e;
    }
    abstract public function getTransactionSerial(): ?int;
    abstract public function inTransaction(): bool;
    abstract public function beginTransaction(): bool;
    abstract public function submit(): bool;
    abstract public function rollback(): bool;
}