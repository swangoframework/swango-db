<?php
namespace Swango\Db;
class Statement implements \Iterator {
    private $load_all_data, $statement, $db, $finished, $current, $position = -1;
    public function __construct(\Swoole\Coroutine\MySQL\Statement $statement, ?Db $db = null) {
        $this->load_all_data = null;
        $this->statement = $statement;
        $this->db = $db;
        $this->finished = false;
    }
    public function __destruct() {
        if (isset($this->db) && ! isset($this->load_all_data)) {
            if (! $this->finished) {
                if ($this->db->needToRunRecv()) {
                    // 如果某次请求，未读返回值就结束了，那么这里要recv一下statement，才能继续正常使用
                    $this->statement->recv();
                    $this->db->setNeedToRunRecv(false);
                }
                $this->statement->fetchAll();
            }
            $this->db->pushSelfIntoPoolOnStatementDestruct();
            $this->db = null;
        }
    }
    /**
     * 不要直接执行此方法！
     *
     * @param array $params
     *            预处理数据参数，必须与prepare语句的参数个数相同。$params必须为数字索引的数组，参数的顺序与prepare语句相同
     * @return bool|array 成功时返回 ture，如果设置connect的fetch_mode参数为true时；成功时返回array数据集数组，如不是上述情况时；失败返回false，可检查$db->error和$db->errno判断错误原因
     */
    public function execute(int $timeout, ...$params): bool {
        if ($this->statement->execute($params, $timeout) === false) {
            return false;
        }

        if (isset($this->db) && $this->db instanceof Db\master && $this->db->in_adapter) {
            // 已经绑定在adapter中的主库连接
            $this->load_all_data = new \SplQueue();

            $fetch_all = $this->statement->fetchAll();
            if (is_array($fetch_all)) {
                foreach ($fetch_all as $v)
                    $this->load_all_data->enqueue((object)$v);
            }

            if ($this->load_all_data->isEmpty()) {
                $this->finished = true;
            } else {
                $this->current = $this->load_all_data->dequeue();
            }
        }

        $this->position = 0;
        return true;
    }
    /**
     * 从结果集中获取下一行
     *
     * @return array|NULL
     */
    private function fetch(): ?\stdClass {
        if ($this->position === -1) {
            throw new Exception\RunTimeErrorException('Not executed yet!');
        }
        $res = $this->statement->fetch();
        if (! isset($res) || $res === false) {
            return null;
        }
        if (array_key_exists('scalar', $res) && $res['scalar'] === false) {
            throw new Exception\QueryErrorException(0, 'Fetch result error. scalar=false');
        }
        return (object)$res;
    }
    /**
     * 返回一个包含结果集中剩余所有行的数组
     *
     * @return array|NULL
     */
    public function toArray(): array {
        $this->finished = true;
        if ($this->position === -1) {
            throw new Exception\RunTimeErrorException('Not executed yet!');
        }
        if (isset($this->load_all_data)) {
            $res = $this->load_all_data;
        } else {
            $res = $this->statement->fetchAll();
            if (! isset($res)) {
                return [];
            }
        }
        $ret = [];
        foreach ($res as &$arr)
            $ret[] = (object)$arr;
        return $ret;
    }
    public function current() {
        if (! $this->finished && ! isset($this->current)) {
            $this->rewind();
        }
        // if (isset($this->current->scalar))
        return $this->current;
    }
    public function next(): void {
        if (! $this->finished) {
            ++$this->position;
            if (isset($this->load_all_data)) {
                if ($this->load_all_data->isEmpty()) {
                    $this->current = null;
                    $this->finished = true;
                } else {
                    $this->current = $this->load_all_data->dequeue();
                }
            } else {
                $this->current = $this->fetch();
                if (! isset($this->current)) {
                    $this->finished = true;
                }
            }
        }
    }
    public function key(): int {
        return $this->position;
    }
    public function valid(): bool {
        if ($this->finished && isset($this->current)) {
            unset($this->current);
        }
        return ! $this->finished;
    }
    public function rewind() {
        if ($this->finished || isset($this->load_all_data)) {
            return;
        }
        if (isset($this->db)) {
            if ($this->db->needToRunRecv()) {
                $this->statement->recv();
                $this->db->setNeedToRunRecv(false);
            }
        }

        $this->current = $this->fetch();
        if (! isset($this->current)) {
            $this->finished = true;
        }
    }
}