<?php

namespace App\Services;

use App\Http\Controllers\LmsController;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LearningAccess
{
    public static function manageCourse(int $id): object
    {
        $user = Auth::user();
        abort_unless($user && ! $user->locked && $user->tenant_id && in_array($user->role, ['teacher', 'tenant_admin'], true), 403);

        return LmsController::courses()->where('id', $id)->firstOrFail();
    }

    public static function tenantManager(): int
    {
        $user = Auth::user();
        abort_unless($user && ! $user->locked && $user->role === 'tenant_admin' && $user->tenant_id, 403);

        return $user->tenant_id;
    }

    public static function classrooms(): Builder
    {
        $user = Auth::user();
        abort_unless($user && ! $user->locked && $user->tenant_id && in_array($user->role, ['tenant_admin', 'teacher'], true), 403);

        return DB::table('classrooms')->where('classrooms.tenant_id', $user->tenant_id)
            ->when($user->role === 'teacher', fn (Builder $query) => $query->where('classrooms.teacher_id', $user->id));
    }

    public static function available(object $activity): bool
    {
        return self::availability($activity) === 'open';
    }

    public static function availability(object $activity): string
    {
        if ($activity->archived_at) {
            return 'archived';
        }
        if (! $activity->published) {
            return 'draft';
        }
        if ($activity->opens_at && Carbon::parse($activity->opens_at)->gt(now())) {
            return 'scheduled';
        }
        if ($activity->closes_at && Carbon::parse($activity->closes_at)->lte(now())) {
            return 'closed';
        }

        return 'open';
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return '';
        }

        return 'https://'.strtolower($parts['host']).':'.($parts['port'] ?? 443);
    }
}
