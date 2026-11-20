<label>Tên công cụ<input name="name" value="{{ old('_modal') === $modalId ? old('name') : ($registration->name ?? '') }}" required></label>
<label>Client ID<input name="client_id" value="{{ old('_modal') === $modalId ? old('client_id') : ($registration->client_id ?? '') }}" required></label>
<label>Deployment ID<input name="deployment_id" value="{{ old('_modal') === $modalId ? old('deployment_id') : ($registration->deployment_id ?? '') }}" required></label>
<label>Tool Login URL<input type="url" name="login_url" value="{{ old('_modal') === $modalId ? old('login_url') : ($registration->login_url ?? '') }}" required></label>
<label>Tool Redirect URI<input type="url" name="redirect_uri" value="{{ old('_modal') === $modalId ? old('redirect_uri') : ($registration->redirect_uri ?? '') }}" required></label>
<label>Khóa công khai RSA (PEM)
    <textarea name="public_key" rows="5" aria-describedby="{{ $modalId }}-key-help" required>{{ old('_modal') === $modalId ? old('public_key') : ($registration->public_key ?? '') }}</textarea>
    <small id="{{ $modalId }}-key-help">Dán khóa công khai tương ứng với khóa H5P Hub đang dùng để ký yêu cầu cấp token AGS. Sau khi đổi khóa trên Hub, cập nhật trường này cho từng bản đăng ký đang dùng Hub.</small>
</label>
