<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><title>Mở hoạt động LTI</title></head>
<body>
<form method="post" action="{{ $action }}" id="transfer">
@foreach($fields as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
    <button type="submit">Tiếp tục mở hoạt động</button>
</form>
<script nonce="{{ $nonce }}">document.getElementById('transfer').submit();</script>
</body></html>