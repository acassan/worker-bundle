<?php

namespace WorkerBundle\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BaseWorkerCommand
 * @package AppBundle\Command
 */
abstract class BaseWorkerCommand extends Worker
{

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
