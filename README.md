# Secure LTI Testing Platform

## Chức năng Client

- Quản trị tổ chức tạo lớp, sửa hồ sơ, đặt lại mật khẩu và nhập tối đa 500 học viên/lượt bằng CSV UTF-8 với dòng đầu `name,email,password`. Lượt nhập sai được hủy toàn bộ; không ghi đè tài khoản cũ.
- Trong khóa học, giáo viên phụ trách hoặc quản trị thêm/thu hồi đồng giảng và bàn giao người phụ trách mới. Có thể giữ người cũ làm đồng giảng; nếu không, người cũ mất quyền quản lý. Đồng giảng không được tự bàn giao hoặc cấp thêm giáo viên. Đồng giảng đã khóa vẫn có thể được thu hồi khỏi khóa.
- Gắn lớp để ghi danh các học viên hiện có; giao/thu hồi bài cho cả khóa hoặc một lớp đã gắn. Đây là thao tác theo danh sách tại thời điểm bấm, không tự cấp quyền cho thành viên thêm sau. Ghi danh/giao lại để bổ sung. Ngoại lệ cấp/thu hồi riêng từng học viên được giữ qua các đợt hàng loạt; chọn **Theo đợt giao** để bỏ ngoại lệ và dùng trạng thái đợt giao gần nhất cho người đó. Thu hồi ghi danh xóa cả quyền bài và ngoại lệ của người đó trong khóa.
- Lớp có thể lưu trữ/khôi phục, gỡ liên kết khỏi khóa mà không xóa lịch sử. Những thao tác này không tự thu hồi ghi danh hoặc quyền bài đã cấp; thu hồi học viên riêng khi cần ngừng quyền học.
- Hoạt động có hướng dẫn, thứ tự, lịch mở/hạn nộp/đóng theo giờ Việt Nam, lưu trữ và khôi phục về bản nháp. Hạn nộp đánh dấu trễ; thời gian đóng chặn mở bài và từ chối kết quả có thời điểm ngoài lịch. Kết quả gửi chậm vẫn được nhận nếu thời điểm được công cụ báo nằm trong lịch và quyền còn hiệu lực.
- Bài đã có lượt mở hoặc điểm được giữ nguyên liên kết, công cụ và thang điểm. Muốn đổi thì tạo hoạt động mới. Không xóa khóa đã có điểm.
- Sổ điểm chính thức gồm học viên hiện đang ghi danh và được giao bài, kể cả người chưa có kết quả. Lịch sử giữ cả kết quả trước khi thu hồi quyền hoặc lưu trữ bài. Chọn điểm cao nhất/gần nhất có điểm theo từng bài; lọc lớp/bài/trạng thái và xuất CSV theo quyền. Menu **Báo cáo** chọn khóa trong phạm vi được phép xem. Số bản ghi kết quả không đồng nghĩa số lần làm bài. Không giới hạn số lượt làm trong phiên bản này.
- Trang học có nút mở tab mới khi trình duyệt chặn khung nhúng; trang gốc vẫn cập nhật kết quả. Khi vào lại trang học, điểm đã có được tải ngay, không cần bấm bắt đầu. Phân biệt bài chưa đến giờ mở và đã đóng. Hub vẫn cần gửi kết quả thì Client mới có điểm và trạng thái hoàn thành; Client không tự lấy điểm từ iframe.
- **Hồ sơ cá nhân** cho sửa tên/email của chính mình. Đổi email cần mật khẩu hiện tại và thu hồi các phiên khác. **Công cụ LTI → Kiểm tra cấu hình** kiểm tra URL, khóa RSA và cookie đã lưu; không gọi Hub qua mạng và không khẳng định kết nối hai bên đã thành công.

### Cấp tài khoản từ Hub

1. Admin hệ thống vào Hub, tạo đơn vị và đăng ký kết nối LTI. Mỗi kết nối của Client cần Client ID riêng và Deployment ID tương ứng. Thêm origin HTTPS của Client vào danh sách origin được nhúng. Dùng URL Client làm issuer, ghép thêm /lti/authorize và /lti/jwks cho hai đầu mối còn lại.
2. Đặt LTI_MANAGED_CLIENT_URL trên Hub trỏ đến URL Client. Đặt HUB_BASE_URL trên Client trỏ về URL Hub. Đặt cùng HUB_PROVISIONING_SECRET trên hai ứng dụng, gồm ít nhất 32 byte ngẫu nhiên (ví dụ tạo bằng openssl rand -hex 32).
3. Admin Hub cấp tài khoản Tenant Admin cho đơn vị bằng kết nối vừa tạo. Hub gửi yêu cầu tới `POST /hub/provision`. Client tạo tổ chức, tài khoản và kết nối trong cùng giao dịch, đồng thời giữ mật khẩu hiện tại nếu yêu cầu được gửi lại cho tài khoản đã có. Với tổ chức Client cũ, dùng cùng Client ID, Deployment ID và origin Hub để liên kết mà giữ dữ liệu. Tổ chức, email hoặc kết nối đã thuộc bản ghi khác sẽ bị báo xung đột.
4. Tenant Admin đăng nhập Client và tạo tài khoản giáo viên, học sinh trong **Tài khoản**. Giáo viên ghi danh và giao bài trong khóa học. Admin hệ thống sử dụng trang quản trị Hub.

Đặt `CLIENT_TENANT_ADMIN_INITIAL_PASSWORD` trong cấu hình riêng của Client. Yêu cầu cấp tài khoản nhận `manager_username` hoặc `manager_email`; tài khoản có tên đăng nhập có thể để trống email. Client băm mật khẩu ban đầu từ cấu hình và yêu cầu đổi mật khẩu trước khi vào màn hình quản trị. Đồng bộ lại giữ mật khẩu hiện tại. Có thể dùng `php artisan lms:reset-password <tên-đăng-nhập-hoặc-email>` để đặt lại mật khẩu.

Khi Client trả HTTP 503 từ `/hub/provision`, chạy `php artisan lms:hub-provision-status` trong container để xem cấu hình còn thiếu. Với Dockhand, cập nhật biến trong stack rồi triển khai lại. Để cấp tài khoản chỉ có tên đăng nhập, màn hình **Quản lý đơn vị → Hồ sơ → Cấp tài khoản và đồng bộ LTI** phía Hub cần gửi trường `manager_username`.

### Chọn bài từ Hub

Giáo viên vào **Khóa học → Hoạt động H5P** và chọn **Mở thư viện H5P**. Hub được mở trong khung của Client. Client kiểm tra phản hồi đã ký và phiên chọn, tạo một hoạt động nháp rồi cập nhật danh sách. Học sinh mở bài đã giao trong khung bài học. Khi trình duyệt chặn cookie nhúng, dùng **Mở ở tab mới**.

Mỗi lượt mở gửi mã người đang đăng nhập, vai trò và deployment trong JWT do Client ký. Giáo viên dùng Instructor, học sinh dùng Learner, Tenant Admin dùng Administrator kèm Instructor. Mật khẩu đi qua yêu cầu cấp tài khoản riêng giữa hai máy chủ. JWT của LTI không chứa mật khẩu. Hai ứng dụng cần HTTPS, SESSION_SECURE_COOKIE=true và SESSION_SAME_SITE=none cho LTI nhúng.

### Dọn dữ liệu demo của Client

Trong thư mục `source` hoặc `/var/www/app` của container Client kết nối đến database demo, chạy:

```bash
php artisan db:seed --class=ResetClientLearningDataSeeder
```

Seed xóa khóa, lớp, bài, điểm, nhật ký và các tài khoản giáo viên, học sinh trong cơ sở dữ liệu đang kết nối. Seed giữ tổ chức, Tenant Admin, khóa ký và cấu hình LTI. Khi container dùng `APP_ENV=production`, kiểm tra đúng database demo rồi chọn **Yes** tại bước xác nhận của Artisan. Dữ liệu Hub được quản lý tại Hub.

### Cập nhật dữ liệu và kiểm thử

Bản này có migration `2026_11_25_000001_extend_learning_management` và `2026_11_25_000002_complete_learning_workflows`, không xóa dữ liệu cũ. Bản đã chạy migration thứ nhất chỉ cần chạy thêm migration thứ hai. Ngoại lệ cá nhân được ghi nhận cho các thao tác sau khi cập nhật; dữ liệu cũ không có thông tin để suy ra ai đã được cấp riêng hay qua giao hàng loạt. Sao lưu trước cập nhật. Khi deploy production bằng Dockhand, giữ Build images và chạy lại init để migration được áp dụng; không chạy `migrate:fresh`.

Chạy kiểm thử trong thư mục `source` bằng `php vendor/bin/phpunit` và `node --test tests/js/learning.test.cjs`. Môi trường test dùng SQLite trong bộ nhớ và cần extension PDO SQLite. Các kiểm thử không thay đổi database production. Deep Linking tham chiếu [đặc tả 1EdTech](https://www.imsglobal.org/spec/lti-dl/v2p0/).

## Deploy production trực tiếp bằng Dockhand

Không cần chạy `setup.sh` cho cấu hình này. Commit các file production lên repo Client và Push nhánh `main`.

1. Trong Dockhand chọn **Stacks → From Git**, link repo Client, branch `main`.
2. **Compose file path:** `compose.production.yaml`. **Context directory:** `.`.
3. Nhập `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `HUB_BASE_URL`, `HUB_PROVISIONING_SECRET` trong Environment variables. Xem các biến phụ ở `.env.production.example`. Đánh dấu Secret cho khóa và mật khẩu.
4. APP_KEY phải là `base64:` cộng chuỗi base64 của 32 byte ngẫu nhiên, tạo một lần. Có thể dùng `openssl rand -base64 32` trên máy tin cậy. Không Commit khóa thật lên Git.
5. Bật **Build images on deploy**, tắt **Re-pull images** cho image tự build rồi Deploy. Dockerfile tự cài Composer và build CSS/JS bằng npm.
6. Đợi init kết thúc với Exited (0). Init chạy migration, tạo roles và khóa LTI nếu thiếu. Sau khi Client chạy, Admin hệ thống cấp Tenant Admin từ Hub.
7. Truy cập domain HTTPS đã cấu hình reverse proxy. Mặc định cổng chỉ bind `127.0.0.1:9080`; proxy ở container/host khác cần cấu hình kết nối thích hợp.

**Cập nhật:** Commit → Push `main` → Dockhand đồng bộ và Build/Deploy. Code, vendor và frontend nằm trong image, không cần npm/composer thủ công trên pod. Init chạy migration khi triển khai; không sinh lại APP_KEY hoặc khóa LTI đang hoạt động. Nếu cần chạy lại init thủ công, dùng Redeploy/Force recreate, không chỉ Restart app. Không chạy song song nhiều đợt deploy cùng database.

**Giữ dữ liệu:** project mặc định là `secure-lti`, volume database cùng tên với cấu hình cũ. Trước khi chuyển bản đang chạy, sao lưu database, giữ đúng `APP_KEY`, `DB_PASSWORD` và project name; chuyển dữ liệu upload/storage cũ sang volume `storage` mới nếu có. Không chạy hai stack cũ và mới cùng tên/cổng. Không xóa volume để update. Giữ cùng bí mật cấp tài khoản ở Hub và Client.

Các biến production được truyền trực tiếp vào container; không cần `source/.env` hoặc `.env.docker`. Giữ bản sao các bí mật ở nơi an toàn ngoài Git. Bản hướng dẫn DOCX cũ dùng compose bind mount; với production này dùng quy trình trong mục này.

## Chạy bản bind mount bằng setup cũ

Chạy từ thư mục gốc bằng Bash (Git Bash trên Windows).

Nếu mở script trong cửa sổ tạm, dùng `./setup.sh --pause` để xem kết quả trước khi đóng cửa sổ.

```bash
./setup.sh
```

Mở Docker Desktop trước. Script tự khởi động các dịch vụ, cài thư viện, tạo bảng và tạo khóa ký LTI khi cần. Cấp tài khoản Tenant Admin từ Hub sau khi dịch vụ chạy. Khi hoàn tất, script in địa chỉ web từ cấu hình triển khai. Giữ lại `.env.docker`, dữ liệu PostgreSQL và `APP_KEY` khi chạy lại.

Để kết nối H5P Hub, cấu hình reverse proxy TLS và APP_URL HTTPS. Thêm HUB_BASE_URL và HUB_PROVISIONING_SECRET vào .env.docker rồi chạy lại setup.sh. Đăng ký kết nối và cấp Tenant Admin tại Hub theo quy trình trên.

Mỗi tenant tạo một kết nối LTI 1.3 riêng trên H5P.com, dùng Client ID riêng và Deployment ID trùng với bản đăng ký trong trang Quản trị của tenant. Các URL Issuer, Login, Token và JWKS dùng chung trên Platform. Token AGS chỉ truy cập các hoạt động đã xuất bản gắn với bản đăng ký và khóa học của tenant đó.

Bản hiện tại hỗ trợ LTI Resource Link Launch, Deep Linking chọn một bài, một line item do nền tảng tạo cho mỗi hoạt động, đọc line item/kết quả và nhận điểm AGS. Public key của Tool được quản trị nhập dạng PEM; lấy khóa Tool tự động qua JWKS và xác thực hai yếu tố chưa có.
