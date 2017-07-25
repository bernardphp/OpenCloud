<?php
/**
 * @author Matt Kynaston <matt@kynx.org>
 * @license MIT
 */

namespace BernardTest\Driver;

use Guzzle\Http\Message\Request;
use Bernard\Driver\OpenCloudDriver;
use OpenCloud\Queues\Service;
use OpenCloud\Rackspace;
use PHPUnit_Framework_TestCase as TestCase;

class OpenCloudDriverTest extends TestCase
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
    /**
     * @var OpenCloudMockSubscriber
     */
    private $currentMockSubscriber;

    public function setUp()
    {
        parent::setUp();

        $this->client = $this->newClient();
        $this->client->addSubscriber(new OpenCloudMockSubscriber());
        $this->client->authenticate();

        $this->service = $this->getClient()->queuesService('cloudQueues', 'ORD');
        $this->service->setClientId('my-client');
        $this->driver = new OpenCloudDriver($this->service);
    }

    public function tearDown()
    {
        $this->client = null;
        $this->driver = null;
    }

    public function testPassingQueuesToConstructor()
    {
        $this->addResponses(['queue_stats_2']);

        $queues = ['test-queue'];
        $driver = new OpenCloudDriver($this->service, $queues);
        $actual = $driver->countMessages('test-queue');
        $this->assertEquals(2, $actual);
    }

    public function testListQueues()
    {
        $this->addResponses(['list_queues_1', 'list_queues_2']);

        $list = $this->driver->listQueues();
        $this->assertCount(12, $list);
        $this->assertEquals('foo', array_pop($list));
    }

    public function testListNonExistentQueue()
    {
        $this->addResponses('204');
        $list = $this->driver->listQueues();
        $this->assertEquals([], $list);
    }

    /**
     * @expectedException \Guzzle\Http\Exception\BadResponseException
     */
    public function testListQueuesThrowsException()
    {
        $this->addResponses('500');
        $this->driver->listQueues();
    }

    public function testCreateQueue()
    {
        $this->addResponses('create_queue');

        $this->driver->createQueue('foo');
        $response = $this->getLastRequest()->getResponse();
        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testCountMessages()
    {
        $this->addResponses(['get_queue', 'queue_stats_2']);
        $this->assertEquals(2, $this->driver->countMessages('test-queue'));
    }

    public function testCountMessagesNonExistentQueue()
    {
        $this->addResponses('404');
        $this->assertEquals(0, $this->driver->countMessages('test-queue'));
    }

    /**
     * @expectedException \Guzzle\Http\Exception\BadResponseException
     */
    public function testCountMessagesThrowsException()
    {
        $this->addResponses('500');
        $this->driver->countMessages('test-queue');
    }

    public function testPushMessage()
    {
        $this->addResponses(['get_queue', 'create_messages', 'get_messages_by_id']);

        $message = (object) ['foo' => 'bar'];
        $this->driver->pushMessage('test-queue', $message);
        $response = $this->getLastRequest()->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPushMessageNonExistentQueue()
    {
        $this->addResponses([
            '404',
            'create_queue',
            'create_messages',
            'get_messages_by_id'
        ]);

        $message = (object) ['foo' => 'bar'];
        $this->driver->pushMessage('test-queue', $message);
        $response = $this->getLastRequest()->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPopMessage()
    {
        $this->addResponses(['get_queue', 'claim_messages']);

        $expected = [
            (object) ['foo' => 'bar'],
            (object) ['bar' => 'baz']
        ];

        list($actual, $id) = $this->driver->popMessage('test-queue');
        $this->assertEquals(array_shift($expected), $actual);
        $this->assertNotEmpty($id);

        list($actual, $id) = $this->driver->popMessage('test-queue');
        $this->assertEquals(array_shift($expected), $actual);
        $this->assertNotEmpty($id);
    }

    public function testPopMessageEmpty()
    {
        $this->addResponses(['get_queue', '204']);

        $actual = $this->driver->popMessage('test-queue', 1);
        $this->assertEquals([null, null], $actual);
    }

    public function testPopMessageWithInterval()
    {
        $this->addResponses(['get_queue', '204', 'claim_messages']);

        list($actual, $id) = $this->driver->popMessage('test-queue', 2000);
        $this->assertEquals((object) ['foo' => 'bar'], $actual);
    }

    public function testAcknowledgeMessage()
    {
        $this->addResponses(['get_queue', 'claim_messages', '204']);

        list($actual, $id) = $this->driver->popMessage('test-queue');
        $this->driver->acknowledgeMessage('test-queue', $id);
        $response = $this->getLastRequest()->getResponse();
        $this->assertEquals(204, $response->getStatusCode());

        $property = new \ReflectionProperty(get_class($this->driver), 'claims');
        $property->setAccessible(true);
        $claims = $property->getValue($this->driver);
        $this->assertCount(1, $claims);
    }

    public function testPeekQueue()
    {
        $this->addResponses([
            'get_queue',
            'list_messages_1',
            'list_messages_2',
            '204'
        ]);

        $expected = [];
        for ($i=0; $i<12; $i++) {
            $expected[] = (object) ['num' => $i];
        }

        $actual = $this->driver->peekQueue('test-queue');
        $this->assertEquals($expected, $actual);
    }

    public function testPeekQueueWithIndexAndLimit()
    {
        $this->addResponses([
            'get_queue',
            'list_messages_1',
            'list_messages_2',
            '204'
        ]);

        $expected = [];
        for ($i=0; $i<12; $i++) {
            $expected[] = (object) ['num' => $i];
        }

        $actual = $this->driver->peekQueue('test-queue', 0, 5);
        $this->assertEquals(array_slice($expected, 0, 5), $actual);

        $actual = $this->driver->peekQueue('test-queue', 10, 2);
        $this->assertEquals(array_slice($expected, 10, 2), $actual);
    }

    public function testRemoveQueue()
    {
        $this->addResponses(['get_queue', '204']);

        $this->driver->removeQueue('test-queue');
        $response = $this->getLastRequest()->getResponse();
        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @expectedException \Guzzle\Http\Exception\BadResponseException
     */
    public function testRemoveNonexistentQueue()
    {
        $this->addResponses('404');
        $this->driver->removeQueue('test-queue');
    }

    /**
     * @expectedException \Guzzle\Http\Exception\BadResponseException
     */
    public function testRemoveQueueThrowsException()
    {
        $this->addResponses('500');
        $this->driver->removeQueue('test-queue');
    }

    public function testInfo()
    {
        $expected = [
            'client_id' => 'my-client',
            'name' => 'cloudQueues',
            'url' => 'https://ord.queues.api.rackspacecloud.com/v1/123456',
            'url_type' => 'publicURL',
            'region' => 'ORD',
            'prefetch' => 2,
            'ttl' => 43200
        ];
        $actual = $this->driver->info();
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return Rackspace
     */
    private function newClient()
    {
        return new Rackspace(Rackspace::US_IDENTITY_ENDPOINT, [
            'username' => 'foo',
            'apiKey'   => 'bar'
        ]);
    }

    /**
     * @return Rackspace
     */
    private function getClient()
    {
        return $this->client;
    }

    private function getTestFilePath($file)
    {
        return __DIR__ . '/_response/' . $file . '.resp';
    }

    private function addResponses($responses)
    {
        $responses = (array) $responses;
        array_walk($responses, function (&$response) {
            $response = $this->getTestFilePath($response);
        });

        $this->currentMockSubscriber = new OpenCloudMockSubscriber($responses, true);
        $this->getClient()->addSubscriber($this->currentMockSubscriber);
    }

    /**
     * @return Request
     */
    private function getLastRequest()
    {
        $requests = $this->currentMockSubscriber->getReceivedRequests();
        $request = array_pop($requests);
        $this->assertInstanceOf('\Guzzle\Http\Message\Request', $request);
        return $request;
    }
}
