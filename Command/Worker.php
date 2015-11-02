<?php

namespace WorkerBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use WorkerBundle\Event\WorkerEvent;
use WorkerBundle\Event\WorkerWorkloadEvent;
use WorkerBundle\Queue\Queue;
use WorkerBundle\WorkerBundleEvents;

/**
 * Worker base
 * @package WorkerBundle\Command
 */
abstract class Worker extends Command implements ContainerAwareInterface
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
    private $output;

    /**
     * @var string
     */
    private $queueName;

    /**
     * @var string
     */
    private $workerName;

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
     * @var Queue
     */
    protected $queue;

    /**
     * @var EventDispatcher
     */
    protected $dispatcher;

    final protected function configure()
    {
        // Generic Options
        $this
            ->addOption('worker-wait-timeout', null, InputOption::VALUE_REQUIRED, 'Number of microsecond to wait for a new workload', 0)
            ->addOption('worker-limit', null, InputOption::VALUE_REQUIRED, 'Number of workload to process', 0)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit (Mb)', 0)
            ->addOption('worker-exit-on-exception', null, InputOption::VALUE_NONE, 'Stop the worker on exception')
        ;

        $this->configureWorker();

        if (!$this->queueName) {
            throw new \LogicException('The worker queue name cannot be empty.');
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input        = $input;
        $this->output       = $output;

        // Limits
        $this->limit        = intval($input->getOption('worker-limit'));
        $this->memoryLimit  = intval($input->getOption('memory-limit'));

        $this->dispatcher   = $this->getContainer()->get('event_dispatcher');
        $this->workerName   = join('', array_slice(explode('\\', get_class($this)), -1));

        $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_INITIALIZE, new WorkerEvent($this->getQueue(), $this->workerName));

        $output->writeln("<comment>".date('H:i:s')." - [".$this->getWorkerName()."] Initializing on queue '". $this->getQueue()->getName()."', worker-limit: '".$this->limit."', memory-limit: '".$this->memoryLimit."'</comment>");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    final protected function execute(InputInterface $input, OutputInterface $output)
    {
        while(WorkerControlCodes::CAN_CONTINUE === ($controlCode = $this->canContinueExecution())) {
            $queue = $this->getQueue();
            if (null === $queue) {
                return $this->shutdown(WorkerControlCodes::STOP_EXECUTION);
            }

            // Wait worker-wait-timeout
            usleep($input->getOption('worker-wait-timeout')*1000);

            $workload = $queue->get();
            if (null === $workload) {
                $controlCode = $this->onNoWorkload($queue);
                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->shutdown($controlCode);
                }

                continue;
            }

            $this->workloadProcessed++;

            try {
                $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_INITIALIZE, new WorkerWorkloadEvent($this->getQueue(), $this->workerName, $workload));

                $controlCode = $this->executeWorker($input, $output, $workload);

                $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_COMPLETED, new WorkerWorkloadEvent($this->getQueue(), $this->workerName, $workload));

                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->shutdown($controlCode);
                }
            } catch (\Exception $e) {
                $controlCode = $this->onException($queue, $workload, $e);

                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_EXCEPTION, new WorkerWorkloadEvent($this->getQueue(), $this->workerName, $workload, $e));
                    return $this->shutdown($controlCode);
                }

                if ($input->getOption('worker-exit-on-exception')) {
                    $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_WORKLOAD_EXCEPTION, new WorkerWorkloadEvent($this->getQueue(), $this->workerName, $workload, $e));
                    return $this->shutdown(WorkerControlCodes::EXIT_ON_EXCEPTION);
                }
            }
        }

        return $this->shutdown($controlCode);
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
     * @param $workload
     * @param \Exception $exception
     * @return int
     */
    protected function onException(Queue $queue, $workload, \Exception $exception)
    {
        $this->getOutput()->writeln("Exception during workload processing for queue {$queue->getName()}. Class=".get_class($exception).". Message={$exception->getMessage()}. Code={$exception->getCode()}");

        return WorkerControlCodes::STOP_EXECUTION;
    }

    /**
     * Called when no workload was provided from the queue.
     *
     * @param \WorkerBundle\Queue\Queue $queue
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
        $this->getContainer()->get('event_dispatcher')->dispatch(WorkerBundleEvents::WORKER_SHUTDOWN_COMPLETED, new WorkerEvent($this->getQueue(), $this->workerName));

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
    protected function getOutput()
    {
        return $this->output;
    }

    /**
     * Get the queue
     * @return Queue
     */
    protected function getQueue()
    {
        if ($this->queue === null) {
            $this->queue = $this->getContainer()->get('worker.queue.'.$this->queueName);
        }

        return $this->queue;
    }

    /**
     * @see ContainerAwareInterface::setContainer()
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @return string
     */
    protected function getQueueName()
    {
        return $this->queueName;
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
        $exitCode = $this->onShutdown($controlCode);

        return $exitCode;
    }

    /**
     * @return string
     */
    public function getWorkerName()
    {
        return $this->workerName;
    }
}