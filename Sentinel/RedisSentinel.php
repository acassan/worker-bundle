<?php

namespace WorkerBundle\Sentinel;

use PSRedis\Client;
use PSRedis\MasterDiscovery;
use WorkerBundle\Provider\PRedis;

/**
 * Class RedisSentinel
 * @package WorkerBundle\Sentinel
 */
Class RedisSentinel
{
    /**
     * @var array
     */
    private $configuration;

    /**
     * @var Client
     */
    private $masterDiscovery;

    /**
     * @var string
     */
    private $masterName;

    /**
     * @var array
     */
    private $sentinel;

    /**
     * @var PRedis
     */
    private $redis;

    /**
     * @param $sentinelConfig
     * @throws \Exception
     */
    public function __construct($sentinelConfig)
    {
        $this->configuration    = $sentinelConfig;
        $this->masterDiscovery  = null;
        $this->sentinel         = [];

        // Init sentinels
        $this->init();
    }

    /**
     * @return bool
     */
    public function hasSentinel()
    {
        return count($this->sentinel) > 0;
    }

    /**
     * Initialize sentinels
     * @throws \Exception
     */
    private function init()
    {
        if(!is_array($this->configuration) || count($this->configuration) < 1) {
            return false;
        }

        // Declare master
        foreach($this->configuration as $sentinelName => $sentinelConfig) {
            if($sentinelConfig['master']) {
                $this->masterDiscovery  = new MasterDiscovery($sentinelName);
                $this->masterName       = $sentinelName;
            }
        }

        if(is_null($this->masterDiscovery)) {
            throw new \Exception("no sentinel master configured");
        }

        // Declare sentinels
        foreach($this->configuration as $sentinelName => $sentinelConfig) {
            $sentinel = new Client($sentinelConfig['host'], $sentinelConfig['port']);
            array_push($this->sentinel, $sentinel);

            $this->masterDiscovery->addSentinel($sentinel);
        }

        return true;
    }

    /**
     * @return PRedis
     */
    public function getRedis()
    {
        if(is_null($this->redis)) {
            $master = $this->masterDiscovery->getMaster($this->masterName);

            $redis = new PRedis([
                'host'                  => $master->getIpAddress(),
                'port'                  => $master->getPort(),
                'read_write_timeout'    => 0,
            ]);

            $this->redis = $redis;
        }

        return $this->redis;
    }
}