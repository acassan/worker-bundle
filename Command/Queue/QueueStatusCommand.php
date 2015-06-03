<?php

namespace WorkerBundle\Command\Queue;

use Symfony\Component\Console\Input\InputOption;
use WorkerBundle\Command\BaseCommand;


/**
 * Class QueueStatusCommand
 * @package WorkerBundle\Command\Queue
 */
class QueueStatusCommand extends BaseCommand
{
    protected function configure()
    {
        // Generic Options
        $this
            ->setName('worker:queue:status')
            ->setDescription('Monitoring queues status')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay between refresh', 10)
        ;

    }

    /**
     * @return bool
     */
    protected function executeCommand()
    {
        $workerConfig       = $this->getContainer()->getParameter('worker.config');

        while(true) {
            $queueData = [];

            foreach($workerConfig['queues'] as $queueConfig) {
                $queueData[]    = [$queueConfig['name'], $this->getContainer()->get('worker.queue.'.$queueConfig['name'])->count()];
            }

            $this->getHelperSet()->get('table')
                ->setHeaders(['Queue', 'Items'])
                ->setRows($queueData)
                ->render($this->getOuput());

            sleep($this->getInput()->getOption('delay'));
        }

        return true;
    }
}
