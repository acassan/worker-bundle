<?php

namespace WorkerBundle\Utils;

/**
 * Interface NameGeneratorInterface
 * @package WorkerBundle\Utils
 */
interface NameGeneratorInterface
{

    /**
     * generate name from input.
     *
     * @param mixed $input
     * @return string
     */
    function generate($input = null);

}
