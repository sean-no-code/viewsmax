<?php

namespace Tests\Unit;

use App\Exceptions\MediaReadException;
use App\Support\BoundedMediaStream;
use Tests\TestCase;

class BoundedMediaStreamTest extends TestCase
{
    /** @return resource */
    private function temp(string $bytes)
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);

        return $fh;
    }

    public function test_reads_a_file_of_the_declared_size_through(): void
    {
        $fh = $this->temp('0123456789');
        $stream = new BoundedMediaStream($fh, 10);

        $this->assertSame(10, $stream->getSize(), 'Content-Length comes from the declared size');
        $this->assertSame('0123456789', $stream->getContents());
        $this->assertTrue($stream->eof());

        unset($stream);
        $this->assertTrue(is_resource($fh), 'the caller owns the handle; the stream must not close it');
        fclose($fh);
    }

    public function test_a_file_shorter_than_declared_fails_with_the_reupload_message(): void
    {
        $stream = new BoundedMediaStream($this->temp('0123456789'), 20);

        $this->assertSame('0123456789', $stream->read(1024));

        try {
            $stream->read(1024);
            $this->fail('A short file must not look like a clean EOF.');
        } catch (MediaReadException $e) {
            $this->assertSame(MediaReadException::MESSAGE, $e->getMessage());
            $this->assertStringContainsString('10 of 20 bytes', $e->detail);
        }
    }

    public function test_a_stalled_socket_read_fails_instead_of_blocking(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($writer, 'abc'); // then the writer goes quiet without closing

        $stream = new BoundedMediaStream($reader, 100, timeout: 1);
        $this->assertSame('abc', $stream->read(3));

        $started = microtime(true);
        try {
            $stream->read(10);
            $this->fail('A stalled read must time out.');
        } catch (MediaReadException $e) {
            $this->assertStringContainsString('stalled after 3 of 100 bytes', $e->detail);
        }
        $this->assertLessThan(5, microtime(true) - $started, 'the read must give up at the timeout');

        fclose($reader);
        fclose($writer);
    }

    public function test_rewinding_resets_the_delivered_count(): void
    {
        $stream = new BoundedMediaStream($this->temp('0123456789'), 10);

        $this->assertSame('0123456789', (string) $stream);
        $this->assertSame('0123456789', (string) $stream, 'Guzzle may rewind and re-read a seekable body');
    }
}
