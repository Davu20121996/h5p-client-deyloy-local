<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Gradebook
{
    public static function records(int $course, ?int $classroom = null, ?int $activity = null)
    {
        return DB::table('grades')->join('activities', 'activities.id', '=', 'grades.activity_id')
            ->join('users', 'users.id', '=', 'grades.user_id')
            ->where('activities.course_id', $course)
            ->when(Auth::user()?->role === 'student', fn ($query) => $query->where('grades.user_id', Auth::id())
                ->where('activities.published', true)->whereNull('activities.archived_at')
                ->whereIn('activities.id', DB::table('activity_assignments')->where('user_id', Auth::id())->select('activity_id')))
            ->when($classroom, fn ($query) => $query->whereIn('grades.user_id', DB::table('classroom_members')->where('classroom_id', $classroom)->select('user_id')))
            ->when($activity, fn ($query) => $query->where('activities.id', $activity))
            ->select('grades.*', 'users.name', 'users.email', 'activities.title', 'activities.maximum', 'activities.grade_policy', 'activities.due_at')
            ->orderByDesc('grades.reported_at');
    }

    public static function overview(int $course, ?int $classroom = null, ?int $activity = null)
    {
        $official = self::official(self::records($course, $classroom, $activity)->get())
            ->keyBy(fn ($row) => $row->activity_id.':'.$row->user_id);
        $assigned = DB::table('activity_assignments as assignment')
            ->join('activities', 'activities.id', '=', 'assignment.activity_id')
            ->join('courses', 'courses.id', '=', 'activities.course_id')
            ->join('users', 'users.id', '=', 'assignment.user_id')
            ->join('enrollments', function ($join) {
                $join->on('enrollments.user_id', '=', 'users.id')->on('enrollments.course_id', '=', 'courses.id');
            })
            ->where('courses.id', $course)->whereColumn('users.tenant_id', 'courses.tenant_id')->where('users.role', 'student')
            ->whereNull('activities.archived_at')
            ->when(Auth::user()?->role === 'student', fn ($query) => $query->where('users.id', Auth::id())->where('activities.published', true))
            ->when($classroom, fn ($query) => $query->whereIn('users.id', DB::table('classroom_members')->where('classroom_id', $classroom)->select('user_id')))
            ->when($activity, fn ($query) => $query->where('activities.id', $activity))
            ->select('assignment.activity_id', 'assignment.user_id', 'users.name', 'users.email', 'activities.title', 'activities.maximum', 'activities.grade_policy', 'activities.due_at')
            ->orderBy('users.name')->orderBy('activities.sort_order')->orderBy('activities.id')->get();

        return $assigned->map(function ($row) use ($official) {
            $result = $official->get($row->activity_id.':'.$row->user_id);
            if ($result) {
                $result->has_result = true;

                return $result;
            }
            $row->score = null;
            $row->reported_at = null;
            $row->activity_progress = 'Initialized';
            $row->attempt_count = 0;
            $row->completed = false;
            $row->has_result = false;

            return $row;
        });
    }

    public static function filterStatus($rows, ?string $status)
    {
        return $rows->filter(fn ($row) => match ($status) {
            'not_reported' => ! ($row->has_result ?? true),
            'in_progress' => ($row->has_result ?? true) && ! ($row->completed ?? $row->activity_progress === 'Completed'),
            'completed' => $row->completed ?? $row->activity_progress === 'Completed',
            default => true,
        })->values();
    }

    public static function official($records)
    {
        return $records->groupBy(fn ($row) => $row->activity_id.':'.$row->user_id)->map(function ($attempts) {
            $graded = $attempts->whereNotNull('score');
            $selected = $attempts->first()->grade_policy === 'highest'
                ? $graded->sortByDesc('score')->first() : $graded->first();
            $row = clone ($selected ?? $attempts->first());
            $row->attempt_count = $attempts->count();
            $row->completed = $attempts->contains('activity_progress', 'Completed');

            return $row;
        })->values();
    }
}
