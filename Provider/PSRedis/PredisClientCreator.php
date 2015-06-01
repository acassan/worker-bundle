<?php
namespace WorkerBundle\Provider\PSRedis;

use PSRedis\Client;
use PSRedis\Exception\ConfigurationError;
use WorkerBundle\Provider\PRedis;

/**
 * Class PredisClientCreator
 * @package WorkerBundle\Provider\PSRedis
 */
Class PredisClientCreator implements Client\Adapter\Predis\PredisClientFactory
{
    public function createClient($clientType, array $parameters = array())
    {
        switch($clientType)
        {
            case Client::TYPE_REDIS:
                return $this->createRedisClient($parameters);
            case Client::TYPE_SENTINEL:
                return $this->createSentinelClient($parameters);
        }

        throw new ConfigurationError('To create a client, you need to provide a valid client type');
    }

    /**
     * @param array $parameters
     * @return PRedis
     */
    private function createSentinelClient(array $parameters = array())
    {
        $predisClient = new PRedis($parameters);
        $predisClient->getProfile()->defineCommand(
            'sentinel', '\\PSRedis\\Client\\Adapter\\Predis\\Command\\SentinelCommand'
        );
        $predisClient->getProfile()->defineCommand(
            'role', '\\PSRedis\\Client\\Adapter\\Predis\\Command\\RoleCommand'
        );

        return $predisClient;
    }

    /**
     * @param array $parameters
     * @return PRedis
     */
    private function createRedisClient(array $parameters = array())
    {
        $predisClient = new Predis($parameters);
        $predisClient->getProfile()->defineCommand(
            'role', '\\PSRedis\\Client\\Adapter\\Predis\\Command\\RoleCommand'
        );

        return $predisClient;
    }
} 