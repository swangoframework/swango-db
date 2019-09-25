<?php
namespace Swango\Db;
/**
 * 每个进程只存在一对
 *
 * @author fdrea
 * @method bool _push(\Coroutine\Db $db)
 * @property \Swoole\Atomic $atomic
 * @property \Swoole\Atomic $too_many_conntection_lock
 * @property int $max_conntection 整个Server最大连接数
 * @property int $max_conntection_for_each_worker 每个Worker的最大连接数
 *
 */
abstract class Pool {
    public static function init(int $max_conntection): void {
        static::$atomic = new \Swoole\Atomic();
        static::$too_many_conntection_lock = new \Swoole\Atomic();
        static::$max_conntection = $max_conntection;
    }
    public static function subCountor(): int {
        -- static::$count;
        if (isset(static::$atomic))
            return static::$atomic->sub(1);
        return 0;
    }
    public static function getWorkerCount(): int {
        return static::$count;
    }
    public static function addWorkerCountToAtomic(bool $set_to_zero_first = false): void {
        if ($set_to_zero_first)
            static::$atomic->set(static::$count);
        else
            static::$atomic->add(static::$count);
    }
    private const TIMEOUT = 25;
    protected $server_info, $queue, $channel, $timer;
    public function __construct(string $host, string $user, string $password, string $database, int $port = 3306,
        string $charset = 'utf8') {
        $this->server_info = [
            'host' => $host,
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'port' => $port,
            'timeout' => self::TIMEOUT,
            'charset' => $charset,
            'strict_type' => false,
            'fetch_mode' => true
        ];
        $this->channel = new \Swoole\Coroutine\Channel(1);
        $this->queue = new \SplQueue();
        \swoole_timer_after(rand(50, 5000), '\\swoole_timer_tick', 10000,
            [
                $this,
                'checkDb'
            ]);
    }
    abstract protected function _newDb(): Db;
    protected function newDb(): ?Db {
        $use_max_limit = isset(static::$too_many_conntection_lock) && isset(static::$atomic) &&
             isset(static::$max_conntection);
        if ($use_max_limit) {
            if (static::$too_many_conntection_lock->get() > \Time\now()) {
                trigger_error("DbPool: new db fail because of lock");
                return null;
            }
            if (static::$count >= static::$max_conntection) {
                trigger_error('DbPool: new db fail because reach max conntections for each worker:' . static::$count);
                return null;
            }

            // 新增连接时，若已达上限，则返回空
            $count = static::$atomic->add(1);
            if ($count > static::$max_conntection) {
                static::$atomic->sub(1);
                trigger_error("DbPool: new db fail because reach max conntections: $count");
                return null;
            }
        }
        try {
            ++ static::$count;
            $db = $this->_newDb();
            return $db;
        } catch(Exception\TooManyConnectionsException $e) {
            if ($use_max_limit) {
                // 10秒内不再尝试新建连接
                static::$too_many_conntection_lock->set(\Time\now() + 10);
                trigger_error("DbPool: new db fail because mysql return too many connections");
                return null;
            }
            throw $e;
        }
    }
    public function push(Db $db): void {
        if ($db->in_pool) {
            trigger_error("DbPool: Already in pool " . get_class($db));
            return;
        }
        // 因为各种原因，push失败了，要抛弃该条连接，总连接数减1
        if (! $db->connected) {
            trigger_error("DbPool: push fail because not connected");
            return;
        }
        if ($db->needToRunRecv()) {
            trigger_error("DbPool: Need to run recv when pushing into pool");
            $db->recv();
        }
        $db->setTimeout(Db::DEFAULT_QUERY_TIMEOUT);
        if ($this->_push($db)) {
            $db->in_pool = true;
            if ($this->channel->stats()['consumer_num'] > 0)
                $this->channel->push($db);
            else
                $this->queue->push($db);
        } else
            trigger_error("DbPool: push fail because of other reasons");
    }
    public function pop(): Db {
        // 如果通道为空，则试图创建，若已达到最大连接数，则注册消费者，等待新的连接
        do {
            if ($this->queue->isEmpty()) {
                $db = $this->newDb();
                if (! isset($db)) {
                    $db = $this->channel->pop(self::TIMEOUT);
                    if ($db === false)
                        throw new Exception\ConnectErrorException(- 1, 'Channel pop timeout', null, null);
                }
                $db->in_pool = false;
                return $db;
            }
            $db = $this->queue->pop();
        } while ( ! $db->connected );
        $db->in_pool = false;
        return $db;
    }
    public function checkDb(?int $timer_id = null) {
        if (isset($timer_id)) {
            if (isset($this->timer) && $this->timer !== $timer_id)
                \swoole_timer_clear($this->timer);
            $this->timer = $timer_id;
        }
        $count = $this->queue->count();
        if ($count === 0) {
            if ($this->channel->stats()['consumer_num'] > 0) {
                trigger_error('New db because there are more than one consumer. This is abnormal.');
                $db = $this->newDb();
                if (isset($db))
                    $this->push($db);
            }
            return;
        }
        // $average_dbs_for_each_worker = intdiv(static::$max_conntection, \Server::getWorkerNum());
        // $max_spare_dbs = intdiv($average_dbs_for_each_worker, 8);
        if ($count > 1) {
            // trigger_error(LOCAL_IP . ' ' . \Server::getWorkerId() . " pop db because there are too many connections");
            $db = $this->queue->pop();
            if ($db instanceof Db)
                $db->in_pool = false;
        }
    }
    public function clearQueueAndTimer(): void {
        if (isset($this->timer))
            \swoole_timer_clear($this->timer);
        $this->queue = new \SplQueue();
    }
}