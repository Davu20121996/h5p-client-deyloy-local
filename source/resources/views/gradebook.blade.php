<h2 class="section-title">{{ request()->boolean('history') ? 'Lịch sử kết quả' : 'Sổ điểm chính thức' }}</h2>
<p>Điểm chính thức gồm học viên hiện được giao bài, kể cả người chưa nhận kết quả. Lịch sử giữ các bản ghi cũ, kể cả khi thu hồi quyền hoặc lưu trữ bài.</p>
<form method="get" action="/courses/{{ $course->id }}/grades" class="card fields report-filters">
@if($manage)
<label>Lớp<select name="classroom_id"><option value="">Tất cả lớp</option>@foreach($classrooms->whereIn('id', $attachedClassrooms) as $classroom)<option value="{{ $classroom->id }}" @selected(request('classroom_id') == $classroom->id)>{{ $classroom->name }}</option>@endforeach</select></label>
@endif
<label>Bài học<select name="activity_id"><option value="">Tất cả bài</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected(request('activity_id') == $activity->id)>{{ $activity->title }}</option>@endforeach</select></label>
<label>Chế độ<select name="history"><option value="0" @selected(!request()->boolean('history'))>Điểm chính thức</option><option value="1" @selected(request()->boolean('history'))>Lịch sử kết quả</option></select></label>
<label>Trạng thái<select name="result_status">@foreach(['all' => 'Tất cả trạng thái', 'not_reported' => 'Chưa nhận kết quả', 'in_progress' => 'Có kết quả chưa hoàn thành', 'completed' => 'Đã hoàn thành'] as $value => $label)<option value="{{ $value }}" @selected(request('result_status', 'all') === $value)>{{ $label }}</option>@endforeach</select></label>
<div class="row"><button>Lọc kết quả</button><a class="link" href="/courses/{{ $course->id }}/report.csv?{{ http_build_query(request()->only(['classroom_id', 'activity_id', 'history', 'result_status'])) }}">Tải CSV</a></div>
</form>
<section class="card table-wrap section"><table>
<thead><tr><th>Học viên</th><th>Bài học</th><th>Điểm</th><th>Trạng thái</th><th>Thời gian UTC+7</th></tr></thead><tbody>
@forelse($grades as $grade)
<tr><td>{{ $grade->name }}</td><td>{{ $grade->title }}<br><small>{{ $grade->grade_policy === 'highest' ? 'Điểm cao nhất' : 'Điểm gần nhất có điểm' }}</small></td>
<td>{{ $grade->score === null ? '—' : (float) $grade->score }} / {{ $grade->maximum }}</td>
<td>{{ !($grade->has_result ?? true) ? 'Chưa nhận kết quả' : (($grade->completed ?? ($grade->activity_progress === 'Completed')) ? 'Đã hoàn thành' : 'Chưa hoàn thành') }}
@if($grade->reported_at && $grade->due_at && \Illuminate\Support\Carbon::parse($grade->reported_at)->gt($grade->due_at))<br><span class="pill">Nộp trễ</span>@endif
@isset($grade->attempt_count)<br><small>{{ $grade->attempt_count }} bản ghi kết quả</small>@endisset</td>
<td>{{ $grade->reported_at ? \Illuminate\Support\Carbon::parse($grade->reported_at)->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') : '—' }}</td></tr>
@empty<tr><td colspan="5">{{ request()->boolean('history') ? 'Chưa có bản ghi kết quả phù hợp. Hub cần gửi kết quả để Client cập nhật.' : 'Chưa có học viên được giao bài phù hợp với bộ lọc.' }}</td></tr>@endforelse
</tbody></table></section>
