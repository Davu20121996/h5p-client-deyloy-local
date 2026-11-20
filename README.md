# Secure LTI Testing Platform

## Deploy production trực tiếp bằng Dockhand

Không cần chạy `setup.sh` cho cấu hình này. Commit các file production lên repo Client và Push nhánh `main`.

1. Trong Dockhand chọn **Stacks → From Git**, link repo Client, branch `main`.
2. **Compose file path:** `compose.production.yaml`. **Context directory:** `.`.
3. Nhập `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD` trong Environment variables. Xem các biến phụ ở `.env.production.example`. Đánh dấu Secret cho khóa và mật khẩu.
4. APP_KEY phải là `base64:` cộng chuỗi base64 của 32 byte ngẫu nhiên, tạo một lần. Có thể dùng `openssl rand -base64 32` trên máy tin cậy. Không Commit khóa thật lên Git.
5. Bật **Build images on deploy**, tắt **Re-pull images** cho image tự build rồi Deploy. Dockerfile tự cài Composer và build CSS/JS bằng npm.
6. Đợi `init` kết thúc với **Exited (0)**, sau đó `app`, `nginx`, `db` chạy. `init` tự migrate, tạo roles, tạo khóa LTI nếu thiếu và tạo admin ban đầu. Nếu chưa có admin mà thông tin admin trống/sai, init sẽ thất bại và web chưa được mở.
7. Truy cập domain HTTPS đã cấu hình reverse proxy. Mặc định cổng chỉ bind `127.0.0.1:9080`; proxy ở container/host khác cần cấu hình kết nối thích hợp.

**Cập nhật:** Commit → Push `main` → Dockhand đồng bộ và Build/Deploy. Code, vendor và frontend nằm trong image, không cần npm/composer thủ công trên pod. Init chạy migration khi triển khai; không đổi admin đã có, không sinh lại APP_KEY hoặc khóa LTI đang hoạt động. Nếu cần chạy lại init thủ công, dùng Redeploy/Force recreate, không chỉ Restart app. Không chạy song song nhiều đợt deploy cùng database.

**Giữ dữ liệu:** project mặc định là `secure-lti`, volume database cùng tên với cấu hình cũ. Trước khi chuyển bản đang chạy, sao lưu database, giữ đúng `APP_KEY`, `DB_PASSWORD` và project name; chuyển dữ liệu upload/storage cũ sang volume `storage` mới nếu có. Không chạy hai stack cũ và mới cùng tên/cổng. Không xóa volume để update. Có thể bỏ `ADMIN_PASSWORD` khỏi cấu hình sau khi đã tạo admin; init vẫn bỏ qua nếu admin tồn tại. Đổi biến mật khẩu không tự đổi mật khẩu PostgreSQL đã tạo.

Các biến production được truyền trực tiếp vào container; không cần `source/.env` hoặc `.env.docker`. Giữ bản sao các bí mật ở nơi an toàn ngoài Git. Bản hướng dẫn DOCX cũ dùng compose bind mount; với production này dùng quy trình trong mục này.

## Chạy bản bind mount bằng setup cũ

Chạy từ thư mục gốc bằng Bash (Git Bash trên Windows).

Nếu mở script trong cửa sổ tạm, dùng `./setup.sh --pause` để xem kết quả trước khi đóng cửa sổ.

```bash
./setup.sh
```

Mở Docker Desktop trước. Script tự khởi động các dịch vụ, cài thư viện, tạo bảng và tạo khóa ký LTI khi cần. Lần đầu, nhập email, tên và mật khẩu quản trị khi được hỏi. Khi hoàn tất, script in địa chỉ web từ cấu hình triển khai. Giữ lại `.env.docker`, dữ liệu PostgreSQL và `APP_KEY` khi chạy lại.

Để kết nối H5P Hosting, cấu hình reverse proxy TLS và đặt URL công khai ngay lần chạy đầu, ví dụ `APP_URL=https://lti.example.com bash setup.sh`. Giá trị này là issuer LTI. Dùng trang Quản trị để lấy các endpoint và đăng ký công cụ bằng Client ID, Deployment ID, Login URL, Redirect URI và khóa RSA công khai dạng PEM. Giáo viên tạo khóa học, ghi danh học viên rồi thêm và xuất bản hoạt động với Target Link URI của H5P. Học viên mở hoạt động và xem điểm trả về trong sổ điểm.

Mỗi tenant tạo một kết nối LTI 1.3 riêng trên H5P.com, dùng Client ID riêng và Deployment ID trùng với bản đăng ký trong trang Quản trị của tenant. Các URL Issuer, Login, Token và JWKS dùng chung trên Platform. Token AGS chỉ truy cập các hoạt động đã xuất bản gắn với bản đăng ký và khóa học của tenant đó.

Bản hiện tại hỗ trợ LTI Resource Link Launch, một line item do nền tảng tạo cho mỗi hoạt động, đọc line item và kết quả, và nhận điểm AGS. Deep Linking, lấy khóa Tool tự động qua JWKS và xác thực hai yếu tố chưa có.
