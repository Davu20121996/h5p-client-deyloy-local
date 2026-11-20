from pathlib import Path
f=Path('output/role-report/build_report.py')
s=f.read_text(encoding='utf-8-sig')
s=s.replace('hệ thống LTI/LMS','hệ thống LTI').replace('LTI/LMS','LTI')
s=s.replace("p('LTI hiện có 4 vai trò. H5P Hub có 1 vai trò quản trị nội bộ và tiếp nhận 3 vai trò từ LMS qua LTI. Quyền quản lý tổ chức trên LMS chưa tương ứng với quyền quản trị đơn vị trên Hub.')", "p('Hai hệ thống có hai cơ chế phân quyền riêng. Hệ thống LTI có 4 vai trò nội bộ; H5P Hub có 1 vai trò quản trị nội bộ và 3 vai trò nhận từ LMS qua LTI. Vai trò quản lý tổ chức trên hệ thống LTI không đồng nghĩa với quyền quản trị đơn vị trên H5P Hub.')")
s=s.replace("h('1 Phân quyền của hệ thống LTI và LMS')", "h('Phần I Phân quyền hệ thống LTI')")
s=s.replace("h('2 Phân quyền của hệ thống H5P Hub')", "h('Phần II Phân quyền hệ thống H5P Hub')")
a=s.index("doc.add_page_break()\nh('3 Liên thông")
b=s.index("h('4 Các chức năng H5P Hub chưa có')")
block=s[a:b]
start=block.index("table(['Vai trò tại")
end=block.index("sub('Luồng vận hành hiện tại')")
mapping="sub('Vai trò gửi sang H5P Hub khi mở bài')\n"+block[start:end]
insert=s.index("\ndoc.add_page_break()\nh('Phần II")
s=s[:insert]+"\n"+mapping+s[insert:]
a=s.index("doc.add_page_break()\nh('3 Liên thông")
b=s.index("h('4 Các chức năng H5P Hub chưa có')")
s=s[:a]+"doc.add_page_break()\nsub('Các vai trò và chức năng H5P Hub chưa có')\n"+s[b+len("h('4 Các chức năng H5P Hub chưa có')\n"):]
a=s.index("sub('Đề xuất xem xét')")
b=s.index("p('Báo cáo mô tả hiện trạng",a)
s=s[:a]+"sub('Lưu ý về quyền quản lý tổ chức')\np('Vai trò tenant_admin trên web 9080 hiện được gửi sang H5P Hub dưới dạng Instructor khi mở hoạt động. Vì vậy, người dùng có quyền giảng viên trên Hub, chưa có quyền quản trị đơn vị. Admin hệ thống LTI và Admin H5P Hub là hai quyền quản trị riêng, không tự động thay thế cho nhau.')\n"+s[b:]
s=s.replace("sub('Các giới hạn cần lưu ý')", "sub('Nguyên tắc và giới hạn phân quyền')")
s=s.replace("p('Mỗi tài khoản có một vai trò.", "p('Học viên cần đủ ba điều kiện để mở bài: đã ghi danh khóa học, bài đã được công bố và được cấp quyền làm bài đó. Chỉ ghi danh vào khóa học chưa đủ.')\np('Quản lý tổ chức quản lý mọi khóa học trong tổ chức; giáo viên chỉ quản lý khóa học do mình phụ trách.')\np('Mỗi tài khoản có một vai trò.")
f.write_text(s,encoding='utf-8')
