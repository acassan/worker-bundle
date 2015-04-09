<?php

namespace WorkerBundle\Utils;

use Symfony\Component\Process\Process;

/**
 * Class Worker
 * @package WorkerBundle\Utils
 * @service("app.utils.worker")
 */
Final Class Worker
{
    /**
     * @var string
     */
    public $kernelRootDir;

    /**
     * @param $kernelRootDir
     */
    public function __construct($kernelRootDir)
    {
        $this->kernelRootDir = $kernelRootDir;
    }

    /**
     * @return mixed
     */
    public function getAvailableWorkers()
    {
        $process    = new Process('php app/console | grep worker:');
        $process->setWorkingDirectory($this->kernelRootDir.'/../');
        $process->setTimeout(1);
        $process->run();
        preg_match_all('/:worker:([0-9a-z:]+) /i', $process->getOutput(), $matches);
        $workerList = $matches[1];

        return $workerList;
    }
}
