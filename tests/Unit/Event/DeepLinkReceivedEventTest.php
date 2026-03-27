<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Event\DeepLinkReceivedEvent;

class DeepLinkReceivedEventTest extends TestCase
{
    public function testUrlIsParsedCorrectly(): void
    {
        $event = new DeepLinkReceivedEvent('myapp://dashboard?ref=email&tab=settings');

        $this->assertSame('myapp://dashboard?ref=email&tab=settings', $event->url);
        $this->assertSame('myapp', $event->scheme);
        $this->assertSame('dashboard', $event->host);
        $this->assertSame('', $event->path);
        $this->assertSame(['ref' => 'email', 'tab' => 'settings'], $event->query);
    }

    public function testUrlWithPath(): void
    {
        $event = new DeepLinkReceivedEvent('myapp://product/42/details');

        $this->assertSame('product', $event->host);
        $this->assertSame('/42/details', $event->path);
        $this->assertSame([], $event->query);
    }

    public function testUrlWithNoQueryString(): void
    {
        $event = new DeepLinkReceivedEvent('myapp://home');

        $this->assertSame('home', $event->host);
        $this->assertSame([], $event->query);
    }

    public function testGetEventName(): void
    {
        $this->assertSame('native.protocol.deep_link', DeepLinkReceivedEvent::NAME);
        $this->assertSame(DeepLinkReceivedEvent::NAME, DeepLinkReceivedEvent::getEventName());
    }
}
