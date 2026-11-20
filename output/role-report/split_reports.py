from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from copy import deepcopy
folder=Path('output/role-report')
source=Document(folder/'Bao_cao_phan_quyen_LTI_va_H5P_Hub.docx')
items=list(source.element.body)
for kind,name,title in [('LTI','Hien_trang_phan_quyen_he_thong_LTI.docx','Hiện trạng phân quyền hệ thống LTI'),('Hub','Hien_trang_phan_quyen_he_thong_H5P_Hub.docx','Hiện trạng phân quyền hệ thống H5P Hub')]:
 d=Document()
 sec=d.sections[0]; sec.page_height=Inches(11.69); sec.page_width=Inches(8.27); sec.top_margin=sec.bottom_margin=Inches(.65); sec.left_margin=sec.right_margin=Inches(.7)
 for st in ['Normal','Title','Heading 1','Heading 2']:
  d.styles[st].font.name='Calibri'; d.styles[st].font.color.rgb=RGBColor(0,0,0)
 d.styles['Normal'].font.size=Pt(11); d.styles['Normal'].paragraph_format.space_after=Pt(6)
 d.styles['Normal'].paragraph_format.line_spacing=1.08
 d.styles['Title'].font.size=Pt(23); d.styles['Heading 1'].font.size=Pt(16); d.styles['Heading 2'].font.size=Pt(12)
 d.add_paragraph(title,'Title')
 d.add_paragraph('Ngày báo cáo 18 tháng 11 năm 2026')
 d.add_paragraph('Mục đích báo cáo là trình bày các vai trò, phạm vi quyền và giới hạn đang hoạt động để lãnh đạo xem xét, xác định nội dung cần điều chỉnh trước khi triển khai thay đổi.')
 if kind=='LTI': d.add_paragraph('Hệ thống LTI hiện có 4 vai trò nội bộ và phân quyền theo tổ chức. Tổ chức có thể là một trường học hoặc trung tâm. Nội dung dưới đây chỉ mô tả hiện trạng của hệ thống LTI.')
 else: d.add_paragraph('H5P Hub hiện có 1 vai trò quản trị nội bộ và 3 vai trò nhận từ LMS qua LTI. Nội dung dưới đây chỉ mô tả hiện trạng phân quyền của H5P Hub.')
 active=False
 for el in items:
  txt=''.join(el.itertext()) if False else ''.join(el.xpath('.//w:t/text()'))
  if 'Phần I Phân quyền hệ thống LTI' in txt:
   active=kind=='LTI'; continue
  if 'Vai trò gửi sang H5P Hub khi mở bài' in txt and kind=='LTI': active=False
  if 'Phần II Phân quyền hệ thống H5P Hub' in txt:
   active=kind=='Hub'; continue
  if 'Lưu ý về quyền quản lý tổ chức' in txt: active=False
  if not active: continue
  if el.tag.endswith('sectPr'): continue
  # Remove page breaks inherited from the combined report.
  if el.xpath('.//w:br[@w:type="page"]'): continue
  d.element.body.insert(len(d.element.body)-1,deepcopy(el))
 d.add_heading('Trạng thái báo cáo',2)
 d.add_paragraph('Đây là báo cáo hiện trạng. Chưa thay đổi mã nguồn hoặc triển khai mô hình phân quyền mới. Các nội dung điều chỉnh sẽ được xác định theo ý kiến của lãnh đạo trước khi thực hiện.')
 d.core_properties.title=title
 d.save(folder/name)
 print(name)
