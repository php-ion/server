<?php

namespace ION;

use ION\Server\Connect;

class SocketServer {
    const STATUS_DISABLED = 1;
    /**
     * @var Listener[]
     */
    private $_listeners = [];

    private $_max_conns = PHP_INT_MAX;

    private $_idle_timeout = 30;

    private $_request_timeout = 30;

    /**
     * @var Sequence
     */
    private $_timeout;
    /**
     * @var Sequence
     */
    private $_disconnect;
    /**
     * @var Sequence
     */
    private $_close;

    /**
     * @var Connect[]
     */
    private $_peers = [];

    private $_stats = [
        "pool_size" => 0,
        "peers" => 0
    ];

    /**
     * @var \SplPriorityQueue
     */
    private $_pool;

    /**
     * @var array
     */
    private $_slots = [];

    /**
     * @var Sequence
     */
    private $_accepted;

    private $_stream_class = Connect::class;

    private $_flags = 0;


    public function __construct() {
        $this->_pool       = new \SplPriorityQueue();
        $this->_accepted   = new Sequence([$this, "_accept"]);
        $this->_close      = new Sequence();
        $this->_disconnect = new Sequence();
        $this->_timeout    = new Sequence();

        $this->_pool->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * Listen address
     * @param string $address
     * @param int $back_log
     *
     * @return Listener
     */
    public function listen(string $address, int $back_log = -1) : Listener {
        $listener = $this->_listeners[$address] = new Listener($address, $back_log);
        $listener->whenAccepted()->then($this->_accepted);
        $listener->setStreamClass($this->_stream_class);
        return $listener;
    }

    public function enable() {
        foreach ($this->_listeners as $listener) {
            $listener->enable();
        }
        $this->_flags &= ~self::STATUS_DISABLED;
    }

    public function disable() {
        foreach ($this->_listeners as $listener) {
            $listener->disable();
        }
        $this->_flags |= self::STATUS_DISABLED;
    }

    protected function _accept(Connect $connect) {
        $connect->setup($this)->suspend();
        $this->_peers[$connect->getPeerName()] = $connect;
        if(count($this->_peers) >= $this->_max_conns) {
            $this->disable();
        }
        $connect->closed()->then([$this, "_disconnect"]);
        return $connect;
    }

    protected function _disconnect(Connect $connect) {
        unset($this->_peers[$connect->getPeerName()]);
        if(count($this->_peers) < $this->_max_conns) {
            $this->enable();
        }
        $this->_disconnect->__invoke($connect);
        return $connect;
    }

    public function whenAccepted() : Sequence {
        return $this->_accepted;
    }

    public function whenDisconnected() : Sequence {
        return $this->_disconnect;
    }

    public function whenTimeout() : Sequence {
        return $this->_timeout;
    }

    public function whenClose() : Sequence {
        return $this->_close;
    }

    public function getConnectionsCount() : int {
        return count($this->_peers);
    }

    /**
     * @param string $address
     *
     * @return Listener
     */
    public function getListener(string $address) : Listener {
        return $this->_listeners[$address];
    }

    /**
     * @param int $max
     */
    public function setMaxConnections(int $max) {
        if($max < 0) {
            $this->_max_conns = PHP_INT_MAX;
        } else {
            $this->_max_conns = $max;
        }
        if($this->_flags & self::STATUS_DISABLED) {
            $this->enable();
        }
    }

    /**
     * @param int $secs
     */
    public function setIdleTimeout(int $secs) {
        $this->_idle_timeout = $secs;
    }


    /**
     * @param Connect $socket
     * @param int $timeout
     */
    public function setTimeout(Connect $socket, int $timeout) {
        $timeout = -(time() + $timeout);
        $this->unsetTimeout($socket);
        if(!isset($this->_slots[$timeout])) {
            $this->_slots[$timeout] = $slot = new \ArrayObject();
            $slot->timeout = $timeout;
            $this->_pool->insert($slot, $timeout);
        } else {
            $slot = $this->_slots[$timeout];
        }
        $socket->timeout = -$timeout;
        $slot[$socket->getPeerName()] = $socket;
        $socket->slot = $slot;
    }

    /**
     * Remove timeout for connect
     * @param Connect $socket
     */
    public function unsetTimeout(Connect $socket) {
        if(isset($socket->slot)) {
            unset($socket->slot[$socket->getPeerName()]);
            if(!$socket->slot->count()) {
                unset($this->_slots[$socket->slot->timeout]);
            }
            unset($socket->slot, $socket->timeout_cb);
        }
    }

    public function release(Connect $connect) {
        $connect->resume();
        if ($this->_idle_timeout > 0) {
            $this->setTimeout($connect, $this->_idle_timeout);
        } elseif ($this->_idle_timeout === 0) {
            $connect->shutdown();
        }
    }

    public function reserve(Connect $connect) {
        $connect->suspend();
        $this->unsetTimeout($connect);
    }

    /**
     * Inspect connections
     */
    public function inspect() : array {
        $time = time();
        while($this->_pool->count() && ($item = $this->_pool->top())) {
            if($time >= abs($item["priority"])) {
                $slot = $item["data"];
                /* @var \ArrayObject $slot */
                foreach((array)$slot as $peer => $socket) {
                    /* @var Connect $socket */
                    $this->unsetTimeout($socket);
                    try {
                        if($socket->timeout_cb) {
                            call_user_func($socket->timeout_cb, $socket);
                        } else {
                            $this->_timeout->__invoke($socket);
                        }
                    } catch(\Throwable $e) {
                        $socket->shutdown();
                    }
                }
                $this->_pool->extract();
            } else {
                break;
            }
        }
        $this->_stats["pool_size"] = $this->_pool->count();
        $this->_stats["peers"] = count($this->_peers);
        return $this->_stats;
    }

    public function shutdown() {
        foreach ($this->_listeners as $listener) {
            $listener->shutdown();
        }
        $this->_listeners = [];
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}