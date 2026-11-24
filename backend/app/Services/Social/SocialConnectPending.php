<?php

namespace App\Services\Social;

/**
 * A connect that cannot finish yet but may on a later try without the user
 * starting over (Telegram: the one-time code has not been seen in a chat
 * yet). The connect endpoint answers 202 with `pending: true`, so the
 * frontend keeps polling instead of showing an error.
 */
class SocialConnectPending extends SocialConnectException {}
