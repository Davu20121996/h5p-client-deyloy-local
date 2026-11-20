from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
import urllib.request,urllib.error
class Proxy(BaseHTTPRequestHandler):
 def do_GET(self):
  if not self.path.startswith(('/debian/','/debian-security/')):
   self.send_error(404); return
  try:
   with urllib.request.urlopen('https://deb.debian.org'+self.path,timeout=40) as r:
    self.send_response(r.status)
    for key in ['Content-Length','Content-Type','Last-Modified']:
     if r.headers.get(key): self.send_header(key,r.headers[key])
    self.end_headers()
    while True:
     data=r.read(1024*1024)
     if not data: break
     self.wfile.write(data)
  except urllib.error.HTTPError as e: self.send_error(e.code)
  except Exception: self.send_error(502)
 def log_message(self,*args): pass
print('Temporary Debian package relay ready',flush=True)
ThreadingHTTPServer(('0.0.0.0',9018),Proxy).serve_forever()
