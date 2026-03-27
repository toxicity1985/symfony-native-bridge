<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\ClipboardManager;

class ClipboardManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private ClipboardManager $clipboard;

    protected function setUp(): void
    {
        $this->driver    = $this->createMock(NativeDriverInterface::class);
        $this->clipboard = new ClipboardManager($this->driver);
    }

    public function testReadTextDelegatesToDriver(): void
    {
        $this->driver->method('clipboardReadText')->willReturn('Hello');

        $this->assertSame('Hello', $this->clipboard->readText());
    }

    public function testReadTextReturnsNullWhenEmpty(): void
    {
        $this->driver->method('clipboardReadText')->willReturn(null);

        $this->assertNull($this->clipboard->readText());
    }

    public function testWriteTextDelegatesToDriver(): void
    {
        $this->driver->expects($this->once())->method('clipboardWriteText')->with('Hello');

        $this->clipboard->writeText('Hello');
    }

    public function testReadImageDelegatesToDriver(): void
    {
        $this->driver->method('clipboardReadImage')->willReturn('base64encodedpng==');

        $this->assertSame('base64encodedpng==', $this->clipboard->readImage());
    }

    public function testReadImageReturnsNullWhenEmpty(): void
    {
        $this->driver->method('clipboardReadImage')->willReturn(null);

        $this->assertNull($this->clipboard->readImage());
    }

    public function testWriteImageDelegatesToDriver(): void
    {
        $this->driver->expects($this->once())->method('clipboardWriteImage')->with('/path/to/image.png');

        $this->clipboard->writeImage('/path/to/image.png');
    }

    public function testClearDelegatesToDriver(): void
    {
        $this->driver->expects($this->once())->method('clipboardClear');

        $this->clipboard->clear();
    }

    public function testHasTextReturnsTrueWhenTextPresent(): void
    {
        $this->driver->method('clipboardReadText')->willReturn('Hello');

        $this->assertTrue($this->clipboard->hasText());
    }

    public function testHasTextReturnsFalseWhenEmpty(): void
    {
        $this->driver->method('clipboardReadText')->willReturn(null);

        $this->assertFalse($this->clipboard->hasText());
    }

    public function testHasImageReturnsTrueWhenImagePresent(): void
    {
        $this->driver->method('clipboardReadImage')->willReturn('base64==');

        $this->assertTrue($this->clipboard->hasImage());
    }

    public function testHasImageReturnsFalseWhenEmpty(): void
    {
        $this->driver->method('clipboardReadImage')->willReturn(null);

        $this->assertFalse($this->clipboard->hasImage());
    }
}
