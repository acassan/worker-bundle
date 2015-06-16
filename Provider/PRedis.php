<?php

namespace WorkerBundle\Provider;

/**
 * Class PRedis
 * @package WorkerBundle\Provider
 */
class PRedis extends BaseProvider
{
    /**
     * @var \Predis\Client
     */
    protected $predis;

    /**
     * @param $predisConfiguration
     */
    public function __construct($predisConfiguration)
    {
        if (!class_exists('Predis\Client')) {
            throw new \LogicException("Can't find PRedis lib");
        }

        $this->predis = new \Predis\Client($predisConfiguration);
    }

    /**
     * @param string $queueName
     * @param mixed $workload
     */
    public function put($queueName, $workload)
    {
        $this->predis->rpush($queueName, serialize($workload));
    }

    /**
     * @param $queueName
     * @param $workload
     */
    public function putFirst($queueName, $workload)
    {
        $this->predis->lpush($queueName, serialize($workload));
    }

    /**
     * @param string $queueName
     * @param null $timeout
     * @return mixed|null
     */
    public function get($queueName, $timeout = null)
    {
        $result = $this->predis->brpop($queueName, $timeout);
        if (empty($result)) {
            return null;
        } else {
            return unserialize($result[1]);
        }
    }

    /**
     * @param string $queueName
     * @return int
     */
    public function count($queueName)
    {
        return $this->predis->llen($queueName);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteQueue($queueName)
    {
        $this->predis->del($queueName);
    }

    /**
     * @param $name
     * @param $arguments
     * @return bool
     */
    public function __call($name, $arguments)
    {
        if(!method_exists($this, $name) && method_exists($this->predis, $name)) {
            return $this->predis->{$name}($arguments);
        }

        return false;
    }
}