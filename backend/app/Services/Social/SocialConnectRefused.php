<?php

namespace App\Services\Social;

/**
 * A connect the provider refused for a reason written for the user (e.g.
 * "make the bot an admin of the group"). Unlike a plain SocialConnectException,
 * which may wrap raw provider text, its message is always shown
 * (App\Support\UserSafeError).
 */
class SocialConnectRefused extends SocialConnectException {}
