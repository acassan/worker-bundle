<?php

namespace WorkerBundle\Provider;

use PSRedis\MasterDiscovery;
use PSRedis\Client;

/**
 * Class PSRedis
 * @package WorkerBundle\Provider
 */
class PSRedis extends BaseProvider
{
    /**
     * @var \PSredis\MasterDiscovery
     */
    protected $masterDiscovery;

	/**
	 * @var \PSredis\Client\ClientAdapter
	 */
	private $master;

    /**
     * @param $psredisConfiguration
     */
    public function __construct($psredisConfiguration)
    {
        if (!class_exists('\PSRedis\Client')) {
            throw new \LogicException("Can't find PSRedis lib");
        }

        $this->masterDiscovery  = new MasterDiscovery($psredisConfiguration['master']);

        foreach($psredisConfiguration['sentinels'] as $sentinelConfiguration) {
            $this->masterDiscovery->addSentinel(new Client($sentinelConfiguration['host'],$sentinelConfiguration['port']));
        }
    }

    /**
     * @param string $queueName
     * @param mixed $workload
     * @throws \PSRedis\Exception\ConfigurationError
     * @throws \PSRedis\Exception\ConnectionError
     */
    public function put($queueName, $workload)
    {
        $this->getMaster()->lpush($queueName, serialize($workload));
    }

    /**
     * @param string $queueName
     * @param null $timeout
     * @return mixed|null
     * @throws \PSRedis\Exception\ConfigurationError
     * @throws \PSRedis\Exception\ConnectionError
     */
    public function get($queueName, $timeout = null)
    {
        $result = $this->getMaster()->brpop($queueName, $timeout);
        if (empty($result)) {
            return null;
        } else {
            return unserialize($result[1]);
        }
    }

    /**
     * @param string $queueName
     * @return mixed
     * @throws \PSRedis\Exception\ConfigurationError
     * @throws \PSRedis\Exception\ConnectionError
     */
    public function count($queueName)
    {
        return $this->getMaster()->llen($queueName);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteQueue($queueName)
    {
        $this->getMaster()->del($queueName);
    }

    /**
     * @return Client\ClientAdapter
     * @throws \PSRedis\Exception\ConfigurationError
     * @throws \PSRedis\Exception\ConnectionError
     */
    public function getMaster()
    {
		if(is_null($this->master)) {
			$this->master = $this->masterDiscovery->getMaster();
		}

		return $this->master;
    }

    /**
     * @param $name
     * @param $arguments
     * @return bool
     */
    public function __call($name, $arguments)
    {
        if(!method_exists($this, $name) && method_exists($this->getMaster(), $name)) {
            return $this->getMaster()->{$name}($arguments);
        }

        return false;
    }
}
