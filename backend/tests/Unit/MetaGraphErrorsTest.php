<?php

namespace Tests\Unit;

use App\Exceptions\TransientPublishException;
use App\Services\Social\Data\MetaGraphOutcome;
use App\Services\Social\MetaGraphErrors;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The classifier is shared by the Instagram, Facebook and Threads publish
 * paths: a wrong kind either retries a post Meta rejected for good or marks
 * a healthy account needs_reauth over a rate limit.
 */
class MetaGraphErrorsTest extends TestCase
{
    /** @param  array<string, mixed>  $error */
    private function graphError(array $error, int $status = 400): MetaGraphOutcome
    {
        return MetaGraphErrors::classify($status, ['error' => $error]);
    }

    /* ---------------- retry ---------------- */

    public function test_http_5xx_and_429_are_retried_whatever_the_body_says(): void
    {
        $this->assertSame(MetaGraphOutcome::RETRY, MetaGraphErrors::classify(503, null)->kind);
        $this->assertSame(MetaGraphOutcome::RETRY, MetaGraphErrors::classify(500, ['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]])->kind);
        $this->assertSame(MetaGraphOutcome::RETRY, MetaGraphErrors::classify(429, ['error' => ['message' => 'Too many calls', 'code' => 4]])->kind);
    }

    public function test_unknown_and_service_codes_are_retried(): void
    {
        $this->assertSame(MetaGraphOutcome::RETRY, $this->graphError(['message' => 'An unknown error has occurred.', 'code' => 1])->kind);
        $this->assertSame(MetaGraphOutcome::RETRY, $this->graphError(['message' => 'Service temporarily unavailable', 'code' => 2])->kind);
        $this->assertSame(MetaGraphOutcome::RETRY, $this->graphError(['message' => 'An unknown error occurred', 'code' => 100])->kind);
    }

    public function test_throw_if_retry_hands_a_transient_outcome_to_the_queue(): void
    {
        $outcome = MetaGraphErrors::classify(502, null);

        try {
            $outcome->throwIfRetry();
            $this->fail('expected TransientPublishException');
        } catch (TransientPublishException $e) {
            $this->assertSame($outcome->message, $e->getMessage());
            $this->assertStringContainsString('502', $e->getMessage());
        }
    }

    public function test_throw_if_retry_returns_the_outcome_when_it_is_final(): void
    {
        $outcome = $this->graphError(['message' => 'Invalid parameter', 'code' => 100]);

        $this->assertSame($outcome, $outcome->throwIfRetry());
    }

    /* ---------------- reauth ---------------- */

    public function test_dead_tokens_need_a_reconnect(): void
    {
        $cases = [
            ['message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190],
            ['message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463],
            ['message' => 'The user changed their password', 'code' => 102, 'error_subcode' => 460],
            ['message' => 'Invalid session', 'code' => 102, 'error_subcode' => 467],
            ['message' => 'Error validating access token: The user has not authorized application', 'code' => 102],
            ['message' => 'REVOKED_ACCESS_TOKEN'],
            ['message' => 'The session has been invalidated because the user changed their password.'],
        ];

        foreach ($cases as $error) {
            $outcome = $this->graphError($error);
            $this->assertSame(MetaGraphOutcome::REAUTH, $outcome->kind, json_encode($error));
            $this->assertTrue($outcome->needsReauth());
            $this->assertFalse($outcome->isCheckpoint());
            $this->assertStringContainsStringIgnoringCase('reconnect the account', $outcome->message);
        }
    }

    /* ---------------- checkpoint ---------------- */

    public function test_checkpointed_accounts_are_told_to_log_in_on_meta_first(): void
    {
        $cases = [
            ['message' => 'You cannot access the app till you log in to www.instagram.com and follow the instructions given.', 'code' => 190, 'error_subcode' => 2207051],
            ['message' => 'Session key is malformed', 'code' => 190],
        ];

        foreach ($cases as $error) {
            $outcome = $this->graphError($error);
            $this->assertSame(MetaGraphOutcome::CHECKPOINT, $outcome->kind, json_encode($error));
            $this->assertTrue($outcome->needsReauth());
            $this->assertTrue($outcome->isCheckpoint());
            $this->assertStringContainsString('instagram.com', $outcome->message);
            $this->assertStringContainsStringIgnoringCase('reconnect', $outcome->message);
        }
    }

    /* ---------------- rate limit → fail ---------------- */

    public function test_daily_rate_limits_fail_without_retry_or_reauth(): void
    {
        $cases = [
            ['message' => '(#4) Application request limit reached', 'code' => 4],
            ['message' => '(#17) User request limit reached', 'code' => 17],
            ['message' => '(#32) Page request limit reached', 'code' => 32],
            ['message' => 'Calls to this api have exceeded the rate limit.', 'code' => 613],
            ['message' => 'Page request limit reached'],
        ];

        foreach ($cases as $error) {
            $outcome = $this->graphError($error, 403);
            $this->assertSame(MetaGraphOutcome::FAIL, $outcome->kind, json_encode($error));
            $this->assertFalse($outcome->isRetry());
            $this->assertFalse($outcome->needsReauth());
            $this->assertStringContainsString('rate-limited for today', $outcome->message);
        }
    }

    /* ---------------- permissions → fail ---------------- */

    public function test_missing_permissions_fail_with_a_permissions_message_not_a_reauth(): void
    {
        $cases = [
            ['message' => '(#10) Application does not have permission for this action', 'code' => 10],
            ['message' => '(#200) The user hasn\'t authorized the application to perform this action', 'code' => 200],
            ['message' => 'Not enough permissions to post', 'code' => 100],
            ['message' => 'The account is missing some permissions to perform this action', 'code' => 190],
        ];

        foreach ($cases as $error) {
            $outcome = $this->graphError($error);
            $this->assertSame(MetaGraphOutcome::FAIL, $outcome->kind, json_encode($error));
            $this->assertFalse($outcome->needsReauth());
            $this->assertStringContainsString('missing permissions', $outcome->message);
            $this->assertStringContainsStringIgnoringCase('tick every box', $outcome->message);
        }
    }

    /* ---------------- everything else ---------------- */

    public function test_unknown_errors_fail_with_metas_message_never_the_raw_json(): void
    {
        $outcome = $this->graphError(['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 2207026, 'fbtrace_id' => 'AbC']);

        $this->assertSame(MetaGraphOutcome::FAIL, $outcome->kind);
        $this->assertSame('Meta rejected the post: Invalid parameter', $outcome->message);
        $this->assertStringNotContainsString('fbtrace_id', $outcome->message);
        $this->assertStringNotContainsString('{', $outcome->message);
        // The log reason keeps the identifiers that the user message drops.
        $this->assertSame('HTTP 400 type=OAuthException code=100 subcode=2207026: Invalid parameter', $outcome->raw);
    }

    public function test_a_non_json_body_fails_with_the_http_status(): void
    {
        $outcome = MetaGraphErrors::classify(400, null, [], '<html>Bad Request</html>');

        $this->assertSame(MetaGraphOutcome::FAIL, $outcome->kind);
        $this->assertSame('Meta rejected the post: HTTP 400', $outcome->message);
        $this->assertSame('HTTP 400: <html>Bad Request</html>', $outcome->raw);
    }

    public function test_from_response_reads_status_and_body_from_the_http_client_response(): void
    {
        $json = json_encode(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463]]);
        $response = new Response(new PsrResponse(400, ['Content-Type' => 'application/json'], $json));

        $outcome = MetaGraphErrors::fromResponse($response);

        $this->assertSame(MetaGraphOutcome::REAUTH, $outcome->kind);
        $this->assertStringContainsString('subcode=463', $outcome->raw);

        $this->assertSame(MetaGraphOutcome::RETRY, MetaGraphErrors::fromResponse(new Response(new PsrResponse(502, [], 'Bad Gateway')))->kind);
    }

    /* ---------------- per-platform table ---------------- */

    public function test_table_subcode_beats_code_beats_substring(): void
    {
        $table = [
            463 => [MetaGraphOutcome::FAIL, 'by subcode'],
            190 => [MetaGraphOutcome::CHECKPOINT, 'by code'],
            'Session has expired' => [MetaGraphOutcome::RETRY, 'by text'],
        ];
        $error = ['message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463];

        $this->assertSame('by subcode', MetaGraphErrors::classify(400, ['error' => $error], $table)->message);

        unset($error['error_subcode']);
        $this->assertSame('by code', MetaGraphErrors::classify(400, ['error' => $error], $table)->message);

        $error['code'] = 102;
        $this->assertSame('by text', MetaGraphErrors::classify(400, ['error' => $error], $table)->message);
    }

    public function test_table_hit_beats_every_generic_rule(): void
    {
        // Facebook 368 / 1390008 "temporarily blocked" is documented as retryable,
        // and a 5xx with a platform mapping keeps the platform's wording.
        $table = [1390008 => [MetaGraphOutcome::RETRY, 'You are posting too fast, please slow down']];

        $outcome = MetaGraphErrors::classify(400, ['error' => ['message' => 'Temporarily blocked for policies violations', 'code' => 368, 'error_subcode' => 1390008]], $table);
        $this->assertSame(MetaGraphOutcome::RETRY, $outcome->kind);
        $this->assertSame('You are posting too fast, please slow down', $outcome->message);

        $table = [190 => [MetaGraphOutcome::FAIL, 'platform says fail']];
        $this->assertSame('platform says fail', MetaGraphErrors::classify(500, ['error' => ['code' => 190]], $table)->message);
    }

    public function test_numeric_table_keys_only_match_whole_numbers_in_the_body(): void
    {
        $table = [33 => [MetaGraphOutcome::REAUTH, 'object gone']];

        // 330 and 2207033 contain "33" but are different errors.
        $this->assertSame(MetaGraphOutcome::FAIL, MetaGraphErrors::classify(400, ['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 330]], $table)->kind);
        $this->assertSame(MetaGraphOutcome::FAIL, MetaGraphErrors::classify(400, ['error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2207033]], $table)->kind);
        $this->assertSame(MetaGraphOutcome::FAIL, MetaGraphErrors::classify(400, ['error' => ['message' => 'Media id 1337 is not ready', 'code' => 100]], $table)->kind);

        // A standalone 33 in the text still matches (substring rule).
        $outcome = MetaGraphErrors::classify(400, ['error' => ['message' => 'Unsupported get request. Object with ID 33 does not exist', 'code' => 100]], $table);
        $this->assertSame('object gone', $outcome->message);
    }

    public function test_text_table_keys_match_case_insensitively_anywhere_in_the_body(): void
    {
        $table = ['does not exist' => [MetaGraphOutcome::FAIL, 'The Page no longer exists']];

        $outcome = MetaGraphErrors::classify(400, ['error' => ['message' => 'Object with ID 1 DOES NOT EXIST', 'code' => 100]], $table);
        $this->assertSame('The Page no longer exists', $outcome->message);

        // Instagram container status responses carry the code in `status`, not `error`.
        $table = [2207026 => [MetaGraphOutcome::FAIL, 'Unsupported video format']];
        $outcome = MetaGraphErrors::classify(200, ['status' => 'Error: unsupported format. Error code: 2207026', 'status_code' => 'ERROR'], $table);
        $this->assertSame('Unsupported video format', $outcome->message);
    }

    public function test_table_with_an_unknown_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MetaGraphErrors::classify(400, ['error' => ['code' => 100]], [100 => ['explode', 'nope']]);
    }
}
