from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image

ROOT = Path(__file__).parent
DOC = Document()
s = DOC.sections[0]
s.page_width = Inches(8.27); s.page_height = Inches(11.69)
s.top_margin = s.bottom_margin = Inches(.63)
s.left_margin = s.right_margin = Inches(.65)
for name in ['Normal', 'Title', 'Subtitle', 'Heading 1', 'Heading 2', 'Caption']:
    st = DOC.styles[name]; st.font.name = 'Calibri'; st.font.color.rgb = RGBColor(0, 0, 0)
    st.paragraph_format.space_after = Pt(6)
DOC.styles['Normal'].font.size = Pt(11)
DOC.styles['Normal'].paragraph_format.line_spacing = 1.06
DOC.styles['Title'].font.size = Pt(25)
DOC.styles['Heading 1'].font.size = Pt(19)
DOC.styles['Heading 2'].font.size = Pt(12)
DOC.styles['Caption'].font.size = Pt(9)
DOC.styles['Caption'].font.italic = True
for st in DOC.styles:
    for border in st.element.xpath('.//w:pBdr'):
        border.getparent().remove(border)
footer = s.footer.paragraphs[0]; footer.alignment = WD_ALIGN_PARAGRAPH.RIGHT
r=footer.add_run('Dockhand  H5P Hub và H5P Client  |  '); r.font.size=Pt(8)
f=OxmlElement('w:fldSimple'); f.set(qn('w:instr'),'PAGE'); footer._p.append(f)
DOC.core_properties.title='Hướng dẫn triển khai và cập nhật H5P trên Dockhand'
DOC.core_properties.subject='Thiết lập Docker và Dockhand, cập nhật file, Git và nhánh main'
DOC.core_properties.author=''

def p(text='', style=None): return DOC.add_paragraph(text, style)
def h(text): DOC.add_heading(text, 2)
def page(text): DOC.add_page_break(); DOC.add_heading(text, 1)
def step(n,text):
    pa=p(); pa.add_run(f'Bước {n}. ').bold=True; pa.add_run(text)
def note(label,text):
    pa=p();pa.add_run(label+' ').bold=True;pa.add_run(text)
def code(text):
    pa=p();pa.paragraph_format.space_before=Pt(3);pa.paragraph_format.space_after=Pt(8)
    pa.paragraph_format.line_spacing=1.0
    for i,line in enumerate(text.strip().splitlines()):
        if i: pa.add_run().add_break()
        r=pa.add_run(line);r.font.name='DejaVu Sans Mono';r.font.size=Pt(9)
def shot(name,caption,max_h=4.6,width=6.95):
    file=ROOT/'screens'/f'{name}.jpg'; im=Image.open(file)
    width=min(width,max_h*im.width/im.height)
    pa=p();pa.alignment=WD_ALIGN_PARAGRAPH.CENTER;pa.paragraph_format.keep_with_next=True
    run=pa.add_run(); run.add_picture(str(file),width=Inches(width))
    run._r.xpath('.//wp:docPr')[0].set('descr',caption)
    pa=p(caption,'Caption');pa.alignment=WD_ALIGN_PARAGRAPH.CENTER
def table(headers,rows,widths):
    t=DOC.add_table(rows=1,cols=len(headers));t.autofit=False;t.alignment=WD_TABLE_ALIGNMENT.CENTER
    for col,w in zip(t.columns,widths): col.width=Inches(w)
    for cell,txt in zip(t.rows[0].cells,headers):cell.text=txt
    for row in rows:
        for cell,txt in zip(t.add_row().cells,row):cell.text=txt
    for i,row in enumerate(t.rows):
        row._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
        for cell,w in zip(row.cells,widths):
            cell.width=Inches(w);cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
            pr=cell._tc.get_or_add_tcPr(); borders=OxmlElement('w:tcBorders')
            for edge in ['top','left','bottom','right']:
                x=OxmlElement('w:'+edge);x.set(qn('w:val'),'single');x.set(qn('w:sz'),'4');x.set(qn('w:color'),'D9D9D9');borders.append(x)
            pr.append(borders)
            mar=OxmlElement('w:tcMar')
            for edge in ['top','left','bottom','right']:
                x=OxmlElement('w:'+edge);x.set(qn('w:w'),'95');x.set(qn('w:type'),'dxa');mar.append(x)
            pr.append(mar)
            shade=OxmlElement('w:shd');shade.set(qn('w:fill'),'E5EDF5' if i==0 else ('F6F8FA' if i%2==0 else 'FFFFFF'));pr.append(shade)
            for pa in cell.paragraphs:
                pa.paragraph_format.space_after=Pt(3);pa.paragraph_format.line_spacing=1.03
                for r in pa.runs:r.font.size=Pt(10);r.bold=(i==0)
    t.rows[0]._tr.get_or_add_trPr().append(OxmlElement('w:tblHeader'))
    p()

p('Hướng dẫn triển khai và cập nhật\nH5P trên Dockhand','Title')
p('Từng bước cho H5P Hub và H5P Client  |  20 tháng 11 năm 2026','Subtitle')
p('Tài liệu hướng dẫn mở hai web đã cài trên máy, cập nhật sau khi sửa file và triển khai từ Git với nhánh main. Các ảnh chụp dùng giao diện Dockhand 1.0.58 trên máy hiện tại. Bắt đầu với cách chạy từ thư mục; chuyển sang Git khi đã chuẩn bị repository và cấu hình triển khai.')
table(['Thành phần','Địa chỉ trên máy','Tên trong Stacks'],[
    ['Dockhand','http://localhost:3000','Màn hình quản lý Docker'],
    ['H5P Hub','http://localhost:8080','h5p-hub  ·  6 container'],
    ['H5P Client','http://localhost:9080','secure-lti  ·  3 container'],
],[1.4,2.6,2.95])
shot('01-stacks-crop','Hình 1  Stacks hiển thị trạng thái running và nguồn Internal của hai web',max_h=3.0)
h('Chạy lại hai web ngay trên máy hiện tại')
step(1,'Mở Docker Desktop và chờ Docker Engine sẵn sàng.')
step(2,'Mở lối tắt Dockhand ngoài Desktop, đăng nhập bằng tài khoản admin đã được cấp.')
step(3,'Chọn môi trường docker-desktop rồi vào Stacks. Nếu web đang stopped, bấm Start tại đúng dòng web. Nếu đã running, mở địa chỉ web để sử dụng.')
note('Đọc theo tình huống','Thiết lập ở mục 1 đến 2; sửa file ở mục 3 đến 5; Git và main ở mục 6 đến 11; server và xử lý lỗi ở mục 12 đến 13.')

page('1 Thiết lập Dockhand và kết nối Docker')
p('Máy hiện tại đã hoàn tất phần này. Dùng các bước dưới đây khi cần kiểm tra hoặc dựng lại Dockhand từ file cấu hình đã có.')
step(1,'Mở PowerShell tại thư mục dự án Client. Chạy lần lượt hai dòng sau. Lệnh up sẽ tạo hoặc khởi chạy Dockhand theo cấu hình, giữ lại dữ liệu trong volume hiện có.')
code("Set-Location 'E:\\laragon\\www\\project-docker\\Secure-LTI-Testing-Platform'\ndocker compose -f docker/dockhand.compose.yaml up -d")
step(2,'Vào http://localhost:3000. Khi cài mới hoàn toàn, tạo quản trị viên và bật đăng nhập trong Settings → Authentication. Giữ Dockhand ở localhost hoặc mạng quản trị riêng.')
step(3,'Vào Settings → Environments → Add environment. Nhập Name là docker-desktop, Connection type là Unix socket và Socket path là /var/run/docker.sock. Bấm Test connection, sau đó Save.')
shot('07-socket-setup-crop','Hình 2  Cấu hình môi trường kết nối Docker bằng Unix socket',max_h=4.0)
note('Kết quả cần thấy','Môi trường hiển thị Connected, Docker có phiên bản và Stacks đọc được các container. Nếu đã có docker-desktop thì dùng lại, không tạo trùng.')
note('Đường dẫn dự án','File dockhand.compose.yaml đã gắn hai thư mục dự án vào Dockhand. Khi đổi ổ đĩa hoặc chuyển server, phải sửa lại các đường dẫn gắn thư mục trước khi triển khai.')

page('2 Đưa hai web đang có vào Dockhand')
p('Hai web hiện đã được đăng ký bằng Adopt và có nguồn Internal. Dockhand đọc đúng compose và env đang dùng. Adopt chỉ đăng ký quản lý, không tự sao chép database hoặc triển khai lại web.')
step(1,'Vào Stacks → Adopt nếu cần đăng ký lại ở một bản cài Dockhand mới. Chọn đúng thư mục chứa compose, kiểm tra tên stack và file env trước khi xác nhận.')
step(2,'Đối chiếu các file dưới đây. Gốc Windows là E:\\laragon\\www\\project-docker. Gốc Dockhand tương ứng là /run/desktop/mnt/host/e/laragon/www/project-docker.')
table(['Web','Compose tính từ gốc dự án','Env và thư mục làm việc'],[
    ['Client\nSecure-LTI-Testing-Platform','docker/compose.yaml','Env: .env.docker\nThư mục compose: docker'],
    ['Hub\nH5P-Hub','compose.yaml','Env: .env\nThư mục compose: gốc Hub'],
],[1.95,2.0,3.0])
step(3,'Tại dòng stack, bấm Edit. Kiểm tra Compose file và Env file. Bấm Validate để kiểm tra cú pháp trước khi lưu. Save chỉ lưu file; Save & redeploy lưu rồi áp dụng cấu hình.')
shot('04-edit-paths','Hình 3  Edit hiển thị đường dẫn compose env và nút Save cùng Save and redeploy',max_h=2.3)
step(4,'Trở về Stacks. Kiểm tra Hub có 6 container và Client có 3 container; trạng thái running. Mở web và thử đăng nhập.')
note('Lưu ý dữ liệu','Giữ tên project secure-lti và h5p-hub khi cập nhật bản hiện tại. Đổi tên project có thể tạo bộ volume khác, khiến web trông như một bản cài trắng.')
note('Hai file env của Client','.env.docker cấp biến cho Docker Compose; source/.env chứa cấu hình Laravel như APP_KEY. Giá trị environment trong compose ưu tiên hơn source/.env nếu trùng tên.')

page('3 Sửa file rồi bấm nút nào')
table(['Bạn thay đổi gì','Cần làm tiếp'],[
    ['Code PHP hoặc Blade trong thư mục đang gắn vào container','Xóa cache Laravel. Restart dịch vụ app nếu chưa thấy thay đổi; Hub cần chạy lại worker và scheduler khi đổi code tác vụ nền.'],
    ['CSS JS hoặc package lock của frontend','Chạy npm ci rồi npm run build ở đúng dự án. Kiểm tra public/build được cập nhật, tải lại trình duyệt.'],
    ['source/.env của Client hoặc .env của Hub','Xóa cache cấu hình. Nếu biến cũng đi qua compose hoặc ảnh hưởng worker, Redeploy với Force recreate các dịch vụ liên quan.'],
    ['.env.docker hoặc environment trong compose','Redeploy; bật Force recreate để container nhận biến mới. Restart đơn thuần giữ môi trường cũ.'],
    ['Dockerfile hoặc file cấu hình được COPY trong Dockerfile','Redeploy với Build images. Bật Force recreate để chắc chắn container dùng ảnh mới.'],
    ['compose.yaml cổng volumes networks hoặc Nginx','Validate rồi Redeploy. Với cấu hình Nginx gắn từ file, kiểm tra nginx -t rồi chạy lại dịch vụ Nginx.'],
    ['composer.lock hoặc composer.json','Chạy composer install theo lock trong app. Với bản đóng gói production, cài thư viện khi build image.'],
    ['Migration thay đổi cấu trúc database','Sao lưu database, chạy php artisan migrate --force và kiểm tra chức năng.'],
],[2.65,4.3])
h('Ý nghĩa các nút')
p('Start chạy container đã dừng. Restart khởi động lại container hiện có. Redeploy đọc cấu hình compose rồi tạo hoặc cập nhật dịch vụ. Build images dựng lại image từ Dockerfile; Force recreate buộc tạo lại container.')
note('Quan trọng','Pull images tải image từ registry Docker, không tải code từ Git. Với image tự build như h5p-hub-app:local, thường bỏ Pull images và chọn Build images khi cần build lại.')
note('Không dùng khi cập nhật thường xuyên','Down và Remove dùng để tháo gỡ tài nguyên. Không chọn xóa volumes; không chạy migrate:fresh trên dữ liệu cần giữ.')

page('4 Cập nhật bằng giao diện Dockhand')
step(1,'Sửa và lưu file ở đúng thư mục dự án trên máy. Với stack Internal hiện tại, Git chưa tham gia vào bước này.')
step(2,'Nếu sửa compose hoặc biến Docker, vào Stacks → Edit của đúng web. Bấm Validate. Sau đó dùng Save & redeploy hoặc lưu rồi mở menu Redeploy ở dòng stack.')
step(3,'Trong Redeploy: bỏ Pull images đối với ảnh local; bật Build images nếu sửa Dockerfile; bật Force recreate nếu cần nhận env mới hoặc dùng lại cùng tag image.')
shot('02-redeploy-crop','Hình 4  Ví dụ Redeploy có Build images và Force recreate và không bật Pull images',max_h=3.0)
step(4,'Bấm Deploy khi đã kiểm tra cấu hình. Chờ tác vụ hoàn tất và xem log triển khai. Thao tác này có thể làm web gián đoạn ngắn trong lúc tạo lại container.')
step(5,'Nếu thay đổi Laravel, mở Shell trong Dockhand, chọn container app tương ứng rồi chạy lệnh phù hợp ở trang kế tiếp. Shell nằm trong container nên chỉ chạy composer hoặc php, không nhập lệnh docker tại đây.')
step(6,'Trở về Stacks kiểm tra running. Mở web, đăng nhập và thử chức năng vừa sửa. Với Hub, thử mở nội dung H5P; với Client, thử mở bài từ khóa học.')
note('Đúng file mới có hiệu lực','Biến env chỉ dùng để thay ${VAR} trong compose chưa chắc đã vào ứng dụng. Compose phải có environment: hoặc env_file: để truyền biến đó vào container.')

page('5 Các lệnh sau khi sửa code trên máy')
p('Các lệnh trong phần A và B chạy ở Shell của container app trong Dockhand. Chọn đúng container trước khi chạy. Chỉ chạy composer khi thay thư viện và migrate khi có migration mới.')
h('A H5P Client')
p('Chọn secure-lti-app-1. File code nằm trong /var/www/app, được gắn từ thư mục source trên máy.')
code('cd /var/www/app\nphp artisan optimize:clear\n# Khi composer.lock thay đổi\ncomposer install --no-dev --prefer-dist --optimize-autoloader\n# Khi có migration mới và đã sao lưu\nphp artisan migrate --force')
p('Nếu chỉ sửa Blade, xóa cache và tải lại trang thường đã đủ. Nếu PHP vẫn giữ code cũ, dùng Containers → secure-lti-app-1 → Restart. Cấu hình OPcache hiện kiểm tra file sau mỗi 10 giây.')
h('B H5P Hub')
p('Chọn h5p-hub-app-1. File code nằm trong /var/www/html. vendor và storage đang dùng volume riêng.')
code('cd /var/www/html\nphp artisan optimize:clear\n# Chỉ khi thay thư viện\ncomposer install --no-dev --prefer-dist --optimize-autoloader\n# Chỉ khi có migration mới và đã sao lưu\nphp artisan migrate --force')
p('Sau khi cập nhật code, dùng Containers để Restart app, worker và scheduler của Hub. Worker và scheduler là tiến trình chạy lâu nên cần nạp lại code. Không cần restart database chỉ vì sửa PHP.')
h('C Khi sửa CSS hoặc JavaScript')
p('Chạy trên máy có Node phù hợp với package.json: mở PowerShell tại thư mục source của Client hoặc thư mục gốc Hub. Chạy lần lượt npm ci và npm run build. Sau đó tải lại trình duyệt bằng Ctrl F5.')
code('npm ci\nnpm run build')
note('Với bản mới từ Git','public/build đang được bỏ qua trong Git. Phải build frontend trong quy trình triển khai hoặc CI trước khi phục vụ trang dùng Vite. Nút Build images hiện tại chưa tự chạy npm cho hai dự án này.')

page('6 Git nhánh main và cách đưa code lên')
p('Repository là nơi lưu lịch sử code. Branch main là nhánh được chọn để triển khai. Commit ghi một phiên bản trên máy; Push gửi commit lên Git; Dockhand Sync lấy code về môi trường chạy. Lưu file hoặc Commit chưa đồng nghĩa website đã cập nhật.')
note('Luồng cần nhớ','Sửa file → Kiểm tra → Commit → Push lên main → Dockhand đồng bộ → Build hoặc Redeploy → Migration nếu có → Kiểm tra web.')
h('Cách dùng GitHub Desktop')
step(1,'Chọn Current repository đúng dự án. Hub đã có Git và đang ở main; thư mục Client hiện chưa có .git ở gốc nên cần tạo repository trước khi dùng luồng Git.')
step(2,'Với Client, dùng File → Add local repository. Nếu ứng dụng báo chưa phải repository, chọn Create a repository here, đúng thư mục gốc Secure-LTI-Testing-Platform. Kiểm tra tên nhánh và đổi thành main nếu cần.')
step(3,'Kiểm tra .gitignore và danh sách Changes. Không chọn file env thật, khóa riêng, dữ liệu upload hoặc database dump. Giữ .env.example không có bí mật để hướng dẫn cấu hình.')
step(4,'Nhập Summary rõ nội dung thay đổi, chọn Commit to main. Với nhóm có review, tạo nhánh sửa riêng rồi merge vào main sau khi được duyệt.')
step(5,'Dùng Publish repository với repo mới, ưu tiên Private. Với repo đã có, bấm Push origin. Mở trang Git kiểm tra commit mới thực sự nằm trên main.')
h('Kiểm tra trước khi Push')
code('git status\ngit branch --show-current\ngit diff --cached --name-only')
p('Các lệnh này chạy tại thư mục Git trên máy. Nếu chưa thấy main, chọn đúng nhánh trước khi triển khai. Một commit ở nhánh khác sẽ không cập nhật stack đang theo main.')
note('Không đưa lên Git','.env, .env.docker, source/.env, APP_KEY, mật khẩu DB, private key LTI, auth.json, thư mục output, backup và upload. Bổ sung .gitignore trước lần git add đầu tiên; ignore không tự gỡ file đã được theo dõi.')

page('7 Chuẩn bị đường dẫn cho bản Git trên máy')
p('Các stack hiện tại đọc hai thư mục Windows đã được gắn đúng đường dẫn. Repo tải mới bởi From Git nằm trong dữ liệu Dockhand. Vì compose có bind mount tương đối, cần đặt dữ liệu này tại đường dẫn mà cả Dockhand và Docker Desktop cùng nhìn thấy trước khi triển khai Git.')
step(1,'Tại PowerShell ở gốc Client, tạo một thư mục đích trống rồi dừng riêng Dockhand để sao chép dữ liệu nhất quán. Hai web vẫn chạy. Không xóa volume dữ liệu Dockhand cũ.')
code("$dataDir = 'E:\\laragon\\www\\project-docker\\dockhand-data'\nNew-Item -ItemType Directory -Path $dataDir\ndocker stop dockhand\ndocker cp dockhand:/app/data/. $dataDir")
step(2,'Tạo file docker/dockhand-paths.env với hai dòng sau. Đây là đường dẫn của máy hiện tại; nếu đổi ổ đĩa hoặc thư mục, sửa cả hai giá trị tương ứng.')
code('DOCKHAND_DATA_HOST=E:/laragon/www/project-docker/dockhand-data\nDOCKHAND_DATA_DIR=/run/desktop/mnt/host/e/laragon/www/project-docker/dockhand-data')
step(3,'Sửa docker/dockhand.compose.yaml. Trong environment thêm DATA_DIR như dưới. Trong volumes thay riêng dòng dockhand_data:/app/data bằng dòng dùng hai biến. Giữ socket, group_add, port localhost và hai bind mount dự án hiện có.')
code('environment:\n  TZ: Asia/Ho_Chi_Minh\n  DATA_DIR: ${DOCKHAND_DATA_DIR}\nvolumes:\n  - /var/run/docker.sock:/var/run/docker.sock\n  - "${DOCKHAND_DATA_HOST}:${DOCKHAND_DATA_DIR}"\n  # Giữ tiếp hai dòng bind mount dự án đã có')
step(4,'Khởi chạy lại với file biến đường dẫn. Các lần dựng lại Dockhand về sau cũng dùng cùng lệnh này.')
code("$dock = @('--env-file','docker/dockhand-paths.env',\n          '-f','docker/dockhand.compose.yaml')\ndocker compose @dock up -d")
step(5,'Đăng nhập lại và kiểm tra vẫn có môi trường cùng hai stack cũ. Nếu ứng dụng giống bản cài trắng, dừng lại và kiểm tra thư mục đã sao chép đủ db và .encryption_key. Có thể quay lại compose cũ dùng named volume để phục hồi cấu hình ban đầu.')
note('Trên server Linux mới','Có thể chọn DATA_DIR=/opt/dockhand và bind mount /opt/dockhand:/opt/dockhand ngay từ lần cài đầu. Không thay DATA_DIR của bản đang dùng mà bỏ qua việc sao chép dữ liệu.')

page('8 Kết nối repository với Dockhand')
step(1,'Lấy URL clone của repository đã Push lên Git. Ví dụ cấu trúc URL là https://github.com/your-org/h5p-client.git; phải thay bằng URL thật của bạn.')
step(2,'Vào Dockhand → Settings → Git. Nếu repo Private, mở Credentials để thêm SSH key hoặc token có quyền đọc repository cần dùng. Không đặt token trong URL và không lưu token vào Git.')
shot('05-git-settings-crop','Hình 5  Settings Git là nơi quản lý Repositories và Credentials',max_h=3.2)
step(3,'Bấm Add repository. Điền tên dễ nhận biết, URL clone và nhánh main. Chọn Credential đã tạo nếu Private; None public chỉ dùng với repo công khai. Kiểm tra kết nối và lưu theo biểu mẫu.')
step(4,'Quay lại Stacks → From Git. Có thể chọn Select existing khi đã thêm repository, hoặc Add new để nhập repository ngay trong biểu mẫu triển khai.')
note('Main lấy ở đâu','Xem Current branch trên GitHub Desktop hoặc danh sách nhánh trên trang repository. Điền đúng tên thật; main là quy ước phổ biến, không phải tên bắt buộc cho mọi repo.')
note('Webhook trên máy cá nhân','GitHub không gọi được địa chỉ localhost:3000 trên máy bạn. Khi chưa có đầu nhận HTTPS mà Git truy cập được, dùng đồng bộ thủ công hoặc scheduled sync; không cần mở công khai toàn bộ Dockhand để thử webhook.')
note('Repo tách riêng','Nếu Hub và Client là hai repository, tạo hai kết nối và hai stack. Nếu để chung một repo, đường dẫn compose phải tính từ gốc repo chung, không dùng đường dẫn ổ E của máy bạn.')
note('Trạng thái hiện tại','Dockhand trên máy đang quản lý hai web bằng Internal; chưa lưu repository Git. Mục 7 đến 11 hướng dẫn chuẩn bị và triển khai khi đã có URL và quyền truy cập Git.')

page('9 Thiết lập From Git từng trường')
shot('08-git-main-crop','Hình 6  From Git có Branch main cùng các trường repository stack và compose',max_h=4.8)
table(['Trường','Client khi repo chứa cả docker và source','Hub khi repo là H5P Hub'],[
    ['Repository','Repo Client của bạn','Repo Hub của bạn'],
    ['Branch','main hoặc nhánh đã duyệt','main hoặc nhánh đã duyệt'],
    ['Compose file path','docker/compose.yaml','compose.yaml trên máy\ncompose.production.yaml cho bản server'],
    ['Context directory','Để trống để dùng thư mục compose docker','Để trống để dùng thư mục gốc'],
    ['Additional env file','Để trống nếu nhập biến ở panel env','Để trống nếu nhập biến ở panel env'],
],[1.55,2.7,2.7])
note('Không chạy trùng','Trên máy đang có hai web, chưa bấm Create hoặc Deploy now với tên project và cổng đang dùng. Muốn thử song song phải sửa cả name trong compose, project, cổng và volumes; chỉ đổi Stack name ở biểu mẫu chưa đủ.')

page('10 Biến môi trường và lần triển khai đầu')
step(1,'Nhập biến ở panel Environment variables của From Git. Dùng Populate để thấy các biến compose cần; nhập giá trị thật và đánh dấu Secret cho mật khẩu. Không tải file env lên repository.')
table(['Bản triển khai','Biến và tài nguyên phải chuẩn bị'],[
    ['Client','APP_URL, DB_PASSWORD, HTTP_PORT, HTTP_BIND, cookie và proxy phù hợp. Giữ source/.env riêng có APP_KEY và cấu hình Laravel.'],
    ['Hub dùng compose.yaml','APP_URL, DB_PASSWORD và .env ứng dụng có APP_KEY. Cần build frontend, cài vendor và migrate ở lần đầu.'],
    ['Hub dùng compose.production.yaml','APP_URL, APP_KEY, DB_PASSWORD, WEB_PORT; tạo network edge trước. Bản này đóng gói code vào image và dùng volume storage riêng.'],
],[2.0,4.95])
step(2,'Bật Build images on deploy vì compose của hai dự án có build:. Thông thường để Disable build cache tắt. Re-pull images dùng cho image trên registry; không bật để cố tải image local.')
step(3,'Ban đầu để scheduled sync và webhook OFF, triển khai thủ công để dễ kiểm tra. Chỉ bật tự động sau khi quy trình build, migration, sao lưu và phục hồi đã hoàn chỉnh.')
shot('03-from-git-crop','Hình 7  Build images on deploy cùng các tùy chọn cache pull và recreate',max_h=2.6)
step(4,'Chuẩn bị .env ứng dụng, build frontend và dữ liệu ban đầu trước khi mở web cho người dùng. Chọn Deploy now rồi Create khi mọi điều kiện đã đủ; theo dõi log clone, build và khởi chạy.')
note('Cần hiểu đúng','Env panel phục vụ Docker Compose, không tự tạo source/.env cho Client hoặc mọi cấu hình còn thiếu của Laravel. Với repo mới, một lần bấm Create chưa hoàn tất toàn bộ khởi tạo ứng dụng.')

page('11 Cập nhật bản đang chạy bằng Git')
step(1,'Sửa code trên máy, chạy kiểm tra và build thử. Commit rồi Push lên nhánh mà stack theo dõi. Ghi lại mã commit trước và sau cập nhật để đối chiếu.')
step(2,'Vào stack có nguồn Git. Dùng thao tác đồng bộ và triển khai của stack để lấy commit mới rồi chạy lại compose. Nếu giao diện hiển thị Sync và Deploy riêng, thực hiện Sync trước, kiểm tra diff rồi Deploy.')
step(3,'Kiểm tra log đã lấy đúng nhánh và đúng commit. Với bản production đóng gói code, bật Build images on deploy để code mới được đưa vào image. Với bind mount, vẫn phải xử lý cache và các tiến trình chạy lâu.')
step(4,'Khi có thư viện mới, cài theo composer.lock hoặc npm lock trong bước build. Khi có migration, sao lưu rồi chạy migrate --force trên app đúng môi trường. Dockhand không tự hiểu phải chạy migration Laravel.')
step(5,'Với Hub, kiểm tra app, web, worker và scheduler đã chạy phiên bản mới. Với Client, kiểm tra app và nginx. Thử đăng nhập, mở bài H5P, nhận tiến độ và điểm.')
h('Khi chỉ sửa cấu hình bí mật')
p('Sửa env trong môi trường triển khai, không Commit bí mật lên Git. Redeploy với tạo lại container để nhận biến Docker mới, rồi xóa cache cấu hình Laravel. Giữ APP_KEY và mật khẩu database đang dùng trừ khi có kế hoạch đổi riêng.')
h('Quay lại code trước khi bản mới lỗi')
step(1,'Dừng triển khai tự động trong lúc xử lý. Xem log để phân biệt lỗi code, env, image hay database.')
step(2,'Trên Git, tạo commit Revert cho thay đổi gây lỗi rồi Push lên nhánh triển khai. Đồng bộ, build và redeploy lại; kiểm tra commit được áp dụng.')
step(3,'Nếu thay đổi đã làm hỏng dữ liệu hoặc migration không tương thích, khôi phục theo bản sao lưu và kế hoạch database. Revert code không tự khôi phục dữ liệu.')
note('Chuyển Internal sang Git','Sao lưu database và file upload; đối chiếu compose, APP_KEY, cổng và tên volume. Thử bản Git riêng trước. Không xóa stack cũ hoặc volumes để chuyển nguồn; việc chuyển cần giữ đúng dữ liệu và đường dẫn.')

page('12 Đưa hai web lên server')
p('Server là một máy chạy Docker khác. Dockhand đang ở máy bạn chỉ quản lý Docker Desktop hiện tại; không tự trở thành môi trường chạy trên server. Cách dễ theo dõi là cài Dockhand ngay trên server rồi mở qua SSH tunnel hoặc VPN.')
step(1,'Cài Docker Engine và Compose trên server. Chuẩn bị domain riêng cho Hub và Client, HTTPS và reverse proxy. Giữ trang quản trị Dockhand trong mạng quản trị.')
step(2,'Dùng thư mục bền vững trên server, ví dụ /opt/h5p-client và /opt/h5p-hub. Sửa bind mount trong cấu hình Dockhand theo đường dẫn Linux thật, gắn cùng đường dẫn trong và ngoài container.')
step(3,'Đưa code lên Git rồi clone hoặc dùng From Git. Với Client, chạy thiết lập ban đầu của dự án trong thư mục repo mới; script setup.sh tạo env, cài thư viện và khởi tạo database. Chọn APP_URL HTTPS đúng từ đầu.')
code('APP_URL=https://client.example.com bash setup.sh')
p('Lệnh trên chỉ minh họa cho server mới, chạy trong Bash ở gốc Client. Thay domain thật và điền tài khoản quản trị khi được hỏi; không chạy lại seeding để cập nhật dữ liệu đang vận hành.')
step(4,'Với Hub, xem compose.production.yaml thay cho compose.yaml đang dùng trên Windows. Tạo network edge một lần; chuẩn bị APP_KEY, DB_PASSWORD, APP_URL, WEB_PORT; build frontend trước khi Docker đóng gói public.')
code('docker network create edge\ndocker compose --env-file .env -f compose.production.yaml up -d --build\ndocker compose --env-file .env -f compose.production.yaml exec app \\\n  php artisan migrate --force')
p('Nếu network edge đã tồn tại thì dùng lại. Đây là project h5p-hub-production với volume mới; muốn mang dữ liệu từ máy lên phải backup và restore database cùng storage, đồng thời giữ APP_KEY phù hợp.')
step(5,'Cấu hình reverse proxy tới cổng web nội bộ. Bổ sung restart: unless-stopped cho các dịch vụ cần tự chạy sau khi server khởi động; compose Client hiện chưa khai báo chính sách này.')
step(6,'Đăng ký hoặc triển khai hai stack trong Dockhand của server, kiểm tra HTTPS, đăng nhập, upload, mở bài, tiến độ và điểm. Cập nhật issuer, redirect, JWKS cùng cấu hình LTI theo domain mới ở cả Hub và Client.')
note('TRUSTED_PROXIES','Lấy IP hoặc dải mạng của proxy thực sự đứng ngay trước ứng dụng. Cloudflare đứng trước Nginx cần cấu hình cả chuỗi chuyển tiếp; không thêm IP H5P Hub chỉ vì Hub kết nối LTI. Không tin mọi proxy khi chưa xác định hạ tầng.')

page('13 Kiểm tra lỗi và hoàn tất triển khai')
table(['Hiện tượng','Kiểm tra và xử lý'],[
    ['Dockhand không mở','Bật Docker Desktop. Kiểm tra container dockhand và cổng 3000; dùng lệnh up ở mục 1 nếu đang dừng.'],
    ['Environment mất kết nối','Settings → Environments → Test connection. Kiểm tra mount docker.sock và quyền truy cập socket.'],
    ['Sửa code nhưng web chưa đổi','Xác định Internal hay Git. Internal phải sửa đúng thư mục gắn vào app; Git phải Push rồi Sync. Xóa cache Laravel và nạp lại các tiến trình.'],
    ['Sửa env rồi Restart vẫn cũ','Giá trị Docker environment cần Redeploy và Force recreate. Kiểm tra biến compose đang dùng và cấu hình Laravel đã cache.'],
    ['Pull access denied với image local','Bỏ Pull images hoặc Re-pull images; bật Build images để dựng image có build:.'],
    ['Không tìm thấy Dockerfile hoặc source','Kiểm tra Compose file path, context directory và bind mount. Client mặc định làm việc ở docker; Hub ở gốc repo.'],
    ['Git không clone hoặc sai main','Kiểm tra URL, credential đọc repo và tên branch tồn tại trên remote. Không dùng mật khẩu tài khoản Git làm token.'],
    ['Lỗi 500 hoặc Vite manifest missing','Xem log app; kiểm tra APP_KEY, DB, quyền storage, composer và public/build. Build frontend ở đúng dự án.'],
    ['Lỗi cổng hoặc database trống','Kiểm tra stack chạy trùng, name project, cổng và volume thực tế. Không tạo database mới rồi tưởng dữ liệu đã mất.'],
],[2.3,4.65])
h('Điều kiện hoàn tất')
p('Hai stack chạy ổn định; không có container khởi động lặp; đăng nhập được; mở được bài H5P từ Client; điểm và tiến độ về đúng khóa học; log không phát sinh lỗi mới. Có bản sao lưu và biết commit đang triển khai.')
h('Nguồn tham khảo')
p('Giao diện Dockhand 1.0.58 và cấu hình hai dự án trên máy được kiểm tra ngày 20 tháng 11 năm 2026. Ảnh From Git là màn hình thiết lập, chưa tạo hoặc triển khai repository mẫu.')
p('Dockhand User Manual: https://dockhand.pro/manual/\nDocker Compose up: https://docs.docker.com/reference/cli/docker/compose/up/\nDocker Compose restart: https://docs.docker.com/reference/cli/docker/compose/restart/')

out=ROOT/'Huong-dan-Deploy-Dockhand-H5P.docx'; DOC.save(out); print(out.resolve())
