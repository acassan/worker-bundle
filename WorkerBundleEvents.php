<?php

namespace WorkerBundle;

/**
 * Class WorkerBundleEvents
 * @package WorkerBundle
 */
Final Class WorkerBundleEvents
{
    // Workers events
    const WORKER_INITIALIZE                     = 'worker.initialize';
    const WORKER_WORKLOAD_INITIALIZE            = 'worker.workload.initialize';
    const WORKER_WORKLOAD_COMPLETED             = 'worker.workload.completed';
    const WORKER_WORKLOAD_EXCEPTION             = 'worker.workload.exception';
    const WORKER_SHUTDOWN_INITIALIZE            = 'worker.shutdown.initialize';
    const WORKER_SHUTDOWN_COMPLETED             = 'worker.shutdown.completed';
}
