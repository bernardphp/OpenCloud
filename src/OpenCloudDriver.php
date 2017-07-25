<?php
/**
 * @author Matt Kynaston <matt@kynx.org>
 * @license MIT
 */

namespace Bernard\Driver;

use Guzzle\Http\Exception\BadResponseException;
use OpenCloud\Queues\Resource\Claim;
use OpenCloud\Queues\Resource\Message;
use OpenCloud\Queues\Resource\Queue;
use OpenCloud\Queues\Service as QueueService;

/**
 * Opencloud driver for Bernard queues
 *
 * @see http://php-opencloud.readthedocs.io/en/latest/services/queues
 * @see http://bernard.readthedocs.io/index.html
 */
class OpenCloudDriver extends AbstractPrefetchDriver
{
    /**
     * @var QueueService
     */
    private $service;
    /**
     * Default TTL for published messages / claims
     * @var int
     */
    private $ttl;
    /**
     * @var Queue[]
     */
    private $queues = [];
    /**
     * The claims we are currently working on
     * @var Message[]
     */
    private $claims = [];
    /**
     * @var \OpenCloud\Common\Collection\PaginatedIterator[]
     */
    private $lists = [];

    /**
     * Constructor
     *
     * @param QueueService $service
     * @param int $prefetch
     * @param int $ttl
     */
    public function __construct(QueueService $service, $queues = [], $prefetch = null, $ttl = Claim::TTL_DEFAULT)
    {
        parent::__construct($prefetch);

        $this->service = $service;
        $this->ttl = $ttl;

        $this->populateQueues($queues);
    }

    /**
     * {@inheritDoc}
     */
    public function listQueues()
    {
        $result = [];
        /* @var $queues Queue[] */
        if ($queues = $this->service->listQueues()) {
            foreach ($queues as $queue) {
                $result[] = $queue->getName();
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function createQueue($queueName)
    {
        $this->service->createQueue($queueName);
    }

    /**
     * {@inheritDoc}
     */
    public function countMessages($queueName)
    {
        try {
            $queue = $this->getQueue($queueName, false);
            if ($stats = $queue->getStats()) {
                return $stats->total;
            }
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() != 404) {
                throw $e;
            }
        }
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function pushMessage($queueName, $message)
    {
        $queue = $this->getQueue($queueName);
        $queue->createMessage([
            'ttl' => $this->ttl,
            'body' => $message
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function popMessage($queueName, $duration = 5)
    {
        /* @var Message $claim */
        if ($message = $this->cache->pop($queueName)) {
            return $message;
        }

        $queue = $this->getQueue($queueName);

        $runtime = microtime(true) + $duration;
        while (microtime(true) < $runtime) {
            if ($claims = $this->claimMessages($queue)) {
                foreach ($claims as $claim) {
                    $this->claims[$claim->getHref()] = $claim;
                    $this->cache->push($queueName, [$claim->getBody(), $claim->getHref()]);
                }

                if (count($claims)) {
                    return $this->cache->pop($queueName);
                }
            }

            //sleep for 10 ms
            usleep(10000);
        }

        return [null, null];
    }

    /**
     * {@inheritDoc}
     */
    public function acknowledgeMessage($queueName, $receipt)
    {
        if (isset($this->claims[$receipt])) {
            $claim = $this->claims[$receipt];
            $claim->delete($claim->getClaimIdFromHref());
            unset($this->claims[$receipt]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function peekQueue($queueName, $index = 0, $limit = 20)
    {
        // `listMessages()` doesn't really allow seeking by index, so we've got to fake it
        // @see https://community.rackspace.com/developers/f/7/t/4494
        // @see http://php-opencloud.readthedocs.io/en/latest/services/queues/messages.html#get-messages
        // @see https://developer.rackspace.com/docs/cloud-queues/v1/api-reference/messages-operations/#get-messages

        $counter = 0;
        $result = [];
        /* @var Message[] $iterator */
        $iterator = $this->getListIterator($queueName);
        foreach ($iterator as $message) {
            if ($counter >= $index && $counter < $limit + $index) {
                $result[] = $message->getBody();
            }
            $counter++;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function removeQueue($queueName)
    {
        $queue = $this->getQueue($queueName, false);
        $queue->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function info()
    {
        return [
            'client_id' => $this->service->getClientId(),
            'name' => $this->service->getName(),
            'url' => (string) $this->service->getUrl(),
            'region' => $this->service->getRegion(),
            'url_type' => $this->service->getUrlType(),
            'prefetch' => $this->prefetch,
            'ttl' => $this->ttl
        ];
    }

    private function populateQueues(array $queues)
    {
        foreach ($queues as $name) {
            $this->queues[$name] = new Queue($this->service);
            $this->queues[$name]->setName($name);
        }
    }

    /**
     * Returns queue, optionally creating it if it doesn't already exist
     * @param string $name
     * @param bool $create
     * @return Queue
     */
    private function getQueue($name, $create = true)
    {
        if (isset($this->queues[$name])) {
            return $this->queues[$name];
        }

        try {
            $this->queues[$name] = $this->service->getQueue($name);
        } catch (BadResponseException $e) {
            if ($create && $e->getResponse()->getStatusCode() == 404) {
                $this->queues[$name] = $this->service->createQueue($name);
            } else {
                throw $e;
            }
        }

        return $this->queues[$name];
    }

    /**
     * @param Queue $queue
     * @return \OpenCloud\Common\Collection\PaginatedIterator|bool
     */
    private function claimMessages(Queue $queue)
    {
        if ($claims = $queue->claimMessages(['limit' => $this->prefetch, 'ttl' => $this->ttl])) {
            return $claims;
        }
        return false;
    }

    private function getListIterator($queueName)
    {
        if (empty($this->lists[$queueName])) {
            $queue = $this->getQueue($queueName);
            // echo = include our own messages in list
            $this->lists[$queueName] = $queue->listMessages(['echo' => true]);
        }
        return $this->lists[$queueName];
    }
}
