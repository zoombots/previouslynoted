import asyncio, ssl, sys, os, time, email
from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult, LoginPassword
OUT='/home/claude/build/smtp/inbox'; os.makedirs(OUT, exist_ok=True)
class H:
    async def handle_DATA(self, server, session, env):
        fn=f"{OUT}/{time.time():.6f}.eml"; open(fn,'wb').write(env.original_content or env.content)
        open(fn+'.env','w').write(f"from={env.mail_from}\nto={env.rcpt_tos}\nauth={session.authenticated}\n")
        return '250 OK queued'
def auth(server, session, envelope, mechanism, data):
    ok = isinstance(data, LoginPassword) and data.login==b'hello@previouslynoted.com' and data.password==b'mailbox-pass-1'
    return AuthResult(success=ok)
ctx=ssl.create_default_context(ssl.Purpose.CLIENT_AUTH); ctx.load_cert_chain('cert.pem','key.pem')
# STARTTLS on 2587, implicit TLS on 2465
c1=Controller(H(), hostname='127.0.0.1', port=2587, tls_context=ctx, require_starttls=True, authenticator=auth, auth_required=True, auth_require_tls=True)
c2=Controller(H(), hostname='127.0.0.1', port=2465, ssl_context=ctx, authenticator=auth, auth_required=True, auth_require_tls=False)
c1.start(); c2.start(); print('smtp up', flush=True)
while True: time.sleep(3600)
