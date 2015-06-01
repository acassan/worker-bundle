<?php

namespace WorkerBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use WorkerBundle\Queue\Queue;

/**
 * Class WorkerEvent
 * @package AppBundle\Event
 */
Final Class WorkerEvent extends Event
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
     * @var integer
     */
    private $controlCode;

    /**
     * @param Queue $Queue
     * @param $workerName
     */
    public function __construct(Queue $Queue, $workerName)
    {
        $this->queue        = $Queue;
        $this->workerName   = $workerName;
    }

    /**
     * @return string
     */
    public function getWorkerName()
    {
        return $this->workerName;
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
