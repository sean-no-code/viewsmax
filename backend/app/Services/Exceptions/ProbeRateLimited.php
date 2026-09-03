<?php

namespace App\Services\Exceptions;

/**
 * YouTube is throttling or bot-checking our /shorts/ probes (429, 5xx, or a
 * redirect to a consent/sorry page). Callers stop the current batch and retry
 * later rather than treating the answer as "long-form".
 */
class ProbeRateLimited extends \RuntimeException
{
}
