from pathlib import Path
from pdf2image import convert_from_path
from pypdf import PdfReader
from PIL import Image,ImageOps,ImageDraw
root=Path('output/ba-report/render')
files=list(root.glob('*.pdf'))
if not files:
 print('Waiting for document preview'); raise SystemExit(0)
pdf=files[0]; pages=convert_from_path(str(pdf),dpi=110,poppler_path=r'C:\Users\admin\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin')
for i,im in enumerate(pages,1): im.save(root/f'page-{i}.png')
reader=PdfReader(pdf)
print('Pages:',len(pages))
for i,p in enumerate(reader.pages,1):
 text=p.extract_text() or ''; print(i,len(text),text[:85].replace('\n',' '))
thumbs=[]
for i,im in enumerate(pages,1):
 im.thumbnail((414,586)); canvas=Image.new('RGB',(434,616),'#d9dfe5'); canvas.paste(im,((434-im.width)//2,10)); ImageDraw.Draw(canvas).text((12,598),str(i),fill='black'); thumbs.append(canvas)
contact=Image.new('RGB',(434*4,616*((len(thumbs)+3)//4)),'white')
for i,im in enumerate(thumbs): contact.paste(im,((i%4)*434,(i//4)*616))
contact.save(root/'contact.png')
