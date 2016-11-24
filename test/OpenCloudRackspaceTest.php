<?php
/**
 * @author Matt Kynaston <matt@kynx.org>
 * @license MIT
 */

namespace BernardTest\Driver;

use Guzzle\Plugin\Log\LogPlugin;
use Bernard\Driver\OpenCloudDriver;
use OpenCloud\Queues\Service;
use OpenCloud\Rackspace;
use PHPUnit_Framework_TestCase as TestCase;

class OpenCloudRackspaceTest extends TestCase
{
    /**
     * @var OpenCloudDriver
     */
    private $driver;
    /**
     * @var Rackspace
     */
    private $client;
    /**
     * @var Service
     */
    private $service;
    private $queueName = 'test-queue';

    public function setUp()
    {
        parent::setUp();

        $this->queueName = empty($_ENV['QUEUE_NAME']) ? 'test-queue' : $_ENV['QUEUE_NAME'];

        $this->client = $this->newClient();
        $this->client->authenticate();

        $this->service = $this->getClient()->queuesService(
            'cloudQueues',
            $_ENV['RACKSPACE_REGION'],
            $_ENV['RACKSPACE_URL_TYPE']
        );
        $this->service->setClientId();
        $this->driver = new OpenCloudDriver($this->service);
    }

    public function tearDown()
    {
        $this->removeDebugger();
        if ($this->service->hasQueue($this->queueName)) {
            $this->service->getQueue($this->queueName)->delete();
        }
        $this->client = null;
        $this->service = null;
        $this->driver = null;
    }

    /**
     * @group integration
     */
    public function testPassingQueuesToConstructor()
    {
        $queue = $this->service->createQueue($this->queueName);
        $queue->createMessages($this->createMessages([(object) ['foo' => 'bar']]));

        $this->addDebugger();

        $queues = [$this->queueName];
        $driver = new OpenCloudDriver($this->service, $queues);
        $actual = $driver->countMessages($this->queueName);
        $this->assertEquals(1, $actual);
    }

    /**
     * @group integration
     */
    public function testListQueues()
    {
        $base = count($this->service->listQueues());
        $this->service->createQueue($this->queueName);

        $this->addDebugger();

        $list = $this->driver->listQueues();
        $this->assertCount($base + 1, $list);
        $this->assertEquals($this->queueName, array_pop($list));
    }

    /**
     * @group integration
     */
    public function testListNonExistentQueue()
    {
        $this->addDebugger();
        $list = $this->driver->listQueues();
        $this->assertNotContains($this->queueName, $list);
    }

    /**
     * @group integration
     */
    public function testCreateQueue()
    {
        $this->addDebugger();

        $this->driver->createQueue($this->queueName);

        $this->removeDebugger();

        $this->assertTrue($this->service->hasQueue($this->queueName));
    }

    /**
     * @group integration
     */
    public function testCountMessages()
    {
        $bodies = [
            (object) ['foo' => 'bar'],
            (object) ['bar' => 'baz']
        ];
        $queue = $this->service->createQueue($this->queueName);
        $queue->createMessages($this->createMessages($bodies));

        $this->addDebugger();

        $this->assertEquals(2, $this->driver->countMessages($this->queueName));
    }

    /**
     * @group integration
     */
    public function testCountMessagesProvidedQueue()
    {
        $bodies = [
            (object) ['foo' => 'bar'],
            (object) ['bar' => 'baz']
        ];
        $queue = $this->service->createQueue($this->queueName);
        $queue->createMessages($this->createMessages($bodies));

        $this->addDebugger();

        $driver = new OpenCloudDriver($this->service, [$this->queueName]);

        $this->assertEquals(2, $driver->countMessages($this->queueName));
    }

    /**
     * @group integration
     */
    public function testCountMessagesNonExistentQueue()
    {
        $this->addDebugger();
        $this->assertEquals(0, $this->driver->countMessages($this->queueName));

        $this->removeDebugger();
        $this->assertFalse($this->service->hasQueue($this->queueName));
    }

    /**
     * @group integration
     */
    public function testPushMessage()
    {
        $queue = $this->service->createQueue($this->queueName);

        $this->addDebugger();

        $message = (object) ['foo' => 'bar'];
        $this->driver->pushMessage($this->queueName, $message);

        $this->removeDebugger();

        $this->service->setClientId();
        $claims = $queue->claimMessages();
        $this->assertCount(1, $claims);
        $claim = $claims->current();
        $this->assertEquals($message, $claim->getBody());
    }

    /**
     * @group integration
     */
    public function testPushMessageNonExistentQueue()
    {
        $this->addDebugger();

        $message = (object) ['foo' => 'bar'];
        $this->driver->pushMessage($this->queueName, $message);

        $this->removeDebugger();

        $this->service->setClientId();
        $this->assertTrue($this->service->hasQueue($this->queueName));
        $queue = $this->service->getQueue($this->queueName);
        $claims = $queue->claimMessages();
        $this->assertCount(1, $claims);
        $claim = $claims->current();
        $this->assertEquals($message, $claim->getBody());
    }

    /**
     * @group integration
     */
    public function testPopMessage()
    {
        $expected = [
            (object) ['foo' => 'bar'],
            (object) ['bar' => 'baz']
        ];
        $messages = array_map(function ($value) {
            return ['body' => $value, 'ttl' => 60];
        }, $expected);

        $queue = $this->service->createQueue($this->queueName);
        $queue->createMessages($messages);

        $this->addDebugger();

        list($actual, $id) = $this->driver->popMessage($this->queueName);
        $this->assertEquals(array_shift($expected), $actual);
        $this->assertNotEmpty($id);

        list($actual, $id) = $this->driver->popMessage($this->queueName);
        $this->assertEquals(array_shift($expected), $actual);
        $this->assertNotEmpty($id);
    }

    /**
     * @group integration
     */
    public function testPopMessageEmpty()
    {
        $this->service->createQueue($this->queueName);

        $this->addDebugger();

        $actual = $this->driver->popMessage($this->queueName, 1);
        $this->assertEquals([null, null], $actual);
    }

    /**
     * @group integration
     */
    public function testAcknowledgeMessage()
    {
        $expected = [
            (object) ['foo' => 'bar'],
            (object) ['bar' => 'baz']
        ];
        $messages = array_map(function ($value) {
            return ['body' => $value, 'ttl' => 60];
        }, $expected);

        $queue = $this->service->createQueue($this->queueName);
        $queue->createMessages($messages);

        $this->addDebugger();

        list($actual, $id) = $this->driver->popMessage($this->queueName);
        $this->driver->acknowledgeMessage($this->queueName, $id);

        $this->removeDebugger();
        $stats = $queue->getStats();
        $this->assertEquals(1, $stats->total);
    }

    /**
     * @group integration
     */
    public function testPeekQueue()
    {
        $queue = $this->service->createQueue($this->queueName);
        $expected = [];
        for ($i=0; $i<12; $i++) {
            $expected[] = (object) ['num' => $i];
        }
        // can only create 10 messages per-request
        $queue->createMessages($this->createMessages(array_slice($expected, 0, 10)));
        $queue->createMessages($this->createMessages(array_slice($expected, 10)));

        $this->addDebugger();

        $actual = $this->driver->peekQueue($this->queueName);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @group integration
     */
    public function testPeekQueueWithIndexAndLimit()
    {
        $queue = $this->service->createQueue($this->queueName);
        $expected = [];
        for ($i=0; $i<12; $i++) {
            $expected[] = (object) ['num' => $i];
        }
        // can only create 10 messages per-request
        $queue->createMessages($this->createMessages(array_slice($expected, 0, 10)));
        $queue->createMessages($this->createMessages(array_slice($expected, 10)));

        $this->addDebugger();

        $actual = $this->driver->peekQueue($this->queueName, 0, 5);
        $this->assertEquals(array_slice($expected, 0, 5), $actual);

        $actual = $this->driver->peekQueue($this->queueName, 10, 2);
        $this->assertEquals(array_slice($expected, 10, 2), $actual);
    }

    /**
     * @group integration
     */
    public function testRemoveQueue()
    {
        $this->service->createQueue($this->queueName);

        $this->addDebugger();

        $this->driver->removeQueue('test-queue');

        $this->removeDebugger();
        $this->assertFalse($this->service->hasQueue($this->queueName));
    }

    /**
     * @group integration
     */
    public function testRemoveNonexistentQueue()
    {
        $this->addDebugger();

        $this->driver->removeQueue('test-queue');

        $this->removeDebugger();
        $this->assertFalse($this->service->hasQueue($this->queueName));
    }

    /**
     * @group integration
     */
    public function testInfo()
    {
        $expected = [
            'name' => 'cloudQueues',
            'url_type' => $_ENV['RACKSPACE_URL_TYPE'],
            'region' => $_ENV['RACKSPACE_REGION'],
            'prefetch' => 2,
            'ttl' => 43200
        ];
        $actual = $this->driver->info();
        $this->assertEquals($expected, array_intersect_key($actual, $expected));
        $this->assertNotEmpty($actual['client_id']);
    }

    /**
     * @return Rackspace
     */
    private function newClient()
    {
        if (empty($_ENV['RACKSPACE_API_KEY'])) {
            $this->markTestSkipped("RACKSPACE_API_KEY not set");
        }
        return new Rackspace($_ENV['RACKSPACE_AUTH_URL'], [
            'username' => $_ENV['RACKSPACE_USERNAME'],
            'apiKey'   => $_ENV['RACKSPACE_API_KEY']
        ]);
    }

    /**
     * @return Rackspace
     */
    private function getClient()
    {
        return $this->client;
    }

    private function addDebugger()
    {
        if ($_ENV['DEBUG_API_REQUESTS']) {
            $this->getClient()->addSubscriber(LogPlugin::getDebugPlugin());
        }
    }

    private function removeDebugger()
    {
        $this->getClient()->getEventDispatcher()->removeSubscriber(LogPlugin::getDebugPlugin());
    }

    private function createMessages($bodies)
    {
        return array_map(function ($value) {
            return ['body' => $value, 'ttl' => 60];
        }, $bodies);
    }
}
