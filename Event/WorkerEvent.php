<?php

namespace WorkerBundle\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class WorkerEvent
 * @package AppBundle\Event
 */
Final Class WorkerEvent extends Event
{
    /**
     * @var string
     */
    private $workerProvider;

    /**
     * @var string
     */
    private $workerName;

    /**
     * @var string
     */
    private $workerQueue;

    /**
     * @var integer
     */
    private $controlCode;

    /**
     * @param $workerProvider
     * @param $workerQueue
     * @param $workerName
     */
    public function __construct($workerProvider, $workerQueue, $workerName)
    {
        $this->workerProvider   = $workerProvider;
        $this->workerQueue      = $workerQueue;
        $this->workerName       = $workerName;
    }

    /**
     * @return string
     */
    public function getWorkerName()
    {
        return $this->workerName;
    }

    /**
     * @return string
     */
    public function getWorkerQueue()
    {
        return $this->workerQueue;
    }

    /**
     * @return string
     */
    public function getWorkerProvider()
    {
        return $this->workerProvider;
    }

    /**
     * @return int
     */
    public function getControlCode()
    {
        return $this->controlCode;
    }

    /**
     * @param int $controlCode
     */
    public function setControlCode($controlCode)
    {
        $this->controlCode = $controlCode;
    }
}
