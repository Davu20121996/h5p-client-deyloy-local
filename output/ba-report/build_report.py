from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image
root=Path('output/ba-report'); doc=Document()
s=doc.sections[0]; s.page_width=Inches(8.27); s.page_height=Inches(11.69)
s.top_margin=s.bottom_margin=Inches(.6); s.left_margin=s.right_margin=Inches(.65)
for name in ['Normal','Title','Subtitle','Heading 1','Heading 2','Caption']:
 st=doc.styles[name]; st.font.name='Calibri'; st.font.color.rgb=RGBColor(0,0,0)
 st.paragraph_format.space_after=Pt(6)
doc.styles['Normal'].font.size=Pt(11); doc.styles['Normal'].paragraph_format.line_spacing=1.08
doc.styles['Title'].font.size=Pt(26); doc.styles['Heading 1'].font.size=Pt(19); doc.styles['Heading 2'].font.size=Pt(12)
doc.styles['Caption'].font.size=Pt(9); doc.styles['Caption'].font.italic=True
footer=s.footer.paragraphs[0]; footer.alignment=WD_ALIGN_PARAGRAPH.RIGHT
r=footer.add_run('H5P Client  |  '); r.font.size=Pt(8)
f=OxmlElement('w:fldSimple'); f.set(qn('w:instr'),'PAGE'); footer._p.append(f)
def p(text,style=None): return doc.add_paragraph(text,style)
def h(text): doc.add_heading(text,1)
def sub(text): doc.add_heading(text,2)
def page(title): doc.add_page_break(); h(title)
def label(key,text):
 pa=p(''); pa.add_run(key+' ').bold=True; pa.add_run(text)
def shot(name,caption,max_h=5.8,width=6.9):
 im=Image.open(root/'screens'/f'{name}.png'); w,h=im.size
 width=min(width,max_h*w/h)
 pa=p(''); pa.paragraph_format.space_after=Pt(3); pa.alignment=WD_ALIGN_PARAGRAPH.CENTER
 run=pa.add_run(); run.add_picture(str(root/'screens'/f'{name}.png'),width=Inches(width))
 inline=run._r.xpath('.//wp:docPr')[0]; inline.set('descr',caption)
 pa.paragraph_format.keep_with_next=True
 cap=p(caption,'Caption'); cap.alignment=WD_ALIGN_PARAGRAPH.CENTER

def table(headers,rows,widths):
 t=doc.add_table(rows=1,cols=len(headers)); t.autofit=False; t.alignment=WD_TABLE_ALIGNMENT.CENTER
 for col,w in zip(t.columns,widths): col.width=Inches(w)
 for c,txt in zip(t.rows[0].cells,headers): c.text=txt
 for row in rows:
  for c,txt in zip(t.add_row().cells,row): c.text=txt
 for i,row in enumerate(t.rows):
  row._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
  for c,w in zip(row.cells,widths):
   c.width=Inches(w); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
   pr=c._tc.get_or_add_tcPr(); borders=OxmlElement('w:tcBorders')
   for edge in ['top','left','bottom','right']:
    x=OxmlElement('w:'+edge); x.set(qn('w:val'),'single'); x.set(qn('w:sz'),'4'); x.set(qn('w:color'),'D9D9D9'); borders.append(x)
   pr.append(borders); mar=OxmlElement('w:tcMar')
   for edge in ['top','left','bottom','right']:
    x=OxmlElement('w:'+edge); x.set(qn('w:w'),'90'); x.set(qn('w:type'),'dxa'); mar.append(x)
   pr.append(mar); shade=OxmlElement('w:shd'); shade.set(qn('w:fill'),'E6EDF3' if i==0 else ('F5F7F9' if i%2==0 else 'FFFFFF')); pr.append(shade)
   for pa in c.paragraphs:
    pa.paragraph_format.space_after=Pt(3); pa.paragraph_format.line_spacing=1.05
    for r in pa.runs: r.font.size=Pt(10); r.bold=(i==0)
 t.rows[0]._tr.get_or_add_trPr().append(OxmlElement('w:tblHeader'))
 p('')

p('H5P Client\nNghiệp vụ vận hành','Title')
p('Báo cáo dành cho BA  |  Ngày 20 tháng 11 năm 2026','Subtitle')
p('H5P Client quản lý đơn vị, người dùng, khóa học và quyền học. Nội dung tương tác được mở từ H5P Hub; tiến độ và điểm được gửi về Client để theo dõi. Báo cáo giúp BA xác định tác nhân, quyền thao tác và màn hình trong một quy trình học hoàn chỉnh.')
h('1 Ai làm gì ở đâu')
table(['Ai và vai trò','Làm gì','Ở đâu'],[
 ['Quản trị hệ thống\nadmin','Tạo đơn vị kèm quản lý; cấp quyền quản lý đơn vị; xem dữ liệu các đơn vị.','Tổ chức, Tài khoản, Khóa học, Công cụ LTI, Nhật ký LTI'],
 ['Quản lý đơn vị\ntenant_admin','Quản lý giáo viên và học viên; kết nối Hub; quản lý mọi khóa học trong đơn vị.','Tài khoản, Công cụ LTI, Tổ chức; các màn hình của khóa học'],
 ['Giáo viên\nteacher','Tạo và quản lý khóa học mình phụ trách; thêm bài, ghi danh, giao bài và theo dõi điểm.','Khóa học → Hoạt động H5P, Ghi danh, Sổ điểm, Cài đặt khóa học'],
 ['Học viên\nstudent','Vào khóa đã ghi danh; làm bài đã công bố và được giao; xem kết quả của mình.','Khóa học → Hoạt động H5P → Mở bài học; Sổ điểm'],
],[1.5,2.85,2.62])
label('Tác nhân ngoài hệ thống', 'H5P Hub cung cấp nội dung và gửi tiến độ, điểm qua kết nối LTI. Hub không phải vai trò đăng nhập nội bộ của Client; màn hình biên soạn H5P thuộc Hub.')
sub('Luồng nghiệp vụ tổng quát')
p('Tạo đơn vị → Tạo tài khoản và kết nối Hub → Tạo khóa học và thêm bài → Ghi danh và giao bài → Học viên làm bài → Nhận tiến độ và điểm.')
p('Tất cả tài khoản đăng nhập bằng email và mật khẩu. Sau đăng nhập, menu và dữ liệu hiển thị theo vai trò và đơn vị; người dùng có thể đổi mật khẩu, đăng xuất.')

page('2 Quản trị hệ thống thiết lập đơn vị')
label('Ai', 'Quản trị hệ thống, phạm vi toàn hệ thống.')
label('Ở đâu', 'Menu Tổ chức, đường dẫn /admin/tenants.')
shot('admin-tenants','Hình 1  Quản trị hệ thống xem các đơn vị và tài khoản quản lý',5.7)
label('Làm gì', 'Chọn Tạo tổ chức và tài khoản quản lý để khai báo đơn vị, người quản lý, email và mật khẩu ban đầu. Có thể sửa câu chào hoặc cấp quyền quản lý cho thành viên đang hoạt động của đơn vị.')
label('Kết quả', 'Đơn vị có một quản lý để tự vận hành người dùng, khóa học và kết nối H5P của mình.')
label('Giới hạn', 'Admin được xem dữ liệu toàn hệ thống nhưng không trực tiếp tạo, sửa, xóa khóa học hoặc mở bài H5P. Tạo giáo viên, học viên và cấu hình công cụ được thực hiện bằng vai trò quản lý đơn vị.')

page('3 Quản lý đơn vị vận hành tài khoản')
label('Ai', 'Quản lý đơn vị Tplus, chỉ thao tác trong Tplus.')
label('Ở đâu', 'Menu Tài khoản, đường dẫn /admin/users.')
shot('manager-users','Hình 2  Quản lý Tplus tạo tài khoản và quản lý vai trò trong đơn vị',5.1)
label('Làm gì', 'Chọn Tạo tài khoản, nhập tên, email, mật khẩu và chọn Giáo viên hoặc Học viên. Tại danh sách, chọn vai trò rồi Lưu vai trò; dùng Khóa hoặc Mở khóa để điều chỉnh trạng thái tài khoản.')
label('Quy tắc', 'Email không được trùng; mật khẩu tối thiểu 12 ký tự. Không chuyển giáo viên thành học viên khi người đó còn phụ trách khóa học. Khóa tài khoản sẽ thu hồi phiên đăng nhập.')
label('Phân biệt quyền', 'Quản lý đơn vị không tự nâng người dùng thành quản lý đơn vị. Quyền này được cấp bởi quản trị hệ thống tại màn hình Tổ chức.')

page('4 Quản lý đơn vị kết nối H5P Hub')
label('Ai', 'Quản lý đơn vị phối hợp với người vận hành H5P Hub.')
label('Ở đâu', 'Menu Công cụ LTI, đường dẫn /admin/tools.')
shot('manager-tools','Hình 3  Tplus có kết nối tplus hub để sử dụng bài học H5P',5.3)
label('Làm gì', 'Chọn Đăng ký công cụ LTI để khai báo tên công cụ, Client ID, Deployment ID, Login URL, Redirect URI và khóa công khai. Dùng Sửa công cụ LTI khi thông tin kết nối thay đổi.')
label('Ý nghĩa nghiệp vụ', 'Kết nối xác định Hub mà đơn vị được sử dụng để mở bài và nhận kết quả. Các địa chỉ nền tảng ở đầu màn hình được cung cấp cho phía Hub để cấu hình hai chiều.')
label('Ranh giới hệ thống', 'Client gắn bài học từ Hub vào khóa học bằng đường dẫn bài. Việc biên soạn nội dung, câu hỏi hoặc video H5P được thực hiện ở Hub, không phải màn hình này.')

page('5 Giáo viên chuẩn bị khóa học và bài học')
label('Ai và ở đâu', 'Giáo viên vào Khóa học để tạo khóa; trong khóa chọn Hoạt động H5P. Ví dụ /courses/12/activities của khóa CSKH.')
shot('teacher-activities','Hình 4  Giáo viên quản lý danh sách bài và trạng thái xuất bản',3.15)
shot('teacher-activity-form','Hình 5  Form thêm bài H5P vào khóa học',3.15)
label('Làm gì', 'Chọn Thêm hoạt động H5P; nhập tên, chọn công cụ, điền Target Link URI và điểm tối đa. Chọn Xuất bản để công bố; có thể sửa bài hoặc ẩn bài đã công bố.')
label('Điều kiện', 'Đường dẫn bài dùng HTTPS và cùng tên miền với Redirect URI của công cụ. Bài phải được xuất bản trước khi học viên được phép mở.')
label('Phạm vi', 'Giáo viên chỉ quản lý khóa mình phụ trách; quản lý đơn vị có thể quản lý tất cả khóa trong đơn vị. Cài đặt khóa học dùng để sửa thông tin hoặc xóa khóa.')

page('6 Giáo viên ghi danh và giao quyền làm bài')
label('Ở đâu', 'Trong khóa CSKH chọn Ghi danh, đường dẫn /courses/12/enrollment.')
shot('teacher-enrollment-rights','Hình 6  Ghi danh vào khóa học và cấp quyền làm từng bài là hai bước riêng',6.35)
label('Bước 1 Ghi danh', 'Nhập email học viên đang hoạt động trong cùng đơn vị rồi chọn Ghi danh. Học viên xuất hiện trong danh sách của khóa.')
label('Bước 2 Giao bài', 'Ở Quyền làm bài, chọn học viên và hoạt động rồi Cấp quyền. Có thể Thu hồi quyền cho từng bài hoặc loại học viên khỏi khóa bằng thao tác tại danh sách ghi danh.')
label('Điểm cần nhớ', 'Ghi danh chỉ mở quyền vào khóa, chưa tự cấp quyền làm mọi bài. Thu hồi quyền bài sẽ chặn mở bài và nhận điểm mới; loại khỏi khóa cũng thu hồi các quyền bài trong khóa đó.')

page('7 Học viên mở bài và theo dõi tiến độ')
label('Ai và ở đâu', 'Học viên vào Khóa học → Hoạt động H5P → Mở bài học. Ví dụ /courses/12/activities/9/learn.')
shot('student-learning','Hình 7  Màn hình bài học trước khi học viên bấm Bắt đầu bài học',5.8)
label('Làm gì', 'Bấm Bắt đầu bài học để mở nội dung từ Hub trong khung học. Có thể Phóng to, thu gọn hoặc Trở về khóa học. Client cập nhật tiến độ và điểm khi nhận kết quả hợp lệ từ Hub.')
label('Ba điều kiện để học', 'Học viên đã ghi danh khóa học; bài đã xuất bản; học viên được giao quyền làm bài đó. Menu học viên không có thao tác tạo bài, ghi danh hoặc quản trị.')
label('Hoàn thành và điểm', 'Đã hoàn thành và đã chấm điểm là hai trạng thái khác nhau. Nếu Hub báo hoàn thành nhưng còn chờ chấm, Client vẫn ghi nhận hoàn thành; điểm chưa có hiển thị dấu gạch và được cập nhật khi chấm xong.')

page('8 Giáo viên và học viên xem kết quả')
label('Ở đâu', 'Trong khóa chọn Sổ điểm, ví dụ /courses/12/grades. Học viên cũng xem điểm gần nhất tại màn hình bài học.')
shot('teacher-grades','Hình 8  Sổ điểm CSKH lưu các lần nhận kết quả với thời gian UTC cộng 7',4.35)
label('Ai thấy gì', 'Giáo viên xem kết quả học viên trong khóa mình phụ trách; quản lý xem các khóa trong đơn vị; học viên chỉ xem kết quả của chính mình trên bài còn được phép truy cập.')
label('Cách ghi nhận', 'Hub gửi tiến độ và điểm. Điểm được quy đổi theo điểm tối đa của bài ở Client. Sổ điểm có thể có nhiều dòng cho cùng một học viên và bài vì lưu lịch sử kết quả theo thời gian.')
sub('Các tình huống BA cần nghiệm thu')
table(['Tình huống','Kết quả mong đợi'],[
 ['Đã ghi danh nhưng chưa giao bài','Học viên vào khóa được nhưng chưa thấy hoặc mở được bài đó.'],
 ['Bài bị ẩn hoặc đã thu hồi quyền','Học viên không mở được bài; điểm mới cho bài đã thu hồi bị từ chối.'],
 ['Hoàn thành nhưng đang chờ chấm','Đã hoàn thành được ghi nhận; điểm chờ cập nhật.'],
 ['Hub gửi lại cùng một kết quả','Không tạo bản ghi điểm trùng; kết quả chờ chấm có thể được bổ sung điểm.'],
 ['Người dùng truy cập dữ liệu đơn vị khác','Bị từ chối truy cập.'],
],[2.8,4.17])

doc.core_properties.title='H5P Client Nghiệp vụ vận hành'; doc.core_properties.subject='Tác nhân vai trò màn hình và luồng vận hành cho BA'; doc.core_properties.author=''; doc.core_properties.keywords='H5P, Client, LTI, BA, nghiệp vụ'

for xml_root in [doc._element, doc.styles.element]:
 for border in list(xml_root.iter(qn('w:pBdr'))):
  border.getparent().remove(border)
out=root/'H5P-client-Nghiêp-Vu.docx'; doc.save(out); print(out.resolve())
