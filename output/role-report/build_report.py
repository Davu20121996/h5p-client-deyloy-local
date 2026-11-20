from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from pathlib import Path
out=Path('output/role-report')
doc=Document()
s=doc.sections[0]; s.page_height=Inches(11.69); s.page_width=Inches(8.27); s.top_margin=Inches(.65); s.bottom_margin=Inches(.65); s.left_margin=Inches(.7); s.right_margin=Inches(.7)
for name in ['Normal','Title','Subtitle','Heading 1','Heading 2']:
 st=doc.styles[name]; st.font.name='Calibri'; st.font.color.rgb=RGBColor(0,0,0)
doc.styles['Normal'].font.size=Pt(11)
doc.styles['Normal'].paragraph_format.space_after=Pt(6)
doc.styles['Normal'].paragraph_format.line_spacing=1.08
doc.styles['Title'].font.size=Pt(23)
doc.styles['Heading 1'].font.size=Pt(16)
doc.styles['Heading 2'].font.size=Pt(12)
def p(t): doc.add_paragraph(t)
def h(t): doc.add_heading(t,1)
def sub(t): doc.add_heading(t,2)
def table(headers, rows, widths):
 t=doc.add_table(rows=1, cols=len(headers)); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.autofit=False
 for c,w in zip(t.columns,widths): c.width=Inches(w)
 for c,txt in zip(t.rows[0].cells,headers): c.text=txt
 for row in rows:
  for c,txt in zip(t.add_row().cells,row): c.text=txt
 for i,row in enumerate(t.rows):
  for j,c in enumerate(row.cells):
   c.width=Inches(widths[j]); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
   pr=c._tc.get_or_add_tcPr(); borders=OxmlElement('w:tcBorders')
   for edge in ['top','left','bottom','right']:
    e=OxmlElement('w:'+edge); e.set(qn('w:val'),'single'); e.set(qn('w:sz'),'4'); e.set(qn('w:color'),'D9D9D9'); borders.append(e)
   pr.append(borders)
   mar=OxmlElement('w:tcMar')
   for edge in ['top','left','bottom','right']:
    e=OxmlElement('w:'+edge); e.set(qn('w:w'),'85'); e.set(qn('w:type'),'dxa'); mar.append(e)
   pr.append(mar)
   sh=OxmlElement('w:shd'); sh.set(qn('w:fill'),'E8EDF2' if i==0 else ('F5F7F9' if i%2==0 else 'FFFFFF')); pr.append(sh)
   for pa in c.paragraphs:
    pa.paragraph_format.space_after=Pt(2); pa.paragraph_format.line_spacing=1.03
    for r in pa.runs: r.font.size=Pt(10); r.bold=(i==0)
  trpr=row._tr.get_or_add_trPr(); ns=OxmlElement('w:cantSplit'); trpr.append(ns)
 repeat=OxmlElement('w:tblHeader'); t.rows[0]._tr.get_or_add_trPr().append(repeat)
 doc.add_paragraph().paragraph_format.space_after=Pt(0)

doc.add_paragraph('Báo cáo hiện trạng phân quyền\nhệ thống LTI và H5P Hub','Title')
p('Ngày báo cáo 18 tháng 11 năm 2026')
p('Hệ thống LTI và H5P Hub đang sử dụng hai cơ chế phân quyền riêng. LTI quản lý tổ chức, tài khoản, khóa học và quyền được giao bài; H5P Hub quản lý nội dung H5P, quyền biên soạn và xử lý kết quả làm bài.')
p('Hai hệ thống có hai cơ chế phân quyền riêng. Hệ thống LTI có 4 vai trò nội bộ; H5P Hub có 1 vai trò quản trị nội bộ và 3 vai trò nhận từ LMS qua LTI. Vai trò quản lý tổ chức trên hệ thống LTI không đồng nghĩa với quyền quản trị đơn vị trên H5P Hub.')
h('Phần I Phân quyền hệ thống LTI')
table(['Vai trò','Phạm vi và quyền đang hoạt động'],[
('Admin hệ thống\nadmin','Xem dữ liệu của tất cả tổ chức gồm người dùng, khóa học, công cụ và nhật ký. Tạo tổ chức kèm tài khoản quản lý; cấp quyền quản lý tổ chức cho thành viên; cập nhật lời chào của tổ chức.'),
('Quản lý tổ chức\ntenant_admin','Quản lý trong tổ chức của mình. Tạo, khóa/mở khóa tài khoản giáo viên và học viên; đổi vai trò giáo viên/học viên; cấu hình công cụ H5P/LTI; quản lý mọi khóa học trong tổ chức; xem nhật ký và sửa lời chào.'),
('Giáo viên\nteacher','Tạo khóa học và quản lý khóa học mình phụ trách. Thêm, sửa và công bố hoạt động H5P; ghi danh hoặc loại học viên; giao/thu hồi quyền làm từng bài; xem điểm trong khóa học.'),
('Học viên\nstudent','Truy cập khóa học đã ghi danh; mở bài đã công bố và được giao; làm bài H5P và xem điểm của bản thân trên các bài được phép truy cập.')],[1.5,5.35])
sub('Nguyên tắc và giới hạn phân quyền')
p('Admin hệ thống không được mở bài H5P hoặc trực tiếp tạo, sửa, xóa khóa học; các chức năng tạo tài khoản giáo viên/học viên và cấu hình công cụ hiện dành cho quản lý tổ chức. Quyền admin không tự động bao gồm mọi thao tác của các vai trò khác.')
p('Học viên cần đủ ba điều kiện để mở bài: đã ghi danh khóa học, bài đã được công bố và được cấp quyền làm bài đó. Chỉ ghi danh vào khóa học chưa đủ.')
p('Quản lý tổ chức quản lý mọi khóa học trong tổ chức; giáo viên chỉ quản lý khóa học do mình phụ trách.')
p('Mỗi tài khoản có một vai trò. Ngoài admin, tài khoản phải thuộc một tổ chức. Quản lý tổ chức chỉ đổi teacher và student cho nhau; không thể chuyển giáo viên thành học viên khi vẫn đang phụ trách khóa học. Tài khoản bị khóa không được đăng nhập.')

sub('Vai trò gửi sang H5P Hub khi mở bài')
table(['Vai trò tại LTI','Vai trò gửi sang Hub','Quyền tương ứng tại Hub'],[
('admin','Không cho phép mở bài','Không tự động có quyền Admin H5P Hub.'),
('tenant_admin','Instructor','Quyền giảng viên; chưa có quyền quản trị đơn vị trên Hub.'),
('teacher','Instructor','Quyền biên soạn và quản lý bài theo quyền sở hữu.'),
('student','Learner','Quyền học và làm bài được phép mở.')],[1.5,1.65,3.7])
p('ContentDeveloper là vai trò Hub có thể tiếp nhận, nhưng luồng mở hoạt động của LTI hiện tại chưa gửi vai trò này. Admin H5P Hub là quyền nội bộ, được xác định bằng is_admin và đăng nhập riêng.')

doc.add_page_break()
h('Phần II Phân quyền hệ thống H5P Hub')
table(['Vai trò','Cách truy cập','Quyền chính'],[
('Admin H5P Hub','Đăng nhập trực tiếp tại /admin/login','Quản trị toàn bộ đơn vị, kết nối LTI, thư viện và nội dung trên Hub.'),
('Giảng viên\nInstructor','Mở Hub từ LMS qua LTI','Tạo, nhập, sửa, xuất bản bài của mình; chọn bài đưa vào LMS; xem kết quả bài mình sở hữu.'),
('Người biên soạn\nContentDeveloper','LMS gửi vai trò khi mở LTI','Hiện có quyền tương đương giảng viên.'),
('Học viên\nLearner','Mở bài được giao từ LMS','Xem, làm và nộp bài; kết quả được lưu, điểm được gửi về LMS khi có cấu hình AGS hợp lệ.')],[1.45,1.75,3.65])
sub('Admin H5P Hub')
p('Tài khoản được đánh dấu is_admin có thể tạo, cập nhật và bật/tắt đơn vị; thêm, cập nhật và bật/tắt kết nối LTI; cài thư viện H5P dùng chung; xem người dùng đã đồng bộ từ LMS. Admin có thể nhập trước định danh giảng viên để nhập bài và gán chủ sở hữu; xem bài thuộc các đơn vị, xem trước, xuất bài và ẩn/hiện bài.')
p('Quyền admin hiện áp dụng trên tất cả đơn vị, chưa được giới hạn theo một công ty hoặc tổ chức cụ thể.')
sub('Giảng viên và người biên soạn')
p('Hub kiểm tra vai trò trong lượt mở LTI được LMS ký xác thực. Người có Instructor hoặc ContentDeveloper được tạo bài bằng thư viện đã cài, nhập gói .h5p, chỉnh sửa, lưu nháp và xuất bản bài mình sở hữu.')
p('Người soạn được xem bài đã xuất bản trong cùng đơn vị, sao chép thành bài riêng để chỉnh sửa và chọn bài đã xuất bản đưa về LMS qua Deep Linking. Họ được xem lượt làm và điểm của bài mình sở hữu; chỉ được xóa bài khi chưa có lượt làm hoặc liên kết sử dụng.')
p('Giảng viên chưa có chức năng cấp vai trò hoặc phân quyền học viên trên Hub.')
sub('Học viên')
p('Khi học viên mở bài từ LMS, Hub kiểm tra phiên LTI, đơn vị, kết nối và bài được phép mở. Học viên được xem bài đã xuất bản, tương tác, trả lời câu hỏi và nộp bài; không được soạn bài hoặc xem kết quả người khác.')
p('Hub lưu kết quả và gửi điểm về LMS khi lượt mở có thông tin AGS hợp lệ. AGS là dịch vụ trao đổi điểm qua LTI; Deep Linking là cơ chế chọn nội dung trên Hub để đưa về LMS.')

doc.add_page_break()
sub('Các vai trò và chức năng H5P Hub chưa có')
table(['Hạng mục','Hiện trạng'],[
('Tài khoản tenant-admin trên Hub','Chưa có.'),
('Admin tạo tenant-admin và cấp/thu hồi quyền','Chưa có.'),
('Tenant-admin chỉ quản lý đơn vị của mình','Chưa có.'),
('Tenant-admin cấp/thu hồi quyền giảng viên','Chưa có.'),
('Giảng viên cấp/thu hồi quyền học viên với từng bài','Chưa có trên Hub; hiện giao bài tại LMS.'),
('Màn hình phân quyền theo từng chức năng','Chưa có.'),
('Màn hình cấu hình public key riêng cho tenant-admin','Chưa có.')],[3.9,2.95])
sub('Lưu ý về quyền quản lý tổ chức')
p('Vai trò tenant_admin trên web 9080 hiện được gửi sang H5P Hub dưới dạng Instructor khi mở hoạt động. Vì vậy, người dùng có quyền giảng viên trên Hub, chưa có quyền quản trị đơn vị. Admin hệ thống LTI và Admin H5P Hub là hai quyền quản trị riêng, không tự động thay thế cho nhau.')
p('Báo cáo mô tả hiện trạng phân quyền; chưa thực hiện thay đổi mã nguồn theo mô hình phân quyền mới.')
doc.core_properties.title='Báo cáo hiện trạng phân quyền hệ thống LTI và H5P Hub'
doc.core_properties.subject='Vai trò và phạm vi quyền hiện tại của hai hệ thống'
doc.save(out/'Bao_cao_phan_quyen_LTI_va_H5P_Hub.docx')
print(out.resolve())
