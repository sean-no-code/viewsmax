<?php

namespace App\Support;

use App\Exceptions\MediaReadException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only PSR-7 stream over a media file resource that fails fast instead
 * of holding a worker slot.
 *
 * Post media is streamed from the media disk (R2 in production) straight into
 * the platform upload. Without a bound, a stalled read looks exactly like a
 * slow upload: the job eventually dies on $timeout with a generic message.
 * Every read here carries a socket timeout, and a stall or a short file (fewer
 * bytes than the declared size) throws MediaReadException so the user is told
 * to re-upload rather than "Timed out".
 *
 * Unlike Guzzle's Stream this never closes the resource it was given: the
 * publish jobs own the handle and close it in their finally blocks.
 */
final class BoundedMediaStream implements StreamInterface
{
    /** Seconds one read may block before it counts as stalled. */
    public const READ_TIMEOUT = 120;

    private int $delivered = 0;

    /**
     * @param  resource  $resource  readable stream of the media file
     * @param  int  $expectedBytes  the size declared to the platform (Storage::size)
     */
    public function __construct(private $resource, private readonly int $expectedBytes, int $timeout = self::READ_TIMEOUT)
    {
        // Only socket-backed streams can stall. stream_set_timeout returns
        // false for local files and php://temp buffers, which is fine.
        @stream_set_timeout($resource, $timeout);
    }

    public function read($length): string
    {
        if ($length <= 0) {
            return '';
        }

        $bytes = fread($this->resource, (int) $length);
        if ($bytes === false) {
            $bytes = '';
        }

        if (stream_get_meta_data($this->resource)['timed_out'] ?? false) {
            $this->fail("read stalled after {$this->delivered} of {$this->expectedBytes} bytes");
        }

        $this->delivered += strlen($bytes);

        if ($bytes === '' && $this->delivered < $this->expectedBytes) {
            $this->fail("file ended after {$this->delivered} of {$this->expectedBytes} bytes");
        }

        return $bytes;
    }

    private function fail(string $detail): never
    {
        Log::warning('Media read failed while publishing', ['detail' => $detail]);

        throw new MediaReadException($detail);
    }

    /**
     * The size the caller declared to the platform. fstat on a detached network
     * stream reports nothing useful, and Guzzle needs this for Content-Length.
     */
    public function getSize(): ?int
    {
        return $this->expectedBytes;
    }

    public function eof(): bool
    {
        return feof($this->resource);
    }

    public function tell(): int
    {
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $pos;
    }

    public function isSeekable(): bool
    {
        return (bool) (stream_get_meta_data($this->resource)['seekable'] ?? false);
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (! $this->isSeekable() || fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek to stream position '.$offset);
        }
        $this->delivered = $this->tell();
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('Cannot write to a media read stream');
    }

    /**
     * Read to the end. Deliberately not gated on eof(): a short file reaches
     * EOF early, and only a further read() can tell that from a complete one.
     */
    public function getContents(): string
    {
        $out = '';
        while (($chunk = $this->read(1024 * 1024)) !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    public function __toString(): string
    {
        if ($this->isSeekable()) {
            $this->rewind();
        }

        return $this->getContents();
    }

    public function getMetadata($key = null)
    {
        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }
}
