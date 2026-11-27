# Discourse và đăng nhập Zalo

## Cài diễn đàn

1. Trên máy chủ Linux có Docker, dùng [trình cài đặt chính thức của Discourse](https://meta.discourse.org/t/discourse-official-install-guide-discourse/197803). Tạo diễn đàn ở một tên miền HTTPS riêng, ví dụ `forum.example.com`. Thiết lập gửi email để xác nhận tài khoản mới và tạo tài khoản quản trị đầu tiên.
2. Trong [Zalo for Developers](https://developers.zalo.me/), mở ứng dụng Zalo Social và vào **Quản lý ứng dụng → Đăng nhập**. Đăng ký callback HTTPS `https://forum.example.com/auth/zalo/callback`. Dùng **App ID** và **Secret Key** của ứng dụng đó. Nếu ứng dụng chưa công khai, dùng tài khoản được phép kiểm thử cho lần xác minh đầu tiên.
3. Từ thư mục gốc của repo H5P Client trên máy chủ diễn đàn, chép plugin vào thư mục lưu trữ của Discourse. Mẫu dưới đây dùng bản cài standalone ở `/var/discourse` với `shared/standalone` được gắn vào `/shared`. Chạy `rsync` lại khi cập nhật plugin.

   ```sh
   sudo rsync -a docker/discourse/discourse-zalo/ /var/discourse/shared/standalone/discourse-zalo/
   ```

4. Trong `/var/discourse/containers/app.yml`, thêm hai biến vào mục `env` hiện có. Chỉ lưu Secret Key trên máy chủ diễn đàn.

   ```yaml
   ZALO_APP_ID: "123456789"
   ZALO_APP_SECRET: "<Secret Key của ứng dụng Zalo>"
   ```

   Thêm lệnh sau vào danh sách `cmd` của hook `after_code` có `cd: $home/plugins`. Giữ các lệnh cài plugin hiện có.

   ```yaml
   - cp -a /shared/discourse-zalo ./discourse-zalo
   ```

5. Từ `/var/discourse`, chạy `sudo ./launcher rebuild app`. Mở `https://forum.example.com/login` và chọn **Đăng nhập bằng Zalo**. Lần đầu, Discourse yêu cầu người dùng điền và xác nhận email vì thông tin hồ sơ Zalo dùng cho tích hợp này chỉ gồm ID và tên. Đăng xuất rồi thử đăng nhập Zalo lần nữa để kiểm tra vào đúng tài khoản diễn đàn.
6. Thêm `DISCOURSE_URL=https://forum.example.com` vào biến môi trường stack **H5P Client** trên Dockhand. Triển khai bản Client mới. Nút **Diễn đàn** trên trang đăng nhập Client sẽ mở trang đăng nhập Discourse.

Tài khoản Discourse dùng để tham gia diễn đàn. Giáo viên và học viên dùng tài khoản Client để vào lớp và mở bài H5P.

[Tài liệu Zalo Social OAuth V4](https://docs.zaloplatforms.com/docs/Social/social-api/tham-khao/user-access-token-v4)
