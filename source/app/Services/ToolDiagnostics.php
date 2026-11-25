<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ToolDiagnostics
{
    public static function checks(object $tool): array
    {
        $public = @openssl_pkey_get_public($tool->public_key);
        $details = $public ? openssl_pkey_get_details($public) : null;
        $signingKey = DB::table('lti_keys')->whereNull('retired_at')->latest('created_at')->first();
        $canSign = false;
        try {
            if ($signingKey) {
                $key = @openssl_pkey_get_private(Crypt::decryptString($signingKey->encrypted_key));
                $privateDetails = $key ? openssl_pkey_get_details($key) : null;
                $canSign = $privateDetails && $privateDetails['type'] === OPENSSL_KEYTYPE_RSA && $privateDetails['bits'] >= 2048;
            }
        } catch (\Throwable) {
            $canSign = false;
        }

        return [
            ['label' => 'Domain Client sử dụng HTTPS', 'ok' => LearningAccess::origin(config('app.url')) !== '', 'help' => 'Đặt APP_URL đúng domain HTTPS công khai của Client.'],
            ['label' => 'Client ID và Deployment ID đã điền', 'ok' => trim($tool->client_id) !== '' && trim($tool->deployment_id) !== '', 'help' => 'Hai định danh phải trùng với bản đăng ký của tenant trên Hub.'],
            ['label' => 'URL đăng nhập và mở bài hợp lệ', 'ok' => LearningAccess::origin($tool->login_url) !== '' && LearningAccess::origin($tool->redirect_uri) !== '', 'help' => 'Dùng URL HTTPS của Hub, không có thông tin đăng nhập hoặc phần fragment trong URL.'],
            ['label' => 'URL chọn bài cùng origin với Hub', 'ok' => ! empty($tool->deep_link_url) && LearningAccess::origin($tool->deep_link_url) !== '' && LearningAccess::origin($tool->deep_link_url) === LearningAccess::origin($tool->redirect_uri), 'help' => 'Với Hub này, URL chọn bài là https://domain-hub/lti/launch.'],
            ['label' => 'Public key của Hub là RSA từ 2048 bit', 'ok' => $details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048, 'help' => 'Sao chép public key PEM đang dùng trên Hub vào kết nối này.'],
            ['label' => 'Client có khóa ký LTI đọc được', 'ok' => (bool) $canSign, 'help' => 'Kiểm tra init và APP_KEY; không đổi APP_KEY của dữ liệu đang chạy.'],
            ['label' => 'Cookie cho luồng quay về từ Hub', 'ok' => config('session.secure') && config('session.same_site') === 'none', 'help' => 'Production HTTPS dùng SESSION_SECURE_COOKIE=true và SESSION_SAME_SITE=none.'],
        ];
    }
}
