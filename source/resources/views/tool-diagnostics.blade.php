@extends('layout')
@section('title', 'Kiểm tra cấu hình Hub')
@section('content')
<a class="back" href="/admin/tools">← Công cụ LTI</a>
<h1>Kiểm tra cấu hình {{ $tool->name }}</h1>
<p>Đây là kiểm tra các giá trị đã lưu trên Client. Kết quả đạt không xác nhận Hub đang truy cập được hoặc hai bên đã cấu hình trùng khớp.</p>
<section class="card table-wrap"><table><thead><tr><th>Mục kiểm tra</th><th>Kết quả</th><th>Cần làm</th></tr></thead><tbody>
@foreach($checks as $check)<tr><td>{{ $check['label'] }}</td><td>{{ $check['ok'] ? 'Đạt' : 'Cần kiểm tra' }}</td><td>{{ $check['help'] }}</td></tr>@endforeach
</tbody></table></section>
<section class="card section"><h2>Đối chiếu phía Hub</h2>
<p>Đăng ký đúng issuer {{ config('app.url') }}, Client ID {{ $tool->client_id }} và Deployment ID {{ $tool->deployment_id }} trong tenant tương ứng.</p>
<p>URL trả về chọn bài: <code>{{ config('app.url').route('lti.deep.return', [], false) }}</code>. Origin của Client phải nằm trong allowed_origins của Hub.</p>
<p>Sau khi cấu hình xong, vào khóa học và chọn một bài để kiểm tra thực tế. Muốn có điểm tự động, Hub cần bật gửi kết quả.</p></section>
@endsection
