<?php

namespace App\Support;

use App\Models\User;

/**
 * The one answer to "may this user use ViewsMax right now?", shared by every
 * entry point so the rules and the wording can't drift apart:
 *
 *  - REST API   EnsureAccessActive   -> 403 with the denial as JSON
 *  - MCP        SafeCallTool         -> plain tool result (HTTP 200, not isError)
 *  - Web        EnsureWebAccess      -> redirect to the denial's url
 *  - Console    call denial() directly before acting for a user
 *
 * Checks run in order and the first failure wins: an unverified user is told
 * to verify before being told to pay. Callers decide only how to deliver the
 * answer; add a new rule here and every entry point enforces it.
 */
final class UserAccess
{
    public const EMAIL_UNVERIFIED = 'email_unverified';

    /** Kept as-is: the SPA keys off this code to pin the user to Billing. */
    public const ACCESS_EXPIRED = 'access_expired';

    /** Signed up, verified, but never added a card (User::canCompleteOnboarding). */
    public const PAYMENT_REQUIRED = 'payment_required';

    /** Why this user can't use ViewsMax right now, or null when they can. */
    public static function denial(User $user): ?AccessDenial
    {
        if (is_null($user->email_verified_at)) {
            $url = self::verifyUrl($user);

            return new AccessDenial(
                self::EMAIL_UNVERIFIED,
                "Verify your email address to use ViewsMax. Open the link we emailed you, or sign in at {$url} to resend it.",
                $url,
            );
        }

        if ($user->accessExpired()) {
            $url = self::billingUrl();

            return new AccessDenial(
                self::ACCESS_EXPIRED,
                "Your ViewsMax free trial has ended. Please upgrade to a paid subscription to continue using ViewsMax: {$url}",
                $url,
            );
        }

        // The paywall: onboarding isn't finished and no card has been added.
        // Accounts that finished onboarding during the card-free weeks are
        // left alone; the end of their window handles them (above).
        if (is_null($user->onboarding_completed_at) && ! $user->canCompleteOnboarding()) {
            $url = self::cardUrl($user);

            return new AccessDenial(
                self::PAYMENT_REQUIRED,
                "Add a card to start your ViewsMax free trial: {$url}",
                $url,
            );
        }

        return null;
    }

    /**
     * Where the card gets added: agent signups on this host, mid OAuth flow
     * (WebBillingController); app signups in the SPA's onboarding wizard.
     */
    public static function cardUrl(User $user): string
    {
        return $user->signup_source === User::SIGNUP_SOURCE_AGENT
            ? route('register.billing')
            : self::frontendUrl('/onboarding');
    }

    /**
     * Where an unverified user can get a fresh link. Mirrors
     * Registration::verifyUrlFor: agent signups live on this host, app
     * signups in the SPA (its login offers "resend").
     */
    public static function verifyUrl(User $user): string
    {
        return $user->signup_source === User::SIGNUP_SOURCE_AGENT
            ? route('login')
            : self::frontendUrl('/auth');
    }

    public static function billingUrl(): string
    {
        return self::frontendUrl('/dashboard/billing');
    }

    private static function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
