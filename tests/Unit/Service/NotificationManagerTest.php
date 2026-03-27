<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\NotificationManager;

class NotificationManagerTest extends TestCase
{
    public function testSendCreatesOptionsAndDelegates(): void
    {
        $driver = $this->createMock(NativeDriverInterface::class);
        $driver->expects($this->once())
            ->method('sendNotification')
            ->with($this->callback(fn($o) => $o->title === 'Hello' && $o->body === 'World'));

        $manager = new NotificationManager($driver);
        $manager->send('Hello', 'World');
    }
}
