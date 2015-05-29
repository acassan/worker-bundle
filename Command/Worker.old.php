<?php

namespace WorkerBundle\Command;

use Symfony\Component\Stopwatch\Stopwatch;
use WorkerBundle\Provider\PRedis;
use WorkerBundle\Utils\WorkerManager;
use WorkerBundle\WorkerBundleEvents;
use WorkerBundle\Event\WorkerEvent;
use WorkerBundle\Event\WorkerWorkloadEvent;
use WorkerBundle\Provider\ProviderInterface;
use WorkerBundle\Utils\Queue;
use WorkerBundle\Utils\QueueNameGenerator;
use WorkerBundle\Utils\WorkerControlCodes;
use WorkerBundle\Utils\WorkerLogManager;
use WorkerBundle\Utils\WorkloadControlCodes;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Class Worker
 */
abstract class WorkerOld extends Command implements ContainerAwareInterface
{
    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface;
     */
    private $container;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $ouput;

    /**
     * @var ProviderInterface
     */
    private $provider;

    /**
     * @var QueueNameGenerator
     */
    private $queueNameGenerator;

    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * @var string
     */
    private $queueName;

    /**
     * @var string
     */
    protected $workerName;

    /**
     * @var int
     */
    private $limit = 0;

    /**
     * @var int
     */
    private $memoryLimit = 0;

    /**
     * @var int
     */
    private $workloadProcessed = 0;

    /**
     * @var int
     */
    private $workerPid;

    /**
     * @var WorkerLogManager
     */
    private $logManager;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var WorkerManager
     */
    private $workerManager;

    /**
     * @var Stopwatch
     */
    private $stopwatch;


    final protected function configure()
    {
        // Generic Options
        $this
        ->addOption('worker-wait-timeout', null, InputOption::VALUE_REQUIRED, 'Number of second to wait for a new workload', 0)
        ->addOption('worker-limit', null, InputOption::VALUE_REQUIRED, 'Number of workload to process', 0)
        ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit (Mb)', 0)
        ->addOption('worker-exit-on-exception', null, InputOption::VALUE_NONE, 'Stop the worker on exception')
        ;

        // Start stopwatch timer
        $this->stopwatch = new Stopwatch();

        $this->configureWorker();

        if (!$this->queueName) {
            throw new \LogicException('The worker queue name cannot be empty.');
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    final protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Check current log path
        if(false === is_writable($this->getContainer()->getParameter('worker_bundle.worker.current.logpath'))) {
            $this->getOuput()->writeln('<error>' . $this->getContainer()->getParameter('worker_bundle.worker.current.logpath') .
            ' not found or not writable. You should override `worker_bundle.worker.current.logpath` in you app/parameters.yml' . '</error>');

            return $this->shutdown(WorkerControlCodes::STOP_EXECUTION);
        }

        // Check finished log path
        if(false === is_writable($this->getContainer()->getParameter('worker_bundle.worker.finished.logpath'))) {
            $this->getOuput()->writeln('<error>' . $this->getContainer()->getParameter('worker_bundle.worker.finished.logpath') .
            ' not found or not writable. You should override `worker_bundle.worker.finished.logpath` in you app/parameters.yml' . '</error>');

            return $this->shutdown(WorkerControlCodes::STOP_EXECUTION);
        }

        $queue = $this->getQueue();

        if (null === $queue) {
            return 0;
        }

        while(WorkerControlCodes::CAN_CONTINUE === ($controlCode = $this->canContinueExecution())) {
            $workload   = $queue->get($input->getOption('worker-wait-timeout'));
            $workloadId =  uniqid();

            if (null === $workload) {
                $controlCode = $this->onNoWorkload($queue);
                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->shutdown($controlCode);
                }

                continue;
            }

            // Log workload as running
            $this->logManager->log($this->workerPid, $this->getName(), $workloadId, $workload, WorkloadControlCodes::RUNNING, $queue->getName());

            $this->workloadProcessed++;
            $this->getOuput()->writeln(date('H:i:s')."- Worload received ..");

            try {

                // Dispatch event initialize
                $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_INITIALIZE, new WorkerWorkloadEvent($this->getProvider(), $this->getQueue()->getName(), $this->getWorkerName(), $workload));

                // Start stopwatch workload timer
                $this->stopwatch->start('workload');

                // Execute code worker
                $controlCode = $this->executeWorker($input, $output, $workload);

                // Stop workload timer
                $workloadTimer = $this->stopwatch->stop('workload');

                // Dispatch event completed
                $workerWorkloadEvent = new WorkerWorkloadEvent($this->getProvider(), $this->getQueue()->getName(), $this->getWorkerName(), $workload);
                $workerWorkloadEvent->setStatistics([
                    'duration'  => $workloadTimer->getDuration(),
                    'memory'    => $workloadTimer->getMemory(),
                ]);
                $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_COMPLETED, $workerWorkloadEvent);

                // Free memory
                gc_collect_cycles();
                $this->getOuput()->writeln(sprintf("Memory: %s Mb", round(memory_get_usage()/1048576,2)));

                // Mark as finished in log
                $this->logManager->log($this->workerPid, $this->getName(), $workloadId, $workload, WorkloadControlCodes::FINISHED, $queue->getName());

                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->shutdown($controlCode);
                }
            } catch (\Exception $e) {

                $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_EXCEPTION, new WorkerWorkloadEvent($this->getProvider(), $this->getQueue()->getName(), $this->getWorkerName(), $workload, $e));

                $controlCode = $this->onException($queue, $e);
                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->shutdown($controlCode);
                }
                if ($input->getOption('worker-exit-on-exception')) {
                    return $this->shutdown(WorkerControlCodes::EXIT_ON_EXCEPTION);
                }
            }
        }

        return $this->shutdown($controlCode);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input                = $input;
        $this->ouput                = $output;
        $this->workerManager        = $this->container->get('app.worker.manager');
        $this->provider             = $this->getDefaultProvider();
        $this->dispatcher           = $this->container->get('event_dispatcher');
        $this->queueNameGenerator   = $this->getContainer()->get('app.worker.queuenamegenerator');
        $this->serializer           = new Serializer([new GetSetMethodNormalizer()], [new JsonEncoder()]);
        $this->workerPid            = getmypid();

        // Limits
        $this->limit        = intval($input->getOption('worker-limit'));
        $this->memoryLimit  = intval($input->getOption('memory-limit'));

        // LogManager
        $this->logManager = $this->getContainer()->get('app.worker.log');
        $this->logManager->createWorkerLog($this->workerPid);

        // Throw event
        $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_INITIALIZE, new WorkerEvent($this->getProvider(), $this->getQueue()->getName(), $this->getWorkerName()));

        $output->writeln("[{$this->getWorkerName()}] Initializing on queue '". $this->getQueue()->getName()."', worker-limit: '".$this->limit."', memory-limit: '".$this->memoryLimit."'");

        // For supervisord
        sleep(2);
    }

    /**
     * @return ProviderInterface
     */
    protected function getProvider()
    {
        return $this->provider;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * Return name of the worker. Used mainly for display in logs.
     *
     * @return string
     */
    protected function getWorkerName()
    {
        if (is_null($this->workerName)) {
            $explodedClass = explode('\\', get_class($this));
            $this->workerName = end($explodedClass);
        }

        return $this->workerName;
    }

    /**
     * @return QueueNameGenerator
     */
    public function getQueueNameGenerator()
    {
        return $this->queueNameGenerator;
    }

    /**
     * Indicates if the worker can process another workload.
     * Reasons :
     *   - limit reached
     *   - memory limit reached
     *   - custom limit reached
     *
     * @return boolean
     */
    protected function canContinueExecution()
    {
        // Workload limit
        if ($this->limit > 0 && $this->workloadProcessed >= $this->limit) {
            return WorkerControlCodes::WORKLOAD_LIMIT_REACHED;
        }

        // Memory limit
        $memory = memory_get_usage(true) / 1024 / 1024;
        if ($this->memoryLimit > 0 && $memory > $this->memoryLimit) {
            return WorkerControlCodes::MEMORY_LIMIT_REACHED;
        }

        return WorkerControlCodes::CAN_CONTINUE;
    }

    protected function configureWorker()
    {
        // Do nothing, could be overwritted
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param mixed $workload
     * @return int
     */
    protected function executeWorker(InputInterface $input, OutputInterface $output, $workload)
    {
        throw new \LogicException('You must override the executeWorker() method in the concrete worker class.');
    }

    /**
     * Called when Exception is catched during workload processing.
     *
     * @param Queue $queue
     * @param \Exception                          $exception
     * @return int
     */
    protected function onException(Queue $queue, \Exception $exception)
    {
        $this->getOuput()->writeln("Exception during workload processing for queue {$queue->getName()}. Class=".get_class($exception).". Message={$exception->getMessage()}. Code={$exception->getCode()}");
        $this->getContainer()->get('logger')->crit("[{$this->getWorkerName()}] Class=".get_class($exception).". Message={$exception->getMessage()}. Code={$exception->getCode()}");

        return WorkerControlCodes::STOP_EXECUTION;
    }

    /**
     * Called when no workload was provided from the queue.
     * @param Queue $queue
     * @return int
     */
    protected function onNoWorkload(Queue $queue)
    {
        return WorkerControlCodes::NO_WORKLOAD;
    }

    /**
     * Called before exit.
     *
     * @param int $controlCode
     * @return int Used as command exit code
     */
    protected function onShutdown($controlCode)
    {
        $this->getOuput()->writeln("[{$this->getWorkerName()}] Shutdown", OutputInterface::VERBOSITY_VERBOSE);
        $this->logManager->workerFinished($this->workerPid);

        return $controlCode;
    }

    /**
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        if (null === $this->container) {
            $this->container = $this->getApplication()->getKernel()->getContainer();
        }

        return $this->container;
    }

    /**
     * Return command input interface.
     *
     * @return \Symfony\Component\Console\Input\InputInterface
     */
    protected function getInput()
    {
        return $this->input;
    }

    /**
     * Return command output interface.
     *
     * @return \Symfony\Component\Console\Output\OutputInterface
     */
    protected function getOuput()
    {
        return $this->ouput;
    }

    /**
     * Get the queue
     * @return Queue
     */
    protected function getQueue()
    {
        return new Queue($this->getQueueNameGenerator()->generate($this->queueName), $this->getDefaultProvider());
    }

    /**
     * @see ContainerAwareInterface::setContainer()
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @param string $queueName
     * @return Worker
     */
    public function setQueueName($queueName)
    {
        $this->queueName = $queueName;

        return $this;
    }

    /**
     * @param int $controlCode
     * @return int
     */
    private function shutdown($controlCode)
    {
        $workerEvent    = new WorkerEvent($this->getProvider(), $this->getQueue()->getName(), $this->getName());
        $workerEvent->setControlCode($controlCode);

        $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_SHUTDOWN_INITIALIZE, $workerEvent);
        $controlCode    = $workerEvent->getControlCode();

        $exitCode = $this->onShutdown($controlCode);

        $workerEvent->setControlCode($controlCode);
        $this->getDispatcher()->dispatch(WorkerBundleEvents::WORKER_SHUTDOWN_COMPLETED, $workerEvent);

        return $exitCode;
    }

    /**
     * @return ProviderInterface
     */
    public function getDefaultProvider()
    {
        if($this->getContainer()->has('app.redis.sentinel')) {
            $redisMaster = $this->getContainer()->get('app.redis.sentinel')->getMaster();

            $redis = new PRedis([
                'host'                  => $redisMaster->getIpAddress(),
                'port'                  => $redisMaster->getPort(),
                'read_write_timeout'    => 0,
            ]);
        } else {
            $providerName   = $this->workerManager->getCurrentProvider();
            $redis          = $this->getContainer()->get('worker.provider.'.$providerName);
        }

        return $redis;
    }
}
