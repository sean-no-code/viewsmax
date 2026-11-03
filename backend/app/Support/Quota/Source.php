<?php

namespace App\Support\Quota;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;

/**
 * Which unit of work a YouTube call belongs to, for the ledger's `source`
 * column: the queued job (job:ProcessYouTubeSearchTermJob), artisan command
 * (cmd:channels:sync-videos), MCP tool (mcp:search_outliers) or API route
 * (api:GET /api/multiplier/{videoId}) that made it. Jobs and commands are
 * named by the queue and console hooks below, MCP tools by SafeCallTool, a
 * plain request by its route — no call site reports anything. Unlike the
 * Actor it never travels into a queued job: a job is its own source.
 */
final class Source
{
    public const KEY = 'youtube_quota.source';

    /** From here on, YouTube calls are recorded as made by this unit of work. */
    public static function set(?string $source): void
    {
        $source === null ? Context::forgetHidden(self::KEY) : Context::addHidden(self::KEY, $source);
    }

    /** The current unit of work, or null when nothing names one (a bare console script). */
    public static function id(): ?string
    {
        if (($source = Context::getHidden(self::KEY)) !== null) {
            return (string) $source;
        }

        $route = app()->bound('request') ? request()->route() : null;
        if (! $route instanceof Route) {
            return null;
        }

        return 'api:'.($route->getName() ?: request()->method().' /'.ltrim($route->uri(), '/'));
    }

    /** Queue hook: the job being processed made every YouTube call until it finishes. */
    public static function jobProcessing(JobProcessing $event): void
    {
        self::set('job:'.class_basename($event->job->resolveName()));
    }

    /** Console hook: the command being run made every YouTube call until it finishes. */
    public static function commandStarting(CommandStarting $event): void
    {
        if (($event->command ?? '') !== '') {
            self::set('cmd:'.$event->command);
        }
    }

    /** Context::dehydrating hook: the requester's source stays behind, a job is its own. */
    public static function dehydrating(Repository $context): void
    {
        $context->forgetHidden(self::KEY);
    }
}
