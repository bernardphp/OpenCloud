<?php
/**
 * @author Matt Kynaston <matt@kynx.org>
 * @license MIT
 */

namespace BernardTest\Driver;

// this isn't autoloaded
use OpenCloud\Tests\MockSubscriber;

require_once __DIR__ . '/../vendor/rackspace/php-opencloud/tests/OpenCloud/Tests/MockSubscriber.php';

class OpenCloudMockSubscriber extends MockSubscriber
{
}
