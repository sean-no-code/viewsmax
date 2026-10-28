<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\YouTubeQuotaSpend;
use App\Support\Quota\Keys;
use App\Support\Quota\QuotaAlerts;
use App\Support\Quota\Resources;
use App\Support\Quota\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The admin quota dashboard's data: today's counters against each tier's
 * .env cap, a 30-day daily series, and the ledger (filter / sort / paginate
 * server-side).
 */
class YouTubeQuotaController extends Controller
{
    public function summary(): JsonResponse
    {
        $resources = [];
        foreach (Resources::all() as $resource) {
            $caps = [];
            foreach (Tier::ALL as $tier) {
                $caps[$tier] = Resources::cap($resource, $tier);
            }
            $used = (int) Cache::get(Keys::counter($resource), 0);
            $limit = QuotaAlerts::dailyLimit($resource);
            $resources[] = [
                'resource' => $resource,
                'units_per_call' => Resources::units($resource),
                'used_today' => $used,
                'daily_limit' => $limit,
                'percent_used' => $limit ? (int) floor($used * 100 / $limit) : null,
                'gated' => in_array($resource, Resources::gated(), true),
                'caps' => $caps,
            ];
        }

        $daily = YouTubeQuotaSpend::query()
            ->selectRaw('pt_date as date, resource, COUNT(*) as calls, SUM(units) as units')
            ->where('pt_date', '>=', Keys::date(now()->subDays(30)))
            ->groupBy('pt_date', 'resource')
            ->orderBy('pt_date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'resource' => $row->resource,
                'calls' => (int) $row->calls,
                'units' => (int) $row->units,
            ])
            ->values();

        return response()->json(['success' => true, 'message' => '', 'data' => [
            'enabled' => (bool) config('services.youtube.quota.enabled', true),
            'alert_at' => config('monitoring.youtube_quota.enabled', true) ? (int) config('monitoring.youtube_quota.alert_at') : null,
            'date' => Keys::date(),
            'timezone' => Keys::TIMEZONE,
            'resets_at' => Keys::resetsAt()->format(DATE_ATOM),
            'resources' => $resources,
            'daily' => $daily,
        ]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'resource' => ['nullable', Rule::in(Resources::all())],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['spent_at', 'units', 'resource', 'status', 'user_id', 'endpoint'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = YouTubeQuotaSpend::query()
            ->when($filters['resource'] ?? null, fn ($q, $v) => $q->where('resource', $v))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('pt_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('pt_date', '<=', $v))
            ->orderBy($filters['sort'] ?? 'spent_at', $filters['dir'] ?? 'desc')
            ->orderBy('id', 'desc');

        return response()->json(['success' => true, 'message' => '', 'data' => $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString()]);
    }
}
