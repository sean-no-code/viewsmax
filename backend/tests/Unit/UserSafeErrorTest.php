<?php

namespace Tests\Unit;

use App\Support\UserSafeError;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

class UserSafeErrorTest extends TestCase
{
    private const FALLBACK = 'Could not fetch that video.';

    public function test_plain_runtime_and_invalid_argument_messages_pass_through(): void
    {
        $this->assertSame('Video not found', UserSafeError::message(new \RuntimeException('Video not found'), self::FALLBACK));
        $this->assertSame('Bad link', UserSafeError::message(new \InvalidArgumentException('Bad link'), self::FALLBACK));
    }

    public function test_connection_exception_maps_to_friendly_timeout(): void
    {
        $message = UserSafeError::message(new ConnectionException('cURL error 28: Operation timed out after 60003 milliseconds'), self::FALLBACK);

        $this->assertStringNotContainsString('cURL', $message);
        $this->assertStringContainsString('too long', $message);
    }

    public function test_query_exception_sql_never_reaches_users(): void
    {
        // QueryException extends RuntimeException — must NOT be treated as safe.
        $e = new QueryException('outlier_db', 'insert into "videos" (...)', [], new \Exception('value too long for type character varying(255)'));

        $this->assertSame(self::FALLBACK, UserSafeError::message($e, self::FALLBACK));
        $this->assertFalse(UserSafeError::isSafe($e));
    }

    public function test_generic_throwables_get_the_fallback(): void
    {
        $this->assertSame(self::FALLBACK, UserSafeError::message(new \Exception('YouTube API Error: {...}'), self::FALLBACK));
        $this->assertSame(self::FALLBACK, UserSafeError::message(new \RuntimeException(''), self::FALLBACK));
    }
}
