import importlib.util
import os
from pathlib import Path
import subprocess
import sys
from pypdf import PdfReader
from PIL import Image, ImageDraw

ROOT=Path(__file__).resolve().parent
sys.stdout.reconfigure(encoding='utf-8')
skill=Path(r'C:\Users\admin\.codex\plugins\cache\openai-primary-runtime\documents\26.1116.12020\skills\documents\render_docx.py')
spec=importlib.util.spec_from_file_location('packaged_docx_renderer',skill)
renderer=importlib.util.module_from_spec(spec);spec.loader.exec_module(renderer)
out=ROOT/'render';out.mkdir(exist_ok=True)
os.environ['PATH']=r'C:\Users\admin\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin'+os.pathsep+os.environ['PATH']

# The Windows dependency bundle has no soffice executable. The isolated renderer
# supplies conversion; the packaged rasterize function still performs page QA output.
def container_convert(doc_path,user_profile,convert_tmp_dir,stem,verbose=False):
    args=['docker','exec','codex-docx-guide-renderer','libreoffice',
          '-env:UserInstallation=file:///tmp/docx-guide-profile','--headless',
          '--convert-to','pdf','--outdir','/work/render','/work/'+Path(doc_path).name]
    proc=subprocess.run(args,capture_output=True,text=True,timeout=120)
    result=out/(stem+'.pdf')
    if proc.returncode or not result.exists():raise RuntimeError(proc.stdout+proc.stderr)
    return str(result),proc.stdout+proc.stderr

renderer.convert_to_pdf=container_convert
files=renderer.rasterize(str(ROOT/'Huong-dan-Deploy-Dockhand-H5P.docx'),str(out),135,False,False)
pdf=PdfReader(out/'Huong-dan-Deploy-Dockhand-H5P.pdf')
print('PAGES',len(files))
for i,page in enumerate(pdf.pages,1):
    text=page.extract_text() or ''
    print(i,len(text),text[:105].replace('\n',' '))
thumbs=[]
for i,file in enumerate(files,1):
    im=Image.open(file);im.thumbnail((414,586));canvas=Image.new('RGB',(434,616),'#e1e5e9')
    canvas.paste(im,((434-im.width)//2,8));ImageDraw.Draw(canvas).text((12,598),str(i),fill='black');thumbs.append(canvas)
contact=Image.new('RGB',(434*4,616*((len(thumbs)+3)//4)),'white')
for i,im in enumerate(thumbs):contact.paste(im,((i%4)*434,(i//4)*616))
contact.save(out/'contact.png')
