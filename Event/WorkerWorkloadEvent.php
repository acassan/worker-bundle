<?php

namespace WorkerBundle\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class WorkerWorkloadEvent
 * @package AppBundle\Event
 */
Final Class WorkerWorkloadEvent extends Event
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
     * @var mixed
     */
    private $workload;

    /**
     * @var \Exception
     */
    private $exception;

    /**
     * @param $workerProvider
     * @param $workerQueue
     * @param $workerName
     * @param $workload
     * @param null $exception
     */
    public function __construct($workerProvider, $workerQueue, $workerName, $workload, $exception = null)
    {
        $this->workerProvider   = $workerProvider;
        $this->workerQueue      = $workerQueue;
        $this->workerName       = $workerName;
        $this->workload         = $workload;
        $this->exception        = $exception;
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
     * @return mixed
     */
    public function getWorkload()
    {
        return $this->workload;
    }

    /**
     * @return \Exception
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * @return string
     */
    public function getWorkerProvider()
    {
        return $this->workerProvider;
    }
}
