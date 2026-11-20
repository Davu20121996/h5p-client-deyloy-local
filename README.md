# Secure LTI Testing Platform

Chạy từ thư mục gốc bằng Bash (Git Bash trên Windows).

Nếu mở script trong cửa sổ tạm, dùng `./setup.sh --pause` để xem kết quả trước khi đóng cửa sổ.

```bash
./setup.sh
```

Mở Docker Desktop trước. Script tự khởi động các dịch vụ, cài thư viện, tạo bảng và tạo khóa ký LTI khi cần. Lần đầu, nhập email, tên và mật khẩu quản trị khi được hỏi. Khi hoàn tất, script in địa chỉ web từ cấu hình triển khai. Giữ lại `.env.docker`, dữ liệu PostgreSQL và `APP_KEY` khi chạy lại.

Để kết nối H5P Hosting, cấu hình reverse proxy TLS và đặt URL công khai ngay lần chạy đầu, ví dụ `APP_URL=https://lti.example.com bash setup.sh`. Giá trị này là issuer LTI. Dùng trang Quản trị để lấy các endpoint và đăng ký công cụ bằng Client ID, Deployment ID, Login URL, Redirect URI và khóa RSA công khai dạng PEM. Giáo viên tạo khóa học, ghi danh học viên rồi thêm và xuất bản hoạt động với Target Link URI của H5P. Học viên mở hoạt động và xem điểm trả về trong sổ điểm.

Mỗi tenant tạo một kết nối LTI 1.3 riêng trên H5P.com, dùng Client ID riêng và Deployment ID trùng với bản đăng ký trong trang Quản trị của tenant. Các URL Issuer, Login, Token và JWKS dùng chung trên Platform. Token AGS chỉ truy cập các hoạt động đã xuất bản gắn với bản đăng ký và khóa học của tenant đó.

Bản hiện tại hỗ trợ LTI Resource Link Launch, một line item do nền tảng tạo cho mỗi hoạt động, đọc line item và kết quả, và nhận điểm AGS. Deep Linking, lấy khóa Tool tự động qua JWKS và xác thực hai yếu tố chưa có.
