@extends('layout')
@section('title', $item->title)
@section('content')
<div class="lesson-page" data-learning-status="/courses/{{ $item->course_id }}/activities/{{ $item->id }}/status">
    <a class="back lesson-back" href="/courses/{{ $item->course_id }}">← Trở về khóa học</a>
    <header class="lesson-heading">
        <div><span class="eyebrow">KHÔNG GIAN HỌC TẬP</span><h1>{{ $item->title }}</h1><p>Học theo nhịp của bạn. Kết quả được cập nhật khi công cụ gửi về hệ thống.</p></div>
        <span class="lesson-kind">H5P · Tương tác</span>
    </header>
    @if($item->instructions)<section class="card"><h2>Hướng dẫn</h2><p class="lesson-instructions">{{ $item->instructions }}</p></section>@endif
    <section class="lesson-player" data-learning-player data-expandable-frame aria-label="Không gian bài học">
        <div class="lesson-toolbar">
            <div class="lesson-player-label"><span class="lesson-dot" aria-hidden="true"></span><span data-learning-label>Sẵn sàng bắt đầu</span></div>
            <x-frame-expand />
        </div>
        <div class="lesson-stage">
            <div class="lesson-placeholder" data-learning-placeholder>
                <div class="lesson-play-symbol" aria-hidden="true">▶</div>
                <span class="eyebrow">BÀI HỌC TƯƠNG TÁC</span>
                <h2>Một bước để bắt đầu</h2>
                <p>Mở bài học, khám phá nội dung và hoàn thành các câu hỏi.</p>
                <form method="post" action="/courses/{{ $item->course_id }}/activities/{{ $item->id }}/launch" target="h5p-lesson" data-learning-launch>
                    @csrf
                    <button class="lesson-start">Bắt đầu bài học <span aria-hidden="true">→</span></button>
                </form>
            </div>
            <iframe name="h5p-lesson" title="Bài học {{ $item->title }}" class="h5p-lesson" allow="fullscreen" allowfullscreen hidden></iframe>
        </div>
        <div class="lesson-player-footer"><span>Nội dung bài học</span><span>Bạn có thể phóng to để tập trung hơn</span></div>
    </section>
    <section class="lesson-result" aria-label="Kết quả bài học">
        <div class="lesson-result-copy"><span class="eyebrow">TIẾN ĐỘ CỦA BẠN</span><p data-learning-message role="status" aria-live="polite">Sẵn sàng khi bạn muốn bắt đầu.</p></div>
        <div class="lesson-score"><span>{{ $item->grade_policy === 'highest' ? 'Điểm cao nhất' : 'Điểm gần nhất có điểm' }}</span><strong data-learning-score>— <small>/ {{ $item->maximum }}</small></strong></div>
    </section>
</div>
<script defer src="/frame-expand.js?v={{ filemtime(public_path('frame-expand.js')) }}"></script>
<script defer src="/learning.js?v={{ filemtime(public_path('learning.js')) }}"></script>
@endsection
