<?php

namespace WorkerBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use WorkerBundle\Queue\Queue;

/**
 * Class WorkerWorkloadEvent
 * @package AppBundle\Event
 */
Final Class WorkerWorkloadEvent extends Event
{
    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var string
     */
    private $workerName;

    /**
     * @var mixed
     */
    private $workload;

    /**
     * @var \Exception
     */
    private $exception;

    /**
     * @var array
     */
    private $statistics;

    /**
     * @param Queue $Queue
     * @param $workerName
     * @param $workload
     * @param null $exception
     */
    public function __construct(Queue $Queue, $workerName, $workload, $exception = null)
    {
        $this->queue        = $Queue;
        $this->workerName   = $workerName;
        $this->workload     = $workload;
        $this->exception    = $exception;
        $this->statistics   = [];
    }

    /**
     * @return string
     */
    public function getWorkerName()
    {
        return $this->workerName;
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
     * @return array
     */
    public function getStatistics()
    {
        return $this->statistics;
    }

    /**
     * @param array $statistics
     */
    public function setStatistics($statistics)
    {
        $this->statistics = $statistics;
    }
}
