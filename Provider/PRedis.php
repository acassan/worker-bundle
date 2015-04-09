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
     * @inheritdoc
     */
    public function __construct($predisConfiguration)
    {
        if (!class_exists('Predis\Client')) {
            throw new \LogicException("Can't find PRedis lib");
        }

        $this->predis = new \Predis\Client($predisConfiguration);
    }

    /**
     * @inheritdoc
     */
    public function put($queueName, $workload)
    {
        $this->predis->lpush($queueName, serialize($workload));
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
     */
    public function count($queueName)
    {
        return $this->predis->llen($queueName);
    }

    /**
     * @inheritdoc
     */
    public function listQueues($queueNamePrefix = null)
    {
        return $this->predis->keys($queueNamePrefix);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteQueue($queueName)
    {
        $this->predis->del($queueName);
    }
}
