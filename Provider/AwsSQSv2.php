<?php

namespace WorkerBundle\Provider;

use Aws\Sqs\SqsClient;
use Aws\Sqs\Enum\QueueAttribute;
use Aws\Sqs\Exception\SqsException;
use WorkerBundle\Utils\Queue;

/**
 * Class AwsSQSv2
 * @package WorkerBundle\Provider
 */
class AwsSQSv2 extends BaseProvider
{
    /**
     * @var \Aws\Sqs\SqsClient;
     */
    protected $sqs;

    /**
     * @var array
     */
    protected $queueUrls = [];

    public function __construct($awsConfiguration)
    {
        if (!class_exists('\Aws\Sqs\SqsClient')) {
            throw new \LogicException("Can't find AWS SDK >= 2.0.0");
        }

        $this->sqs = SqsClient::factory($awsConfiguration);
    }

    /**
     * {@inheritdoc}
     */
    public function createQueue($queueName, array $queueOptions = [])
    {
        // Enable Long Polling by default
        if (! isset($queueOptions[QueueAttribute::RECEIVE_MESSAGE_WAIT_TIME_SECONDS])) {
            $queueOptions[QueueAttribute::RECEIVE_MESSAGE_WAIT_TIME_SECONDS] = 20;
        }

        $response = $this->sqs->createQueue([
            'QueueName'  => $queueName,
            'Attributes' => $queueOptions
        ]);

        return new Queue($this->extractQueueNameFromUrl($response['QueueUrl']), $this);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteQueue($queueName)
    {
        $this->sqs->deleteQueue([
            'QueueUrl' => $this->getQueueUrl($queueName)
        ]);
        unset($this->queueUrls[$queueName]);

        return true;
    }

    /**
     * Extract queue name from AWS queue url.
     *
     * @param string $queueUrl
     * @return string Queue name
     */
    private function extractQueueNameFromUrl($queueUrl)
    {
        return substr(strrchr($queueUrl, '/'), 1);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueOptions($queueName)
    {
        $response = $this->sqs->getQueueAttributes([
            'QueueUrl'       => $this->getQueueUrl($queueName),
            'AttributeNames' => ['All']
        ]);

        return $response['Attributes'];
    }

    /**
     * {@inheritdoc}
     */
    public function listQueues($queueNamePrefix = null)
    {
        $options = [];
        if (! is_null($queueNamePrefix)) {
            $options['QueueNamePrefix'] = $queueNamePrefix;
        }

        $response = $this->sqs->listQueues($options);

        $queues = [];
        foreach($response['QueueUrls'] as $queueUrl) {
            $queues[] = $this->extractQueueNameFromUrl($queueUrl);
        }

        return $queues;
    }

    /**
     * {@inheritdoc}
     */
    public function queueExists($queueName)
    {
        return (null !== $this->getQueueUrl($queueName));
    }

    /**
     * {@inheritdoc}
     */
    public function multiPut($queueName, array $workloads)
    {
        $queueUrl = $this->getQueueUrl($queueName);

        $batchWorkloads  = [];
        $batchWorkloadId = 1;
        foreach($workloads as $workload) {
            $workload = base64_encode(gzcompress(serialize($workload), 9));
            $batchWorkloads[] = [
                'Id'          => $batchWorkloadId++,
                'MessageBody' => $workload
            ];
        }

        $this->sqs->sendMessageBatch([
            'QueueUrl' => $queueUrl,
            'Entries'  => $batchWorkloads
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function put($queueName, $workload)
    {
        $queueUrl = $this->getQueueUrl($queueName);
        $workload = base64_encode(gzcompress(serialize($workload), 9));
        $this->sqs->sendMessage([
            'QueueUrl'    => $queueUrl,
            'MessageBody' => $workload
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function get($queueName, $timeout = null)
    {
        // Simulate timeout
        $tic = time();

        do {
            $queueUrl = $this->getQueueUrl($queueName);
            $response = $this->sqs->receiveMessage([
                'QueueUrl'            => $queueUrl,
                'MaxNumberOfMessages' => 1,
            ]);

            if (count($response['Messages']) > 0) {
                $workload = $response['Messages'][0];

                $this->sqs->deleteMessage([
                    'QueueUrl'      => $queueUrl,
                    'ReceiptHandle' => $workload['ReceiptHandle']
                ]);
                if (md5($workload['Body']) == $workload['MD5OfBody']) {
                    return unserialize(gzuncompress(base64_decode($workload['Body'])));
                } else {
                    throw new \RuntimeException('Corrupted response');
                }
            }
        } while(null !== $timeout && (time() - $tic < $timeout));

        return null;
    }

    /**
     * @param string $queueName
     * @throws \Aws\Sqs\Exception\SqsException|\Exception
     * @return string AWS queue url
     */
    private function getQueueUrl($queueName)
    {
        if (! isset($this->queueUrls[$queueName])) {
            try {
                $response = $this->sqs->getQueueUrl([
                    'QueueName' =>$queueName
                ]);
                $this->queueUrls[$queueName] = $response['QueueUrl'];
            } catch(SqsException $e) {
                if ('AWS.SimpleQueueService.NonExistentQueue' === $e->getExceptionCode()) {
                    // Non existing queue
                    return null;
                } else {
                    // Broadcast
                    throw $e;
                }
            }
        }

        return $this->queueUrls[$queueName];
    }

    /**
     * {@inheritdoc}
     */
    public function count($queueName)
    {
        $attributes = $this->getQueueOptions($queueName);
        return intval($attributes['ApproximateNumberOfMessages']);
    }

    /**
     * {@inheritdoc}
     */
    public function updateQueue($queueName, array $queueOptions = [])
    {
        $this->sqs->setQueueAttributes([
            'QueueUrl'   => $this->getQueueUrl($queueName),
            'Attributes' => $queueOptions
        ]);

        return true;
    }

}
