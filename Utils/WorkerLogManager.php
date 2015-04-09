<?php

namespace WorkerBundle\Utils;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

/**
 * Class WorkerLogManager
 * @package WorkerBundle\Utils
 */
class WorkerLogManager
{
    /**
     * @var string
     */
    private $currentWorkerLogPath;

    /**
     * @var string
     */
    private $finishedWorkerLogPath;

    /**
     * @var Parser
     */
    private $yamlParser;

    /**
     * @var Dumper
     */
    private $yamlDumper;

    /**
     * @param $currentWorkerLogPath
     * @param $finishedWorkerLogPath
     */
    public function __construct($currentWorkerLogPath, $finishedWorkerLogPath)
    {
        $this->currentWorkerLogPath     = $currentWorkerLogPath;
        $this->finishedWorkerLogPath    = $finishedWorkerLogPath;
        $this->yamlParser               = new Parser();
        $this->yamlDumper               = new Dumper();
    }

    /**
     * @param $workerId
     * @param $workerName
     * @param $workloadId
     * @param $workload
     * @param int $workloadControlCode
     * @param $queueName
     * @return bool
     */
    public function log($workerId, $workerName, $workloadId, $workload, $workloadControlCode = WorkloadControlCodes::RUNNING, $queueName)
    {
        // If worker has no log create it
        $this->createWorkerLog($workerId);

        $workerLog              = $this->getWorkerLog($workerId);
        $workerLog[$workloadId] = [
            'date'          => date('Y-m-d H:i:s'),
            'workerName'    => $workerName,
            'workerQueue'   => $queueName,
            'workloadCode'  => $workloadControlCode,
            'workload'      => $workload
        ];

        $yaml                   = $this->yamlDumper->dump($workerLog);

        file_put_contents($this->currentWorkerLogPath.'/'.$workerId, $yaml);

        return true;
    }

    /**
     * Close and move to finished directory
     * @param $workerId
     * @return bool
     */
    public function workerFinished($workerId)
    {
        $filesystem             = new Filesystem();
        $filesystem->rename($this->currentWorkerLogPath.'/'.$workerId, $this->finishedWorkerLogPath.'/'.$workerId);

        return true;
    }

    /**
     * Retrieve worker log
     * @param $workerId
     * @return mixed
     */
    public function getWorkerLog($workerId)
    {
        $workerLog              = $this->yamlParser->parse(file_get_contents($this->currentWorkerLogPath.'/'.$workerId));

        return $workerLog;
    }

    /**
     * Create worker log
     * @param $workerId
     * @throws \Exception
     */
    public function createWorkerLog($workerId)
    {
        $filesystem         = new Filesystem();

        if(!$filesystem->exists($this->currentWorkerLogPath)) {
            throw new \Exception("Worker log directory '{$this->currentWorkerLogPath}' not found");
        }

        if(!$filesystem->exists($this->currentWorkerLogPath.'/'.$workerId)) {
            $filesystem->touch($this->currentWorkerLogPath.'/'.$workerId);
        }
    }
}
