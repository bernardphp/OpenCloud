# Bernard OpenCloud Driver

This driver implements the Queues part of Rackspace or other OpenStack-based cloud providers, using 
[php-opencloud](https://github.com/rackspace/php-opencloud). It supports prefetching messages, which reduces the number 
of HTTP requests. It also supports passing a list of known queues to the constructor. Passing known queue names saves an HTTP 
request to the provider.

For full details on using Bernard see the [documentation](http://bernard.readthedocs.io).

For full information on Opencloud Queues, refer to the [php-opencloud](http://docs.php-opencloud.com/en/latest/services/queues/index.html)
documentation.

**Important**

You need to create an account with your provider, consisting of a username and API key, along with the region 
('ORD', 'LON', etc).

**Important**

When using prefetching, the TTL value for each message should be greater than the time it takes to consume all of 
the fetched messages. If one message takes 10 seconds to consume and the driver is prefetching 5 messages the TTL must 
be greater than 50 seconds.


# Installation

Install via [Composer](https://getcomposer.org):

```
composer require bernard/OpenCloud
```

Note that the current version of php-opencloud has a dependency on Guzzle 3. This will cause a warning when installing.

Version 2 of php-opencloud is in the works and uses a supported version of Guzzle. This driver will be updated to use it 
once it is stable.

## Quickstart

```php
use Bernard\Driver\OpenCloudDriver;
use OpenCloud\Rackspace;

$client = new Rackspace(Rackspace::US_IDENTITY_ENDPOINT, array(
  'username' => '{username}',
  'apiKey'   => '{apiKey}',
));

$service = $client->queuesService('cloudQueues', 'ORD');

// you _must_ call `setClientId()` - if empty a UUID will be automatically generated
$service->setClientId();

$driver = new OpenCloudDriver($service);

// or, passing a known queue name
$driver = new OpenCloudDriver($service, ['my-queue']);

// or, using prefetching...
$driver = new OpenCloudDriver($service, [], 10);

// or, using prefetching and specifying a one hour TTL for messages that will be created / claimed
$driver = new OpenCloudDriver($service, [], null, 3600);
```

## Tests

The default unit tests will mock the responses from the API.

To test against a real provider you will need to copy `phpunit.xml.dist` to `phpunit.xml` and edit it, adding your 
credentials. Then run the "integration" group:

```
./vendor/bin/phpunit --group integarion
```

You can capture the requests and responses sent to the provider by setting the `DEBUG_API_REQUESTS` environment variable
in `phpunit.xml`. This is particularly useful when generating mock responses for other tests.
