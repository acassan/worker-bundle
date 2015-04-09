<?php

namespace WorkerBundle\Utils;
use WorkerBundle\Provider\ProviderInterface;

/**
 * Class WorkerManager
 * @package WorkerBundle\Utils
 */
class WorkerManager
{
    /**
     * @var array
     */
    private $config;

    /**
     * @param array $workerConfig
     */
    public function __construct(array $workerConfig)
    {
        $this->config = $workerConfig;
    }

    /**
     * @return string|null
     */
    public function getCurrentProvider()
    {
        foreach($this->config['providers'] as $providerName => $providerConfig) {
            return $providerName;
        }

        return null;
    }
}
