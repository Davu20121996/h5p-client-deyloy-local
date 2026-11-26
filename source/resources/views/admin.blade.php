@extends('layout')
@section('title', 'Quản trị')
@section('content')
@if($screen === 'tenants')
<h1>Tổ chức</h1>
<section class="card section">
    <h2>{{ $organization->name }}</h2>
    <form method="post" action="/admin/tenants/welcome" class="fields">
        @csrf
        @method('PUT')
        <label>Câu chào<textarea name="welcome_message" maxlength="1000">{{ old('welcome_message', $organization->welcome_message) }}</textarea></label>
        <button>Lưu câu chào</button>
    </form>
</section>
@elseif($screen === 'tools')
<h1>Công cụ LTI</h1>
<p>Quản trị hệ thống thiết lập kết nối của tổ chức trên H5P Hub.</p>
<section class="card"><dl class="endpoints">
<dt>Issuer</dt><dd>{{ config('app.url') }}</dd>
<dt>OIDC authorization</dt><dd>{{ config('app.url').route('lti.authorize', [], false) }}</dd>
<dt>JWKS</dt><dd>{{ config('app.url').route('lti.jwks', [], false) }}</dd>
<dt>Access token</dt><dd>{{ config('app.url').route('lti.token', [], false) }}</dd>
</dl></section>
<h2 class="section-title">Kết nối của tổ chức</h2>
@forelse($toolGroups as $tools)
<section class="card table-wrap"><table>
<thead><tr><th>Tên</th><th>Client ID</th><th>Deployment ID</th><th>Thao tác</th></tr></thead>
<tbody>
@foreach($tools as $tool)
<tr><td>{{ $tool->name }}</td><td>{{ $tool->client_id }}</td><td>{{ $tool->deployment_id }}</td>
<td><a class="button-link" href="/admin/tools/{{ $tool->id }}/check">Kiểm tra cấu hình</a></td></tr>
@endforeach
</tbody></table></section>
@empty
<p class="muted">Tổ chức chưa có kết nối với H5P Hub.</p>
@endforelse
@elseif($screen === 'users')
<h1>Tài khoản</h1>
<div class="section actions">

<x-modal id="user-create" title="Tạo tài khoản">
<form method="post" action="/admin/users" class="fields">@csrf<input type="hidden" name="_modal" value="user-create">
<label>Họ tên<input name="name" required></label><label>Email<input name="email" type="email" required></label>
<label>Vai trò<select name="role"><option value="student">Học viên</option><option value="teacher">Giáo viên</option></select></label>
<label>Mật khẩu<input name="password" type="password" minlength="12" autocomplete="new-password" required></label>
<button>Tạo tài khoản</button></form></x-modal>
<x-modal id="users-import" title="Nhập học viên từ CSV">
<form method="post" action="/admin/users/import" enctype="multipart/form-data" class="fields">@csrf
<p>CSV UTF-8, tối đa 500 học viên. Dòng đầu: <code>name,email,password</code>. Mỗi học viên có mật khẩu ban đầu riêng từ 12 ký tự. Email trùng hoặc dòng sai sẽ hủy toàn bộ lượt nhập.</p>
<label>File CSV<input type="file" name="csv" accept=".csv,text/csv" required></label><button>Kiểm tra và nhập học viên</button></form>
</x-modal>

</div>
<h2 class="section-title">Danh sách tài khoản</h2>
@forelse($userGroups as $members)
<h3 class="section-title">{{ $members->first()->tenant_name ?? 'Tài khoản không thuộc tổ chức' }}</h3>
<section class="card table-wrap"><table><thead><tr><th>Tên</th><th>Email</th><th>Vai trò</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@foreach($members as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>
@if(!in_array($user->role, ['teacher', 'student'], true))
{{ $roles->get($user->role, $user->role) }}
@else
<form method="post" action="/admin/users/{{ $user->id }}/role" class="fields table-role">@csrf
<label>Vai trò của {{ $user->name }}
<select name="role">
    <option value="student" @selected($user->role === 'student')>Học viên</option>
    <option value="teacher" @selected($user->role === 'teacher')>Giáo viên</option>
</select></label>
<button class="outline">Lưu vai trò</button>
</form>
@endif
</td><td>{{ $user->locked ? 'Đã khóa' : 'Đang hoạt động' }}</td>

<td class="table-actions">
@if(in_array($user->role, ['teacher', 'student'], true))
<div class="actions">
<x-modal :id="'user-edit-'.$user->id" title="Sửa hồ sơ">
<form method="post" action="/admin/users/{{ $user->id }}" class="fields">@csrf @method('PUT')
<label>Họ tên<input name="name" value="{{ $user->name }}" required maxlength="255"></label>
<label>Email<input name="email" type="email" value="{{ $user->email }}" required maxlength="255"></label><button>Lưu hồ sơ</button></form>
</x-modal>
<x-modal :id="'user-password-'.$user->id" title="Đặt lại mật khẩu">
<form method="post" action="/admin/users/{{ $user->id }}/password" class="fields">@csrf
<p>Đặt lại mật khẩu cho {{ $user->name }} và thu hồi các phiên đăng nhập cũ. Gửi mật khẩu mới cho người nhận qua kênh riêng.</p>
<label>Mật khẩu mới<input type="password" name="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label>
<label>Nhập lại mật khẩu<input type="password" name="password_confirmation" minlength="12" maxlength="1024" autocomplete="new-password" required></label><button>Đặt lại và thu hồi phiên</button></form>
</x-modal>
<form method="post" action="/admin/users/{{ $user->id }}/lock">@csrf<input type="hidden" name="locked" value="{{ $user->locked ? 0 : 1 }}"><button class="outline">{{ $user->locked ? 'Mở khóa' : 'Khóa' }}</button></form>
</div>
@endif
</td>

</tr>@endforeach
</tbody></table></section>
@empty
<p class="muted">Chưa có tài khoản.</p>
@endforelse
@else
<h1>Nhật ký LTI</h1>
@forelse($logGroups as $logs)
<h2 class="section-title">{{ $logs->first()->tenant_name ?? 'Sự kiện chưa gắn tổ chức' }}</h2>
<section class="card table-wrap"><table><thead><tr><th>Sự kiện</th><th>Người dùng</th><th>Hoạt động</th><th>Thời gian</th></tr></thead><tbody>
@foreach($logs as $log)<tr><td>{{ $log->event }}</td><td>{{ $log->user_id }}</td><td>{{ $log->resource_id }}</td><td>{{ $log->created_at }}</td></tr>@endforeach
</tbody></table></section>
@empty
<p class="muted">Chưa có sự kiện.</p>
@endforelse
@endif
@endsection
