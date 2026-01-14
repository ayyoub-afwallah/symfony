<?php

namespace Symfony\Bridge\Doctrine\Tests\Middleware\Debug;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Driver;

class PrimaryReadReplicaDriverTest extends TestCase
{
    public function testConnectDetectsPrimary()
    {
        $debugDataHolder = new DebugDataHolder();
        $driverMock = $this->createMock(DriverInterface::class);
        $connectionMock = $this->createMock(ConnectionInterface::class);
        $resultMock = $this->createMock(Result::class);
        
        $driverMock->method('connect')->willReturn($connectionMock);
        $connectionMock->method('query')->willReturn($resultMock);

        $driver = new Driver(
            $driverMock,
            $debugDataHolder,
            null,
            'default'
        );
        

        
        // Mocking PrimaryReadReplicaConnection behavior
        $connection = new TestPrimaryReadReplicaConnection($driver);

        // 1. Test Primary detection
        $connection->ensureConnectedToPrimary();
        $debugConnection = $connection->lastConnection;
        
        // Execute a query to trigger DebugDataHolder logging
        $debugConnection->query('SELECT 1');
        
        $data = $debugDataHolder->getData();
        $this->assertArrayHasKey('default', $data);
        $this->assertCount(1, $data['default']);
        $this->assertTrue($data['default'][0]['isPrimary']);
        
        $debugDataHolder->reset();
        
        // 2. Test Replica detection
        $connection->ensureConnectedToReplica();
        $debugConnection = $connection->lastConnection;
        
        $debugConnection->query('SELECT 1');
        
        $data = $debugDataHolder->getData();
        $this->assertArrayHasKey('default', $data);
        $this->assertCount(1, $data['default']);
        $this->assertFalse($data['default'][0]['isPrimary']);
    }
}

class TestPrimaryReadReplicaConnection extends PrimaryReadReplicaConnection {
    private $debugDriver;
    public $lastConnection;
    
    public function __construct($driver) {
        $this->debugDriver = $driver;
    }
    
    public function ensureConnectedToPrimary(): void {
        $this->lastConnection = $this->debugDriver->connect(['primary' => true]);
    }
    
    public function ensureConnectedToReplica(): void {
        $this->lastConnection = $this->debugDriver->connect(['replica' => true]);
    }
    
    protected function connectTo(string $connectionName): \Doctrine\DBAL\Driver\Connection
    {
        return $this->debugDriver->connect([]);
    }
}
