<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityAssignments
{
    public static function ensureAssignable(object $activity): void
    {
        if ($activity->archived_at) {
            throw ValidationException::withMessages(['activity_id' => 'Bài đang lưu trữ. Hãy khôi phục bản nháp trước khi thay đổi giao bài.']);
        }
    }

    public static function individual(int $activity, int $user, ?bool $granted): void
    {
        DB::transaction(function () use ($activity, $user, $granted) {
            self::ensureAssignable(DB::table('activities')->where('id', $activity)->lockForUpdate()->firstOrFail());
            $key = ['activity_id' => $activity, 'user_id' => $user];
            $override = DB::table('activity_assignment_overrides')->where($key)->first();
            if ($granted === null) {
                if ($override) {
                    self::apply($key, (bool) $override->baseline_granted);
                    DB::table('activity_assignment_overrides')->where($key)->delete();
                }

                return;
            }
            DB::table('activity_assignment_overrides')->updateOrInsert($key, [
                'granted' => $granted,
                'baseline_granted' => $override ? $override->baseline_granted : DB::table('activity_assignments')->where($key)->exists(),
            ]);
            self::apply($key, $granted);
        });
    }

    public static function bulk(int $activity, array $users, bool $granted): int
    {
        return DB::transaction(function () use ($activity, $users, $granted) {
            self::ensureAssignable(DB::table('activities')->where('id', $activity)->lockForUpdate()->firstOrFail());
            $overrides = DB::table('activity_assignment_overrides')->where('activity_id', $activity)->whereIn('user_id', $users)->get()->keyBy('user_id');
            foreach ($users as $user) {
                $key = ['activity_id' => $activity, 'user_id' => $user];
                if ($override = $overrides->get($user)) {
                    DB::table('activity_assignment_overrides')->where($key)->update(['baseline_granted' => $granted]);
                    self::apply($key, (bool) $override->granted);
                } else {
                    self::apply($key, $granted);
                }
            }

            return $overrides->count();
        });
    }

    private static function apply(array $key, bool $granted): void
    {
        if ($granted) {
            DB::table('activity_assignments')->insertOrIgnore($key);
        } else {
            DB::table('activity_assignments')->where($key)->delete();
        }
    }
}
