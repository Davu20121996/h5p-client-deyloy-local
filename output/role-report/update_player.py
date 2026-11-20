from pathlib import Path
f=Path('source/resources/views/lms.blade.php')
s=f.read_text(encoding='utf-8')
a='<form method="post" action="/courses/{{ $course->id }}/activities/{{ $activity->id }}/launch" target="_blank" rel="noopener">@csrf<button>Mở hoạt động <span aria-hidden="true">↗</span><span class="sr-only"> (tab mới)</span></button></form>'
assert a in s
s=s.replace(a,'<a class="link" href="/courses/{{ $course->id }}/activities/{{ $activity->id }}/learn">Mở bài học →</a>')
f.write_text(s,encoding='utf-8')
f=Path('source/public/lms.css')
with f.open('a',encoding='utf-8') as out: out.write('\n.h5p-lesson { display: block; width: 100%; height: 75vh; min-height: 480px; border: 1px solid #d9d9d9; border-radius: 12px; margin-top: 20px; background: #fff; }\n')
