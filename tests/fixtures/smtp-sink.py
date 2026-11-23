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
  oauth       Microsoft 365 style: STARTTLS first, then only AUTH XOAUTH2, which takes an access token that
              the token service below issued (a refused one is a plain 535)
  oauth334    Gmail style: a refused token is answered with 334 and a base64 explanation; the client must send
              an empty line before the 535 arrives (a client that does not waits until it times out)
  oauthoff    Authenticated SMTP switched off for the mailbox (535 5.7.139)
  oauthecho   refuses a token with a reply that holds the token and its base64, the careless server again
  basicoff    Microsoft 365 after password sign-in is switched off: AUTH LOGIN is answered 550 5.7.30
  oauthnotls  offers AUTH XOAUTH2 on a connection with no STARTTLS (a token must never be sent over that)
  oldtls      starttls, but a server whose newest TLS is 1.1 (a client that will not speak less than 1.2 must walk away
              before sending the password); needs a Python/OpenSSL that can still serve TLS 1.1, and is left out where not
  oldtlsimplicit  the same, from the first byte (port 465 style)

Sign-in everywhere is  mailer / s3cret-pass.  The certificate (CN=localhost, SAN localhost and
127.0.0.1) is generated with openssl at start and written to <outdir>/cert.pem.
A second kind of listener stands in for Microsoft's sign-in service (login.microsoftonline.com): one plain HTTP
port ("token_http") and one HTTPS port ("token_https", the same certificate). Which behaviour a request gets
is chosen by the tenant in its path, /<tenant>/oauth2/v2.0/token, the way a real service would be chosen by
address; see TENANTS. The access tokens it issues are the only ones the oauth* scenarios above accept, because
both live in this one process. POST /_control/approve approves every pending device sign-in, and
POST /_control/revoke takes every access token back (a token that expires early or is withdrawn). Tenant
"racedisconnect" does what a person pressing Disconnect while a token request is in flight does: the file named in
<outdir>/race-store loses its "user" entry just before the request is answered.
Every event carries t, the time it happened (seconds since 1970), so a test can say what came before what.

Needs only python3 (3.8+) and, for the TLS scenarios, the openssl command (found on PATH, or inside Git for Windows).
"""
import argparse
import base64
import json
import os
import re
import secrets
import shutil
import socket
import ssl
import subprocess
import sys
import threading
import time
import urllib.parse

USER, PASSWORD = "mailer", "s3cret-pass"

# The OAuth side. The mailbox the oauth* scenarios expect in the sign-in string, the app registrations the
# token service knows, and what it hands out. Values are made up for the tests; nothing here is real.
OAUTH_MAILBOX = "mailbox@contoso.test"
APP_CLIENT_ID, APP_SECRET = "11111111-1111-4111-8111-111111111111", "Sup3r~Secret.Value-0123456789"
USER_CLIENT_ID = "22222222-2222-4222-8222-222222222222"
SCOPE_APP = "https://outlook.office365.com/.default"
SCOPE_USER_NEEDS = ("https://outlook.office.com/SMTP.Send", "offline_access")

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
    "oauth": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True, "auth_mechs": "XOAUTH2"},
    "oauth334": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True,
                 "auth_mechs": "LOGIN PLAIN XOAUTH2", "oauth_challenge": True},
    "oauthoff": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True,
                 "auth_mechs": "XOAUTH2", "oauth_refuse_all": True,
                 "oauth_error": "535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant. "
                                "Visit https://aka.ms/smtp_auth_disabled for more information."},
    "oauthecho": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True,
                  "auth_mechs": "XOAUTH2", "oauth_echo": True},
    "oauthnotls": {"auth": True, "auth_mechs": "XOAUTH2"},
    "oldtls": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True, "old_tls": True},
    "oldtlsimplicit": {"implicit": True, "auth": True, "require_auth": True, "old_tls": True},
    "basicoff": {"starttls": True, "auth": True, "auth_needs_tls": True, "require_auth": True, "require_tls": True,
                 "auth_mechs": "LOGIN XOAUTH2", "basic_error": "550 5.7.30 Basic authentication is not supported for Client Submission. "
                                                                "For more information see https://aka.ms/smtp_auth_disabled"},
}

# Access and refresh tokens the token service issued, shared with the SMTP scenarios (one process).
ACCESS = {}    # access token -> {"user": mailbox, "expires": unix time}
REFRESH = {}   # refresh token -> {"user": mailbox}
DEVICES = {}   # device_code -> {"approved": bool, "user_code": str}

lock = threading.Lock()
last_activity = time.time()


def record(outdir, name, entry):
    entry = dict(entry, t=round(time.time(), 4))
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
        if mech == "XOAUTH2":
            self.authenticate_oauth(parts)
            return
        if self.opts.get("basic_error"):
            self.event("auth_failed", user="", mech=mech)
            self.send(self.opts["basic_error"])
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

    def authenticate_oauth(self, parts):
        """AUTH XOAUTH2 <base64>: user=<mailbox> ^A auth=Bearer <token> ^A ^A (RFC-less, but Microsoft's and Google's documents agree)."""
        blob = parts[2] if len(parts) > 2 else None
        if blob is None:
            self.send("334 ")
            raw = self.readline()
            blob = raw.decode() if raw is not None else ""
        try:
            text = base64.b64decode(blob, validate=True).decode("utf-8", "replace")
        except Exception:
            self.send("501 5.5.2 Cannot decode credentials")
            return
        m = re.fullmatch(r"user=(.*)\x01auth=Bearer (.*)\x01\x01", text, re.S)
        if not m:
            self.event("auth_failed", user="", mech="XOAUTH2", why="malformed")
            self.send("535 5.7.3 Authentication unsuccessful")
            return
        user, token = m.group(1), m.group(2)
        with lock:
            entry = ACCESS.get(token)
            good = entry is not None and entry["expires"] > time.time() and user == OAUTH_MAILBOX and entry["user"] == OAUTH_MAILBOX
        # "oauth_refuse_all": a perfectly good token is refused too (Authenticated SMTP switched off for the mailbox).
        if good and not self.opts.get("oauth_refuse_all"):
            self.authed = user
            self.event("auth_ok", user=user, mech="XOAUTH2", token=token)
            self.send("235 2.7.0 Authentication successful")
            return
        self.event("auth_failed", user=user, mech="XOAUTH2", token=token)
        o = self.opts
        if o.get("oauth_error"):
            self.send(o["oauth_error"])
        elif o.get("oauth_echo"):
            self.sock.sendall(("535 5.7.3 Authentication unsuccessful for " + user + " with token " + token + " (" + base64.b64encode(token.encode()).decode()
                               + ") and " + blob + "\x00\x07\xff").encode("latin-1", "replace") + b"\r\n")
        elif o.get("oauth_challenge"):
            # Google's behaviour: explain with a 334, wait for the client's empty line, then refuse for good.
            explanation = base64.b64encode(json.dumps({"status": "401", "schemes": "Bearer", "scope": "https://mail.google.com/"}).encode()).decode()
            self.send("334 " + explanation)
            answer = self.readline()
            self.event("challenge_answered", line=None if answer is None else answer.decode("latin-1"))
            if answer is None:
                return
            self.send("535 5.7.8 Username and Password not accepted. For more information, go to https://support.google.com/mail/?p=BadCredentials")
        else:
            self.send("535 5.7.3 Authentication unsuccessful [CO1PR00CA0001.namprd00.prod.outlook.com 2026-11-23T10:00:00]")

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


def jwt(payload):
    def part(data):
        return base64.urlsafe_b64encode(json.dumps(data).encode()).decode().rstrip("=")
    return part({"typ": "JWT", "alg": "none"}) + "." + part(payload) + "." + secrets.token_urlsafe(24)


def new_access(user, lives):
    token = "EwB" + secrets.token_urlsafe(48) + "." + secrets.token_urlsafe(700)   # long, as the real ones are
    with lock:
        ACCESS[token] = {"user": user, "expires": time.time() + lives}
    return token


def aad_error(status, error, code, text):
    body = {"error": error, "error_description": "AADSTS%d: %s Trace ID: 0a0a0a0a-0000-0000-0000-000000000000 Correlation ID: "
            "1b1b1b1b-0000-0000-0000-000000000000 Timestamp: 2026-11-23 10:00:00Z" % (code, text), "error_codes": [code]}
    return status, json.dumps(body).encode(), {}


# Which tenant name in the path gets which behaviour: "contoso" is the well-behaved one; "short" issues tokens that
# last two minutes; "auto" approves device sign-ins as soon as they are asked for; slow, dropped, garbage, redirect, html, badjson, huge, echo, chunked, closedelim, longheader
# hugeheader, hugeclose and hugechunk each misbehave in the way their name says (see TokenSession.run); evilurl and evilcode answer a
# device sign-in with a javascript: address, an address with markup in it, and markup for a code.


class TokenSession:
    def __init__(self, sock, outdir, tls_name):
        self.sock, self.outdir, self.tls_name = sock, outdir, tls_name
        self.buf = b""

    def event(self, kind, **extra):
        record(self.outdir, self.tls_name, dict(event=kind, **extra))

    def readline(self):
        while b"\n" not in self.buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                return None
            self.buf += chunk
        line, self.buf = self.buf.split(b"\n", 1)
        return line.rstrip(b"\r")

    def read_request(self):
        request = self.readline()
        if request is None:
            return None
        headers = {}
        while True:
            line = self.readline()
            if line is None:
                return None
            if line == b"":
                break
            k, _, v = line.decode("latin-1").partition(":")
            headers[k.strip().lower()] = v.strip()
        length = int(headers.get("content-length", "0") or 0)
        while len(self.buf) < length:
            chunk = self.sock.recv(4096)
            if not chunk:
                return None
            self.buf += chunk
        body, self.buf = self.buf[:length], self.buf[length:]
        return request.decode("latin-1"), headers, body.decode("utf-8", "replace")

    def respond(self, status, body, extra=None):
        reasons = {200: "OK", 302: "Found", 400: "Bad Request", 401: "Unauthorized", 404: "Not Found"}
        headers = {"Content-Type": "application/json; charset=utf-8"}
        headers.update(extra or {})
        if "Transfer-Encoding" not in headers:
            headers["Content-Length"] = str(len(body))
        headers["Connection"] = "close"
        head = "HTTP/1.1 %d %s\r\n" % (status, reasons.get(status, "Status")) + "".join("%s: %s\r\n" % kv for kv in headers.items()) + "\r\n"
        self.sock.sendall(head.encode("latin-1") + body)

    def run(self):
        self.sock.settimeout(15)
        got = self.read_request()
        if got is None:
            return
        request, headers, body = got
        method, _, rest = request.partition(" ")
        path = rest.split(" ")[0]
        form = {k: v[0] for k, v in urllib.parse.parse_qs(body, keep_blank_values=True).items()}
        self.event("request", method=method, path=path, host=headers.get("host", ""), ctype=headers.get("content-type", ""), form=form)
        if path.startswith("/_control/"):
            return self.control(path)
        m = re.fullmatch(r"/([^/]+)/oauth2/v2\.0/(token|devicecode)", path.split("?")[0])
        if method != "POST" or not m:
            return self.respond(404, b'{"error":"not_found"}')
        tenant, endpoint = m.group(1), m.group(2)
        if tenant == "racedisconnect":
            # Somebody presses Disconnect while this request is being answered: the saved sign-in is gone from its file
            # by the time the answer arrives. (The test says which file in <outdir>/race-store.)
            try:
                with open(os.path.join(self.outdir, "race-store"), encoding="utf-8") as fh:
                    target = fh.read().strip()
                with open(target, encoding="utf-8") as fh:
                    saved = json.load(fh)
                saved.pop("user", None)
                with open(target, "w", encoding="utf-8") as fh:
                    json.dump(saved, fh)
                self.event("race_disconnected")
            except (OSError, ValueError):
                self.event("race_not_set_up")
        if tenant == "slow":
            time.sleep(20)
            return
        if tenant == "dropped":
            return
        if tenant == "garbage":
            self.sock.sendall(b"SSH-2.0-OpenSSH_9.6\r\n")
            return
        if tenant == "redirect":
            return self.respond(302, b"", {"Location": "http://evil.example/steal"})
        if tenant == "html":
            return self.respond(200, b"<html><body>Sign in to your account</body></html>", {"Content-Type": "text/html"})
        if tenant == "badjson":
            return self.respond(200, b'{"access_token": ')
        if tenant == "huge":
            self.sock.sendall(b"HTTP/1.1 200 OK\r\nContent-Length: 5000000\r\nConnection: close\r\n\r\n" + b"x" * 600000)
            return
        if tenant == "hugeclose":
            # No length at all: the body ends when the connection does. The client has to stop reading by itself.
            self.sock.sendall(b"HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" + b"x" * 600000)
            return
        if tenant == "hugechunk":
            # Chunks that are each modest and together far too much (6 x 100000 bytes; 0x186a0 is 100000).
            pieces = b"".join(b"186a0\r\n" + b"x" * 100000 + b"\r\n" for _ in range(6)) + b"0\r\n\r\n"
            self.sock.sendall(b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n" + pieces)
            return
        if tenant == "echo":
            # The request echoed as it travelled (URL-encoded) first, then as decoded values, then a lot of padding.
            text = "Raw request: %s. The request carried client_secret=%s and refresh_token=%s and device_code=%s and this is wrong." % (
                urllib.parse.urlencode(form), form.get("client_secret", ""), form.get("refresh_token", ""), form.get("device_code", "")) + " padding" * 400
            return self.respond(*aad_error(400, "invalid_request", 900144, text)[:2])
        if tenant == "hugeheader":
            self.sock.sendall(b"HTTP/1.1 200 OK\r\nX-Far-Too-Long: " + b"a" * 200000 + b"\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}")
            return
        status, body_bytes, extra = self.issue(tenant, endpoint, form)
        if tenant == "longheader":
            # What a real service sends: cookies and policy headers far longer than a line buffer.
            extra = {**extra, "X-Long-Header": "a" * 5000, "Set-Cookie": "esctx=" + "b" * 3000 + "; path=/; secure; HttpOnly"}
        if tenant == "chunked":
            third = max(1, len(body_bytes) // 3)
            pieces = [body_bytes[:third], body_bytes[third:2 * third], body_bytes[2 * third:]]
            out = b"".join(("%x;ext=1\r\n" % len(p)).encode() + p + b"\r\n" for p in pieces if p) + b"0\r\nX-Trailer: yes\r\n\r\n"
            return self.respond(status, out, {"Transfer-Encoding": "chunked"})
        if tenant == "closedelim":
            self.sock.sendall(b"HTTP/1.1 %d OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n" % status + body_bytes)
            return
        self.respond(status, body_bytes, extra)

    def control(self, path):
        with lock:
            if path.startswith("/_control/approve"):
                for d in DEVICES.values():
                    d["approved"] = True
            elif path.startswith("/_control/revoke"):
                ACCESS.clear()
        self.respond(200, b'{"ok":true}')

    def issue(self, tenant, endpoint, form):
        lives = 120 if tenant == "short" else 3599
        grant = form.get("grant_type", "")
        client = form.get("client_id", "")
        if endpoint == "devicecode":
            scope = form.get("scope", "")
            if client != USER_CLIENT_ID:
                return aad_error(400, "unauthorized_client", 700016, "Application with identifier '%s' was not found in the directory 'Contoso'." % client)
            if not all(need in scope.split() for need in SCOPE_USER_NEEDS):
                return aad_error(400, "invalid_scope", 70011, "The provided value for the input parameter 'scope' is not valid.")
            device_code, user_code = secrets.token_urlsafe(60), "AB12CD34"
            uri = "https://microsoft.com/devicelogin"
            if tenant == "evilurl":
                uri = "javascript:alert(document.cookie)"
            if tenant == "evilquote":
                uri = 'https://microsoft.com/devicelogin?x="><script>alert(1)</script>'
            if tenant == "evilcode":
                user_code = "<script>alert(1)</script>"
            with lock:
                # tenant "auto": the person signs in at once (for a command-line run nobody can press anything during)
                DEVICES[device_code] = {"approved": tenant == "auto", "user_code": user_code}
            return 200, json.dumps({"user_code": user_code, "device_code": device_code, "verification_uri": uri,
                                    "expires_in": 900, "interval": 5, "message": "To sign in, use a web browser to open the page https://microsoft.com/devicelogin and enter the code."}).encode(), {}
        if grant == "client_credentials":
            if client != APP_CLIENT_ID:
                return aad_error(400, "invalid_request", 700016, "Application with identifier '%s' was not found in the directory 'Contoso'." % client)
            if form.get("client_secret", "") != APP_SECRET:
                return aad_error(401, "invalid_client", 7000215, "Invalid client secret provided. Ensure the secret being sent in the request is the client secret value, not the client secret ID, for a secret added to app '%s'." % client)
            if form.get("scope", "") != SCOPE_APP:
                return aad_error(400, "invalid_scope", 70011, "The provided value for the input parameter 'scope' is not valid. The scope %s is not valid." % form.get("scope", ""))
            return 200, json.dumps({"token_type": "Bearer", "expires_in": lives, "ext_expires_in": lives, "access_token": new_access(OAUTH_MAILBOX, lives)}).encode(), {}
        if grant == "refresh_token":
            if client != USER_CLIENT_ID:
                return aad_error(400, "invalid_request", 700016, "Application with identifier '%s' was not found in the directory 'Contoso'." % client)
            with lock:
                known = form.get("refresh_token", "") in REFRESH
            if not known:
                return aad_error(400, "invalid_grant", 700082, "The refresh token has expired due to inactivity. The token was issued on 2026-08-01T00:00:00.0000000Z and was inactive for 90.00:00:00.")
            fresh = "0.A" + secrets.token_urlsafe(90)
            with lock:
                REFRESH[fresh] = {"user": OAUTH_MAILBOX}
            return 200, json.dumps({"token_type": "Bearer", "scope": " ".join(SCOPE_USER_NEEDS), "expires_in": lives, "access_token": new_access(OAUTH_MAILBOX, lives), "refresh_token": fresh}).encode(), {}
        if grant == "urn:ietf:params:oauth:grant-type:device_code":
            with lock:
                device = DEVICES.get(form.get("device_code", ""))
            if device is None:
                return aad_error(400, "bad_verification_code", 70000, "The provided device code is not valid.")
            if not device["approved"]:
                return aad_error(400, "authorization_pending", 70016, "OAuth 2.0 device flow error. Authorization is pending. Continue polling.")
            fresh = "0.A" + secrets.token_urlsafe(90)
            with lock:
                REFRESH[fresh] = {"user": OAUTH_MAILBOX}
            idt = jwt({"preferred_username": OAUTH_MAILBOX, "name": "Mail Box", "aud": USER_CLIENT_ID})
            return 200, json.dumps({"token_type": "Bearer", "scope": " ".join(SCOPE_USER_NEEDS), "expires_in": lives, "access_token": new_access(OAUTH_MAILBOX, lives),
                                    "refresh_token": fresh, "id_token": idt}).encode(), {}
        return aad_error(400, "unsupported_grant_type", 70003, "The app is configured with a grant type this endpoint does not support.")


def serve_tokens(label, ctx, outdir, ready, ports):
    global last_activity
    srv = socket.socket()
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", 0))
    srv.listen(16)
    ports[label] = srv.getsockname()[1]
    ready.release()
    while True:
        conn, _ = srv.accept()
        last_activity = time.time()

        def handle(conn=conn):
            sess = None
            try:
                if ctx is not None:
                    conn = ctx.wrap_socket(conn, server_side=True)
                sess = TokenSession(conn, outdir, label)
                sess.run()
            except Exception as exc:  # a test server must not die on one odd client
                try:
                    record(outdir, label, {"event": "server_error", "why": repr(exc)[:160]})
                except Exception:
                    pass
            finally:
                try:
                    conn.close()
                except Exception:
                    pass

        threading.Thread(target=handle, daemon=True).start()


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
    # A server that speaks nothing newer than TLS 1.1. Whether this Python and OpenSSL can still be one is found out, not
    # assumed: where they cannot, the scenarios that need it are simply not offered, and the test says it skipped them.
    old_ctx = None
    if ctx is not None and any(SCENARIOS[n].get("old_tls") for n in names):
        try:
            import warnings
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                old_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                old_ctx.load_cert_chain(cert, key)
                old_ctx.set_ciphers("ALL:@SECLEVEL=0")
                old_ctx.minimum_version = ssl.TLSVersion.TLSv1
                old_ctx.maximum_version = ssl.TLSVersion.TLSv1_1
        except (ValueError, ssl.SSLError, OSError, AttributeError):
            old_ctx = None
    if old_ctx is None:
        names = [n for n in names if not SCENARIOS[n].get("old_tls")]

    ready, ports = threading.Semaphore(0), {}
    for name in names:
        threading.Thread(target=serve, args=(name, SCENARIOS[name], old_ctx if SCENARIOS[name].get("old_tls") else ctx, args.outdir, ready, ports), daemon=True).start()
    token_listeners = [("token_http", None)] + ([("token_https", ctx)] if ctx is not None else [])
    for label, tls in token_listeners:
        threading.Thread(target=serve_tokens, args=(label, tls, args.outdir, ready, ports), daemon=True).start()
    for _ in range(len(names) + len(token_listeners)):
        ready.acquire()
    tmp = args.state + ".tmp"
    with open(tmp, "w") as fh:
        json.dump({"ports": ports, "cert": cert, "user": USER, "password": PASSWORD, "oauth": {
            "mailbox": OAUTH_MAILBOX, "app_client_id": APP_CLIENT_ID, "app_secret": APP_SECRET, "user_client_id": USER_CLIENT_ID}}, fh)
    os.replace(tmp, args.state)  # appears complete or not at all
    print("READY", json.dumps(ports), flush=True)

    while time.time() - last_activity < args.idle_exit:
        time.sleep(1)


if __name__ == "__main__":
    sys.exit(main())
