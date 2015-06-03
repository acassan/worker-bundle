<?php

namespace WorkerBundle\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Stopwatch\Stopwatch;

Abstract Class BaseCommand extends Command implements ContainerAwareInterface
{
    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface;
     */
    protected $container;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $ouput;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->ouput    = $output;

        // Start timer
        $stopwatch = new Stopwatch();
        $stopwatch->start('command');

        $this->executeCommand();

        // Stop timer
        $event = $stopwatch->stop('command');
        $this->outputWriteln('Duration: '.$event->getDuration().' ms', OutputInterface::OUTPUT_NORMAL);
        $this->outputWriteln('Memory: '.$event->getMemory().' bytes', OutputInterface::OUTPUT_NORMAL);
        
        return true;
    }

    /**
     * @throws \Exception
     */
    protected function executeCommand()
    {
        throw new \Exception("Function 'ExecuteCommand' must be defined");
    }

    /**
     * Return command input interface.
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
     * @see ContainerAwareInterface::setContainer()
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        return $this->container;
    }

    /**
     * @param array $messages
     * @param bool  $newline
     * @param int   $verbosity (@see OutputInterface VERBOSITY_* constants)
     * @param int   $type
     */
    protected function outputWrite($messages, $newline = false, $verbosity = OutputInterface::VERBOSITY_NORMAL, $type = OutputInterface::OUTPUT_NORMAL)
    {
        if ($this->getOuput()->getVerbosity() < $verbosity) {
            return;
        }

        $this->getOuput()->write($messages, $newline, $type);
    }

    /**
     * @param array $messages
     * @param int   $verbosity (@see OutputInterface VERBOSITY_* constants)
     * @param int   $type
     */
    protected function outputWriteln($messages, $verbosity = OutputInterface::VERBOSITY_NORMAL, $type = OutputInterface::OUTPUT_NORMAL)
    {
        $timePrefix = date('H:i:s').'-';
        $messages = is_array($messages) ? array_map(function($text) use($timePrefix) { return $timePrefix.$text; }, $messages) : $timePrefix.$messages;

        $this->outputWrite($messages, true, $verbosity, $type);
    }
}
