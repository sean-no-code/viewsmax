<?php

namespace App\Services\Social;

/**
 * The social account being connected already belongs to another ViewsMax
 * user. The message tells the person to sign in with that account instead.
 */
class HandleClaimedException extends SocialConnectException {}
