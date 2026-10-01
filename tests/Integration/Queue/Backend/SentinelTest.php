<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Integration\Queue\Backend;

use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Queue\Backend\Sentinel;
use Piwik\Plugins\QueuedTracking\Queue\Factory;

/**
 * @group QueuedTracking
 * @group Redis
 * @group RedisTest
 * @group Queue
 * @group Tracker
 */
class SentinelTest extends RedisTest
{
    private const SENTINEL_HOST = '127.0.0.1';
    private const SENTINEL_PORT = 26379;
    private const ACL_USERNAME = 'MyUser';
    private const ACL_PASSWORD = 'MySecret';

    public function tearDown(): void
    {
        Config::getInstance()->QueuedTracking = [];
        parent::tearDown();
    }

    protected function createRedisBackend()
    {
        $settings = Factory::getSettings();

        $this->enableRedisSentinel();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisPort->setValue('26379');

        $sentinel = Factory::makeBackend();

        $this->assertTrue($sentinel instanceof Sentinel);

        return $sentinel;
    }

    public function test_canCreateInstanceWithMultipleSentinelAndFallback()
    {
        $settings = Factory::getSettings();

        $this->enableRedisSentinel();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisHost->setValue('127.0.0.1,127.0.0.2,127.0.0.1');
        $settings->redisPort->setValue('26378,26379,26379');

        $sentinel = Factory::makeBackendFromSettings($settings);
        $this->assertTrue($sentinel->testConnection());
    }

    public function test_connect_ShouldThrowException_IfNotExactSameHostAndPortNumbersGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('QueuedTracking_NumHostsNotMatchNumPorts');

        $this->enableRedisSentinel();

        $settings = Factory::getSettings();
        $this->assertTrue($settings->isUsingSentinelBackend());

        $settings->redisHost->setValue('127.0.0.1,127.0.0.1');
        $settings->redisPort->setValue('26378,26379,26379');

        $sentinel = Factory::makeBackendFromSettings($settings);
        $sentinel->get('test');
    }

    public function test_connect_shouldAuthenticateWithUsername_OnMaster()
    {
        $master = $this->createAdminClientForMaster();
        $this->createAclUser($master);

        try {
            $backend = $this->makeBackendWithCredentials(self::ACL_USERNAME, self::ACL_PASSWORD, false);

            $this->assertTrue($backend->testConnection());
            $this->assertSame(self::ACL_USERNAME, $backend->getConnection()->rawCommand('ACL', ['WHOAMI']));
        } finally {
            $this->deleteAclUser($master);
        }
    }

    public function test_connect_shouldFail_IfUsernameIsWrong_OnMaster()
    {
        $master = $this->createAdminClientForMaster();
        $this->createAclUser($master);

        try {
            $backend = $this->makeBackendWithCredentials('OtherUser', self::ACL_PASSWORD, false);

            $this->assertFalse($backend->testConnection());
        } finally {
            $this->deleteAclUser($master);
        }
    }

    public function test_connect_shouldAuthenticateWithUsername_OnSentinelAndMaster()
    {
        $master = $this->createAdminClientForMaster();
        $sentinel = $this->createAdminClientForSentinel();
        $this->createAclUser($master);
        $this->createAclUser($sentinel);
        // Only MyUser can talk to the sentinel now, so a connect without the username must fail
        $sentinel->rawCommand('ACL', ['SETUSER', 'default', 'off']);

        try {
            $backend = $this->makeBackendWithCredentials(self::ACL_USERNAME, self::ACL_PASSWORD, true);

            $this->assertTrue($backend->testConnection());
            $this->assertSame(self::ACL_USERNAME, $backend->getConnection()->rawCommand('ACL', ['WHOAMI']));
        } finally {
            $sentinel->rawCommand('ACL', ['SETUSER', 'default', 'on']);
            $this->deleteAclUser($sentinel);
            $this->deleteAclUser($master);
        }
    }

    public function test_connect_shouldFail_IfUsernameIsWrong_OnSentinel()
    {
        $master = $this->createAdminClientForMaster();
        $sentinel = $this->createAdminClientForSentinel();
        $this->createAclUser($master);
        $this->createAclUser($sentinel);
        $sentinel->rawCommand('ACL', ['SETUSER', 'default', 'off']);

        try {
            $backend = $this->makeBackendWithCredentials('OtherUser', self::ACL_PASSWORD, true);

            $this->assertFalse($backend->testConnection());
        } finally {
            $sentinel->rawCommand('ACL', ['SETUSER', 'default', 'on']);
            $this->deleteAclUser($sentinel);
            $this->deleteAclUser($master);
        }
    }

    private function makeBackendWithCredentials(string $username, string $password, bool $authOnSentinel): Sentinel
    {
        $settings = Factory::getSettings();

        $this->enableRedisSentinel();
        $settings->redisHost->setValue(self::SENTINEL_HOST);
        $settings->redisPort->setValue((string) self::SENTINEL_PORT);
        $settings->redisUsername->setValue($username);
        $settings->redisPassword->setValue($password);
        $settings->usePasswordForSentinelInstances->setValue($authOnSentinel);

        return Factory::makeBackendFromSettings($settings);
    }

    private function createAdminClientForSentinel(): \Credis_Client
    {
        $client = new \Credis_Client(self::SENTINEL_HOST, self::SENTINEL_PORT);
        $client->forceStandalone();

        return $client;
    }

    private function createAdminClientForMaster(): \Credis_Client
    {
        [$host, $port] = $this->getRedisMasterHostAndPort();

        return new \Credis_Client($host, $port);
    }

    protected function getRedisMasterHostAndPort(): array
    {
        $address = (new \Credis_Sentinel($this->createAdminClientForSentinel()))->getMasterAddressByName('mymaster');

        return [$address[0], (int) $address[1]];
    }

    private function createAclUser(\Credis_Client $client): void
    {
        $client->rawCommand('ACL', ['SETUSER', self::ACL_USERNAME, 'reset', 'on', '>' . self::ACL_PASSWORD, '~*', '&*', '+@all']);
    }

    private function deleteAclUser(\Credis_Client $client): void
    {
        $client->rawCommand('ACL', ['DELUSER', self::ACL_USERNAME]);
    }
}
