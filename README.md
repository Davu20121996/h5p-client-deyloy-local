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

### Chọn bài từ Hub

Quản trị tổ chức vào **Công cụ LTI**, nhập **URL chọn bài từ Hub** (với Hub hiện tại là `https://hub.example.com/lti/launch`), giữ đúng Client ID, Deployment ID và public key của Hub. Đăng ký origin Client trong `allowed_origins` của Hub; URL trả về là `https://client.example.com/lti/deep-link/return`. Hai ứng dụng cần HTTPS và cookie phiên phù hợp với LTI (`SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=none`).

Giáo viên vào khóa học → **Thêm bài từ Hub** → chọn công cụ → **Mở kho và chọn bài**. Client gửi yêu cầu Deep Linking 2.0, xác thực chữ ký cùng phiên/người dùng/tenant của phản hồi và lưu một bài dưới dạng nháp. Sau đó sửa lịch/thang điểm, ghi danh, giao bài và xuất bản. Phản hồi chọn bài hết hạn sau 30 phút; biên nhận trong database ngăn tạo lại bài kể cả khi cache cũ được khôi phục. Giữ cách nhập Target Link URI thủ công cho công cụ không hỗ trợ chọn bài.

Các tài khoản Hub trực tiếp không được tự gộp với danh tính LTI theo email. Việc tạo/sửa nội dung và liên kết hai loại danh tính thuộc phạm vi Hub.

### Cập nhật dữ liệu và kiểm thử

Bản này có migration `2026_11_25_000001_extend_learning_management` và `2026_11_25_000002_complete_learning_workflows`, không xóa dữ liệu cũ. Bản đã chạy migration thứ nhất chỉ cần chạy thêm migration thứ hai. Ngoại lệ cá nhân được ghi nhận cho các thao tác sau khi cập nhật; dữ liệu cũ không có thông tin để suy ra ai đã được cấp riêng hay qua giao hàng loạt. Sao lưu trước cập nhật. Khi deploy production bằng Dockhand, giữ Build images và chạy lại init để migration được áp dụng; không chạy `migrate:fresh`.

Chạy kiểm thử trong thư mục `source` bằng `php vendor/bin/phpunit` và `node --test tests/js/learning.test.cjs`. Môi trường test dùng SQLite trong bộ nhớ và cần extension PDO SQLite. Các kiểm thử không thay đổi database production. Deep Linking tham chiếu [đặc tả 1EdTech](https://www.imsglobal.org/spec/lti-dl/v2p0/).

### Phạm vi còn phụ thuộc Hub

Các chức năng C01–C11 phía Client đã có màn hình và xử lý tương ứng. Tạo/sửa nội dung H5P, phân quyền/chia sẻ/version nội dung và liên kết danh tính tài khoản Hub với LTI nằm ở repository Hub. Hub hiện không lưu/gửi kết quả theo code của Hub; vì vậy chưa thể xác nhận nhận điểm xuyên suốt chỉ bằng thay đổi Client. Kiểm tra cấu hình ở Client không thay thế thử nghiệm chọn bài và nhận kết quả với Hub thật.

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

Bản hiện tại hỗ trợ LTI Resource Link Launch, Deep Linking chọn một bài, một line item do nền tảng tạo cho mỗi hoạt động, đọc line item/kết quả và nhận điểm AGS. Public key của Tool được quản trị nhập dạng PEM; lấy khóa Tool tự động qua JWKS và xác thực hai yếu tố chưa có.
