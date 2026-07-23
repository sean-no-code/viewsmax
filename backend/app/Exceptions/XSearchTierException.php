<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * X refused /2/users/search for this app (HTTP 403 — the endpoint is gated to
 * higher API tiers, and refusal bodies vary: client-not-enrolled, etc.).
 * Callers degrade to exact-username lookup, which lower tiers allow.
 */
class XSearchTierException extends RuntimeException {}
