#!/usr/bin/env python3
"""A throwaway SMTP server for tests/mail.php. It is test equipment, not part of the site.

It opens one listener per scenario on a free local port, each behaving like a different kind
of real mail server, and writes what it received to <outdir>/<scenario>.jsonl so the PHP test
can check the message byte for byte. Once every listener is up it writes {scenario: port} to
--state, which is how the test knows it may start.

  plain       a local mail catcher (Mailpit, MailHog): no TLS, no sign-in
  auth        sign-in offered on a plain connection (an old relay)
  starttls    port-587 style: STARTTLS first, sign-in only afterwards, mail refused without it
  implicit    port-465 style: TLS from the first byte, sign-in required
  nostarttls  offers sign-in but no STARTTLS (a client told to encrypt must not send the password)
  loginonly   offers only AUTH LOGIN, the old Microsoft-style mechanism
  unsupported offers only sign-in mechanisms the site does not implement (XOAUTH2, CRAM-MD5)
  reject      refuses every recipient (550)
  rejectfrom  refuses the sender (550)
  dropbanner  closes the connection straight after the greeting
  dropmail    closes the connection when MAIL FROM arrives
  silent      accepts the connection and never says a word
  small       advertises SIZE 1500
  garbage     answers with something that is not SMTP (control characters and bytes that are not text included)
  hostile     refuses sign-in with a reply that holds control characters, bytes that are not text, a very long
              tail, and the password it was sent, in plain and in base64: the way a careless server behaves
  greylist    defers the message after DATA (451)

Sign-in everywhere is  mailer / s3cret-pass.  The certificate (CN=localhost, SAN localhost and
127.0.0.1) is generated with openssl at start and written to <outdir>/cert.pem.
Needs only python3 (3.8+) and, for the TLS scenarios, the openssl command (found on PATH, or inside Git for Windows).
"""
import argparse
import base64
import json
import os
import re
import shutil
import socket
import ssl
import subprocess
import sys
import threading
import time

USER, PASSWORD = "mailer", "s3cret-pass"

SCENARIOS = {
    "plain": {},
    "auth": {"auth": True, "require_auth": True},
    "starttls": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True},
    "implicit": {"implicit": True, "auth": True, "require_auth": True},
    "nostarttls": {"auth": True},
    "loginonly": {"auth": True, "auth_mechs": "LOGIN"},
    "unsupported": {"auth": True, "auth_mechs": "XOAUTH2 CRAM-MD5"},
    "reject": {"reject_rcpt": True},
    "rejectfrom": {"reject_from": True},
    "dropbanner": {"drop": "banner"},
    "dropmail": {"drop": "mail"},
    "silent": {"silent": True},
    "small": {"size": 1500},
    "garbage": {"garbage": True},
    "hostile": {"auth": True, "require_auth": True, "hostile": True},
    "greylist": {"data_reply": "451 4.7.1 Greylisted, please try again later"},
}

lock = threading.Lock()
last_activity = time.time()


def record(outdir, name, entry):
    with lock:
        with open(os.path.join(outdir, name + ".jsonl"), "a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry) + "\n")


class Session:
    def __init__(self, sock, name, opts, ctx, outdir):
        self.sock, self.name, self.opts, self.ctx, self.outdir = sock, name, opts, ctx, outdir
        self.buf = b""
        self.tls = False
        self.authed = None
        self.ehlo = ""
        self.mail_from = None
        self.rcpts = []

    def event(self, kind, **extra):
        record(self.outdir, self.name, dict(event=kind, tls=self.tls, **extra))

    def send(self, text):
        self.sock.sendall(text.encode("utf-8") + b"\r\n")

    def readline(self):
        while b"\n" not in self.buf:
            try:
                chunk = self.sock.recv(4096)
            except (socket.timeout, ssl.SSLError, OSError):
                return None
            if not chunk:
                return None
            self.buf += chunk
        line, self.buf = self.buf.split(b"\n", 1)
        return line.rstrip(b"\r")

    def upgrade(self):
        self.sock = self.ctx.wrap_socket(self.sock, server_side=True)
        self.sock.settimeout(15)
        self.buf = b""
        self.tls = True

    def run(self):
        self.sock.settimeout(15)
        o = self.opts
        self.event("connect")
        if o.get("silent"):
            time.sleep(20)
            return
        if o.get("garbage"):
            self.sock.sendall(b"\x1b[31m<html>\x00\x07<body>This is a web server, not a mail server.\xff\xfe</body></html>\r\n")
            return
        if o.get("implicit"):
            try:
                self.upgrade()
            except (ssl.SSLError, OSError) as exc:
                self.event("tls_failed", why=str(exc)[:120])
                return
        self.send("220 sink.test ESMTP ready")
        if o.get("drop") == "banner":
            return
        while True:
            raw = self.readline()
            if raw is None:
                return
            line = raw.decode("utf-8", "replace")
            verb = line.split(" ", 1)[0].upper()
            if verb in ("EHLO", "HELO"):
                self.ehlo = line[5:].strip()
                self.mail_from, self.rcpts = None, []
                self.event("ehlo", name=self.ehlo)
                if verb == "HELO":
                    self.send("250 sink.test")
                    continue
                caps = ["sink.test greets " + self.ehlo, "8BITMIME"]
                if o.get("size"):
                    caps.append("SIZE %d" % o["size"])
                if o.get("starttls") and not self.tls:
                    caps.append("STARTTLS")
                if o.get("auth") and not (o.get("auth_needs_tls") and not self.tls):
                    caps.append("AUTH " + o.get("auth_mechs", "PLAIN LOGIN"))
                for i, cap in enumerate(caps):
                    self.send(("250-" if i < len(caps) - 1 else "250 ") + cap)
            elif verb == "STARTTLS":
                if not o.get("starttls") or self.tls:
                    self.send("503 5.5.1 STARTTLS not available")
                    continue
                self.send("220 2.0.0 Ready to start TLS")
                try:
                    self.upgrade()
                except (ssl.SSLError, OSError) as exc:
                    self.event("tls_failed", why=str(exc)[:120])
                    return
                self.event("starttls_ok")
            elif verb == "AUTH":
                self.event("auth_attempt")
                if not o.get("auth"):
                    self.send("502 5.5.1 Authentication not supported")
                    continue
                if o.get("auth_needs_tls") and not self.tls:
                    self.send("530 5.7.0 Must issue a STARTTLS command first")
                    continue
                self.authenticate(line)
            elif verb == "MAIL":
                if o.get("drop") == "mail":
                    return
                if o.get("require_auth") and not self.authed:
                    self.send("530 5.7.0 Authentication required")
                    continue
                if o.get("require_tls") and not self.tls:
                    self.send("530 5.7.0 Must issue a STARTTLS command first")
                    continue
                if o.get("reject_from"):
                    self.event("from_rejected", line=line)
                    self.send("550 5.7.1 Sender address not allowed for this account")
                    continue
                m = re.match(r"MAIL FROM:\s*<([^>]*)>", line, re.I)
                if not m:
                    self.send("501 5.5.4 Syntax error in MAIL FROM")
                    continue
                self.mail_from = m.group(1)
                self.send("250 2.1.0 Sender OK")
            elif verb == "RCPT":
                if self.mail_from is None:
                    self.send("503 5.5.1 Need MAIL first")
                    continue
                if o.get("reject_rcpt"):
                    self.event("rcpt_rejected", line=line)
                    self.send("550 5.1.1 Mailbox unavailable")
                    continue
                m = re.match(r"RCPT TO:\s*<([^>]*)>", line, re.I)
                if not m:
                    self.send("501 5.5.4 Syntax error in RCPT TO")
                    continue
                self.rcpts.append(m.group(1))
                self.send("250 2.1.5 Recipient OK")
            elif verb == "DATA":
                if not self.rcpts:
                    self.send("503 5.5.1 Need RCPT first")
                    continue
                self.send("354 End data with <CR><LF>.<CR><LF>")
                self.take_message()
            elif verb == "RSET":
                self.mail_from, self.rcpts = None, []
                self.send("250 2.0.0 OK")
            elif verb == "NOOP":
                self.send("250 2.0.0 OK")
            elif verb == "QUIT":
                self.send("221 2.0.0 Bye")
                return
            else:
                self.send("502 5.5.2 Command not recognized")

    def authenticate(self, line):
        parts = line.split()
        mech = parts[1].upper() if len(parts) > 1 else ""
        user = password = None
        if mech not in self.opts.get("auth_mechs", "PLAIN LOGIN").split():
            self.send("504 5.5.4 Unrecognized authentication type")
            return
        try:
            if mech == "PLAIN":
                blob = parts[2] if len(parts) > 2 else None
                if blob is None:
                    self.send("334 ")
                    raw = self.readline()
                    blob = raw.decode() if raw is not None else ""
                fields = base64.b64decode(blob).split(b"\0")
                user, password = fields[1].decode(), fields[2].decode()
            elif mech == "LOGIN":
                self.send("334 VXNlcm5hbWU6")
                user = base64.b64decode(self.readline() or b"").decode()
                self.send("334 UGFzc3dvcmQ6")
                password = base64.b64decode(self.readline() or b"").decode()
            else:
                self.send("504 5.5.4 Unrecognized authentication type")
                return
        except Exception:
            self.send("501 5.5.2 Cannot decode credentials")
            return
        if user == USER and password == PASSWORD:
            self.authed = user
            self.event("auth_ok", user=user, mech=mech)
            self.send("235 2.7.0 Authentication successful")
        elif self.opts.get("hostile"):
            self.event("auth_failed", user=user, mech=mech)
            self.sock.sendall(b"535 5.7.8 \x1b[31mBad login for " + user.encode() + b" with password " + password.encode()
                              + b" (" + base64.b64encode(password.encode()) + b")\x00\x07\xff\xfe " + b"padding " * 80 + b"\r\n")
        else:
            self.event("auth_failed", user=user, mech=mech)
            self.send("535 5.7.8 Authentication credentials invalid")

    def take_message(self):
        chunks = []
        size = 0
        while True:
            raw = self.readline()
            if raw is None:
                return
            if raw == b".":
                break
            if raw.startswith(b".."):
                raw = raw[1:]  # undo dot-stuffing
            chunks.append(raw)
            size += len(raw) + 2
            if size > 2_000_000:
                self.send("552 5.3.4 Message too big")
                return
        data = b"\r\n".join(chunks) + b"\r\n"
        reply = self.opts.get("data_reply")
        if reply:
            self.event("data_deferred", reply=reply)
            self.send(reply)
            return
        self.event("message", mail_from=self.mail_from, rcpts=self.rcpts, authed=self.authed,
                   ehlo=self.ehlo, data=data.decode("latin-1"))
        self.mail_from, self.rcpts = None, []
        self.send("250 2.0.0 Queued as TEST%04d" % (int(time.time()) % 10000))


def serve(name, opts, ctx, outdir, ready, ports):
    global last_activity
    srv = socket.socket()
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", 0))
    srv.listen(16)
    ports[name] = srv.getsockname()[1]
    ready.release()
    while True:
        conn, _ = srv.accept()
        last_activity = time.time()
        sess = Session(conn, name, opts, ctx, outdir)

        def handle(sess=sess):
            try:
                sess.run()
            except Exception as exc:  # a test server must not die on one odd client
                try:
                    sess.event("server_error", why=repr(exc)[:160])
                except Exception:
                    pass
            finally:
                try:
                    sess.sock.close()
                except Exception:
                    pass

        threading.Thread(target=handle, daemon=True).start()


def find_openssl():
    """The openssl command, or None. On Windows it is usually only inside Git for Windows."""
    found = shutil.which("openssl")
    if found:
        return found
    for base in (os.environ.get("ProgramFiles", r"C:\Program Files"), os.environ.get("ProgramW6432", r"C:\Program Files")):
        for rel in (r"Git\usr\bin\openssl.exe", r"Git\mingw64\bin\openssl.exe", r"OpenSSL-Win64\bin\openssl.exe"):
            candidate = os.path.join(base, rel)
            if os.path.isfile(candidate):
                return candidate
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--outdir", required=True)
    ap.add_argument("--state", required=True)
    ap.add_argument("--scenarios", default=",".join(SCENARIOS))
    ap.add_argument("--idle-exit", type=int, default=180, help="seconds without a connection before exiting")
    args = ap.parse_args()

    os.makedirs(args.outdir, exist_ok=True)
    for old in os.listdir(args.outdir):
        if old.endswith(".jsonl"):
            os.remove(os.path.join(args.outdir, old))

    names = [n for n in args.scenarios.split(",") if n in SCENARIOS]
    cert, key = os.path.join(args.outdir, "cert.pem"), os.path.join(args.outdir, "key.pem")
    ctx = None
    openssl = find_openssl()
    if openssl:
        # Its own tiny config: works the same with OpenSSL 1.1, 3.x and LibreSSL, and does not
        # depend on a system openssl.cnf that a Windows machine may not have.
        conf = os.path.join(args.outdir, "openssl.cnf")
        with open(conf, "w") as fh:
            fh.write("[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n"
                     "[dn]\nCN=localhost\n[v3]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n")
        try:
            subprocess.run([openssl, "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", key, "-out", cert,
                            "-days", "2", "-config", conf],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=dict(os.environ, OPENSSL_CONF=conf))
            ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            ctx.load_cert_chain(cert, key)
        except (OSError, subprocess.CalledProcessError, ssl.SSLError):
            ctx = None
    if ctx is None:
        names = [n for n in names if not (SCENARIOS[n].get("starttls") or SCENARIOS[n].get("implicit"))]
        cert = ""

    ready, ports = threading.Semaphore(0), {}
    for name in names:
        threading.Thread(target=serve, args=(name, SCENARIOS[name], ctx, args.outdir, ready, ports), daemon=True).start()
    for _ in names:
        ready.acquire()
    tmp = args.state + ".tmp"
    with open(tmp, "w") as fh:
        json.dump({"ports": ports, "cert": cert, "user": USER, "password": PASSWORD}, fh)
    os.replace(tmp, args.state)  # appears complete or not at all
    print("READY", json.dumps(ports), flush=True)

    while time.time() - last_activity < args.idle_exit:
        time.sleep(1)


if __name__ == "__main__":
    sys.exit(main())
