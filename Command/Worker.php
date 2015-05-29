<?php

namespace WorkerBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use WorkerBundle\Utils\WorkerControlCodes;

/**
 * Class Worker
 * @package WorkerBundle\Command
 */
abstract class Worker extends Command implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface;
     */
    private $container;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $ouput;

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
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var string
     */
    private $queueName;

    /**
     * @var Serializer
     */
    private $serializer;


    final protected function configure()
    {
        // Generic Options
        $this
        ->addOption('worker-wait-timeout', null, InputOption::VALUE_REQUIRED, 'Number of second to wait for a new workload', 0)
        ->addOption('worker-limit', null, InputOption::VALUE_REQUIRED, 'Number of workload to process', 0)
        ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit (Mb)', 0)
        ->addOption('worker-exit-on-exception', null, InputOption::VALUE_NONE, 'Stop the worker on exception')
        ;

        // Configure function could be overwrited
        $this->configureWorker();

        // Check queue has been defined
        if (!$this->queueName) {
            throw new \LogicException('The worker queue name cannot be empty.');
        }
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
        $this->dispatcher           = $this->container->get('event_dispatcher');

        // Limits
        $this->limit                = intval($input->getOption('worker-limit'));
        $this->memoryLimit          = intval($input->getOption('memory-limit'));

        // Serializer
        $this->serializer           = new Serializer([new GetSetMethodNormalizer()], [new JsonEncoder()]);

        $output->writeln("<comment>[".get_class($this)."] Initializing on queue '". $this->queueName."', worker-limit: '".$this->limit."', memory-limit: '".$this->memoryLimit."'</comment>");

        // For supervisord
        sleep(2);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    final protected function execute(InputInterface $input, OutputInterface $output)
    {
        while(WorkerControlCodes::CAN_CONTINUE === ($controlCode = $this->canContinueExecution())) {

            $workload   = $this->getNextWorkload();
            $workloadId =  uniqid();

            // Check queue has workload
            if (null === $workload) {
                $controlCode = $this->onNoWorkload();

                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->onShutdown($controlCode);
                }

                continue;
            }

            $this->workloadProcessed++;
            $this->getOuput()->writeln(date('H:i:s')."- Worload received ..", OutputInterface::VERBOSITY_DEBUG);

            try {
                // Execute code worker
                $controlCode = $this->executeWorker($input, $output, $workload);

                // Free memory
                gc_collect_cycles();
                $this->getOuput()->writeln(sprintf("Memory: %s Mb", round(memory_get_usage()/1048576,2)), OutputInterface::VERBOSITY_DEBUG);

                // Check if workload stop worker
                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->onShutdown($controlCode);
                }
            } catch (\Exception $e) {

                $controlCode = $this->onException($e);

                if (WorkerControlCodes::CAN_CONTINUE !== $controlCode) {
                    return $this->onShutdown($controlCode);
                }

                if ($input->getOption('worker-exit-on-exception')) {
                    return $this->onShutdown(WorkerControlCodes::EXIT_ON_EXCEPTION);
                }
            }
        }

        return $this->onShutdown($controlCode);
    }

    /**
     * @return \WorkerBundle\Provider\PRedis
     */
    public function getRedis()
    {
        return $this->container->get('app.worker.sentinel')->getRedis();
    }

    /**
     * @return mixed
     */
    public function getNextWorkload()
    {
        $workload = $this->getRedis()->get($this->queueName, $this->input->getOption('worker-wait-timeout'));

        return $workload;
    }

    /**
     * Called when no workload was provided from the queue.
     * @return int
     */
    protected function onNoWorkload()
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
        $this->getOuput()->writeln("Shutdown", OutputInterface::VERBOSITY_VERBOSE);

        return $controlCode;
    }

    /**
     * Called when Exception is catched during workload processing.
     *
     * @param \Exception $exception
     * @return int
     */
    protected function onException(\Exception $exception)
    {
        $this->getOuput()->writeln("Exception during workload processing for queue {$this->queueName}. Class=".get_class($exception).". Message={$exception->getMessage()}. Code={$exception->getCode()}. Line=" . $exception->getLine());
        $this->getContainer()->get('logger')->critical("[".get_class($this)."] Class=".get_class($exception).". Message={$exception->getMessage()}. Code={$exception->getCode()}. Line=" . $exception->getLine());

        return WorkerControlCodes::STOP_EXECUTION;
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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $workload
     * @return int
     */
    protected function executeWorker(InputInterface $input, OutputInterface $output, $workload)
    {
        throw new \LogicException('You must override the executeWorker() method in the concrete worker class.');
    }

    /**
     * Return command input interface.
     *
     * @return InputInterface
     */
    protected function getInput()
    {
        return $this->input;
    }

    /**
     * Return command output interface.
     *
     * @return OutputInterface
     */
    protected function getOuput()
    {
        return $this->ouput;
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Sets the Container.
     *
     * @param ContainerInterface|null $container A ContainerInterface instance or null
     *
     * @api
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @return string
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    /**
     * @param $queueName
     * @return $this
     */
    public function setQueueName($queueName)
    {
        $this->queueName = $queueName;

        return $this;
    }

    /**
     * @return Serializer
     */
    public function getSerializer()
    {
        return $this->serializer;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }
}
