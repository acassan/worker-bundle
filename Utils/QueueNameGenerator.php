<?php

namespace WorkerBundle\Utils;

/**
 * Class QueueNameGenerator
 * @package WorkerBundle\Utils
 */
class QueueNameGenerator implements NameGeneratorInterface
{
    const GLUE     = '-';
    /**
     * @var string
     */
    private $prefix;

    /**
     * @param string $prefix
     */
    public function __construct($prefix)
    {
        $this->prefix = $prefix;
    }

    /**
     * {@inheritDoc}
     */
    function generate($input = null)
    {
        $name = $this->prefix.self::GLUE;

        if (is_array($input)) {
            return $name .= join(self::GLUE, $input);
        }

        return $name .= $input;
    }
}
