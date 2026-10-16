#!/usr/bin/env python3
"""live_verify.py — shared helpers for the LIVE feature checks run against production.

scripts/ci/live-feature-checks.py in each repository imports this module. Those checks go
further than post-deploy-checks.sh (which proves the build is up and nothing private is served):
they prove the features themselves against the real systems — Console roles, the portals' role
re-checks, Manage's lifecycle, the portal's account closure, Flow's mail, storage and telephony.

Safety rules every check built on this module keeps:
  * read-only, or acting only as the dedicated verification identities and test company the
    owner created for this (docs/GO_LIVE_VERIFICATION.md in flow-react-app lists them); never
    a real customer's data;
  * a negative probe (an unsigned or unkeyed call that must be refused) always carries a body
    the endpoint would reject anyway, so a refusal that failed open still changes nothing;
  * nothing destructive without an explicit workflow input (the calling script checks it);
  * secrets come from the environment (GitHub secrets), are masked in the log, and are never
    put on a command line: a request retried from the server is handed to curl on stdin.

Like scripts/ci/verify-live.sh, a request the runner is shown the host's anti-bot page for (or
cannot connect with) is made again from the server itself over VERIFY_SSH. A real answer is
never retried from the server: it is the answer.

Results: every check is ok, fail, warn or skip. A skip is a check that could not run because a
secret or a test identity is not configured; with LIVE_VERIFY_STRICT=1 (the go-live run) a skip
fails the job, so a green strict run means everything was really proven. A summary table goes to
$GITHUB_STEP_SUMMARY.

Environment read here (all optional):
  VERIFY_SSH            command prefix that runs one command on the server (as verify-live.sh)
  VERIFY_TIMEOUT        seconds per request (default 25)
  LIVE_VERIFY_STRICT    1: a skipped check fails the run
  VERIFY_CONSOLE_API    Console API base (default https://console.aicountly.org/api)
  VERIFY_IMAP_HOST / _PORT (993) / _USER / _PASSWORD / _FOLDER (INBOX)
                        the mailbox that receives the verification accounts' Console sign-in codes
  VERIFY_STAFF_EMAIL / VERIFY_STAFF_PASSWORD     Console account holding a role in every app
  VERIFY_NOROLE_EMAIL / VERIFY_NOROLE_PASSWORD   Console account holding no role in any app
  VERIFY_DRILL_EMAIL / VERIFY_DRILL_PASSWORD     Console account for the revocation drill
  VERIFY_STAFF_EXPECTED_ROLES  JSON {"flow": ["team_lead"], "build": [], ...}: the roles the staff
                        account must hold per app; [] = it deliberately holds none there (a portal
                        whose only role is its administrator), so the portal must refuse it
  VERIFY_PORTAL_EMAIL / VERIFY_PORTAL_PASSWORD   my.aicountly verification customer (owns the test company)
  VERIFY_PORTAL_BASE    my.aicountly origin (default https://my.aicountly.com)

Canonical copy; keep every repository's scripts/ci/live_verify.py identical to it.
"""
from __future__ import annotations

import email
import email.header
import email.policy
import email.utils
import html
import imaplib
import json
import os
import re
import shlex
import smtplib
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from dataclasses import dataclass, field
from email.message import EmailMessage
from typing import Any, Callable, Iterable

SPLASH = re.compile(r"request is being verified|<title>One moment, please", re.I)
CONSOLE_DENY = "Access not assigned"
DEFAULT_CONSOLE_API = "https://console.aicountly.org/api"
CONTROLLER_COOKIE = "aicountly_controller_token"


# ── environment and secrets ─────────────────────────────────────────────────────────────────

def env(name: str, default: str = "") -> str:
    value = os.environ.get(name, "")
    value = value.strip() if isinstance(value, str) else ""
    return value if value != "" else default


def mask(value: str | None) -> None:
    """Hide a value from the rest of the job's log (GitHub Actions)."""
    if value and os.environ.get("GITHUB_ACTIONS") == "true":
        for line in str(value).splitlines():
            if line.strip():
                print(f"::add-mask::{line.strip()}", flush=True)


def secret(name: str) -> str:
    value = env(name)
    mask(value)
    return value


def flag(name: str) -> bool:
    return env(name).lower() in ("1", "true", "yes", "on")


def strict() -> bool:
    return flag("LIVE_VERIFY_STRICT")


def console_api() -> str:
    return env("VERIFY_CONSOLE_API", DEFAULT_CONSOLE_API).rstrip("/")


def expected_roles(app_code: str) -> list[str] | None:
    raw = env("VERIFY_STAFF_EXPECTED_ROLES")
    if raw == "":
        return None
    try:
        data = json.loads(raw)
    except ValueError:
        return None
    roles = data.get(app_code) if isinstance(data, dict) else None
    return [str(r) for r in roles] if isinstance(roles, list) else None


# ── results ─────────────────────────────────────────────────────────────────────────────────

class Report:
    """Collects check results, prints them as they happen and writes the step summary."""

    def __init__(self, title: str):
        self.title = title
        self.rows: list[tuple[str, str, str]] = []

    def _add(self, status: str, label: str, detail: str) -> None:
        detail = " ".join(str(detail).split())[:600]
        self.rows.append((status, label, detail))
        line = f"[{status.upper():4}] {label}: {detail}"
        if status == "fail":
            print(f"::error title=Live check::{label}: {detail}", flush=True)
        elif status in ("warn", "skip"):
            level = "error" if status == "skip" and strict() else "warning"
            print(f"::{level} title=Live check::{label}: {detail}", flush=True)
        print(line, flush=True)

    def ok(self, label: str, detail: str = "") -> None:
        self._add("ok", label, detail or "as expected")

    def fail(self, label: str, detail: str) -> None:
        self._add("fail", label, detail)

    def warn(self, label: str, detail: str) -> None:
        self._add("warn", label, detail)

    def skip(self, label: str, why: str) -> None:
        self._add("skip", label, why)

    def expect(self, cond: bool, label: str, good: str, bad: str) -> bool:
        if cond:
            self.ok(label, good)
        else:
            self.fail(label, bad)
        return cond

    def run(self, label: str, fn: Callable[[], Any]) -> Any:
        """Run one check function; an exception is that check's failure, not the run's end."""
        try:
            return fn()
        except Exception as exc:  # noqa: BLE001 — report and carry on with the next check
            self.fail(label, f"check raised {type(exc).__name__}: {exc}")
            return None

    def finish(self) -> int:
        counts = {s: sum(1 for r in self.rows if r[0] == s) for s in ("ok", "fail", "warn", "skip")}
        failed = counts["fail"] + (counts["skip"] if strict() else 0)
        summary = os.environ.get("GITHUB_STEP_SUMMARY")
        if summary:
            try:
                with open(summary, "a", encoding="utf-8") as fh:
                    fh.write(f"### {self.title}\n\n")
                    fh.write(f"{counts['ok']} ok, {counts['fail']} failed, {counts['warn']} warnings, "
                             f"{counts['skip']} skipped{' (strict: skips fail)' if strict() else ''}\n\n")
                    fh.write("| | Check | Detail |\n|---|---|---|\n")
                    icon = {"ok": "ok", "fail": "FAIL", "warn": "warn", "skip": "skip"}
                    for status, label, detail in self.rows:
                        fh.write(f"| {icon[status]} | {label} | {detail.replace('|', '/')} |\n")
                    fh.write("\n")
            except OSError:
                pass
        print(f"\n{self.title}: {counts['ok']} ok, {counts['fail']} failed, {counts['warn']} warnings, "
              f"{counts['skip']} skipped" + (" (strict)" if strict() else ""), flush=True)
        return 1 if failed else 0


# ── HTTP: the runner first, the server itself when the runner never reached the app ──────────

@dataclass
class Answer:
    status: int
    body: bytes = b""
    headers: dict[str, str] = field(default_factory=dict)
    place: str = "runner"
    error: str = ""

    @property
    def text(self) -> str:
        return self.body.decode("utf-8", "replace")

    def json(self) -> Any:
        try:
            return json.loads(self.body.decode("utf-8", "replace"))
        except ValueError:
            return None

    def brief(self, limit: int = 160) -> str:
        if self.status == 0:
            return f"no answer ({self.error})"
        snippet = " ".join(self.text.split())[:limit]
        return f"HTTP {self.status} from the {self.place}: {snippet}" + (f" — {self.error}" if self.error else "")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        return None


_OPENER = urllib.request.build_opener(_NoRedirect)


def _cookie_header(cookies: dict[str, str] | None) -> str:
    return "; ".join(f"{k}={v}" for k, v in (cookies or {}).items())


def _from_runner(method: str, url: str, headers: dict[str, str], data: bytes | None, timeout: int) -> Answer:
    try:
        req = urllib.request.Request(url, data=data, method=method)
        for k, v in headers.items():
            req.add_header(k, v)
        with _OPENER.open(req, timeout=timeout) as resp:
            return Answer(resp.status, resp.read(1_048_576), {k.lower(): v for k, v in resp.headers.items()}, "runner")
    except urllib.error.HTTPError as exc:
        body = b""
        try:
            body = exc.read(1_048_576)
        except Exception:  # noqa: BLE001
            pass
        return Answer(exc.code, body, {k.lower(): v for k, v in (exc.headers or {}).items()}, "runner")
    except Exception as exc:  # noqa: BLE001 — URLError, timeouts, TLS
        return Answer(0, b"", {}, "runner", f"{type(exc).__name__}: {exc}")


def _curl_quote(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\r", "\\r").replace("\t", "\\t") + '"'


def _from_server(method: str, url: str, headers: dict[str, str], data: bytes | None, timeout: int) -> Answer:
    """The same request made by the server itself: curl reads its whole configuration — URL,
    headers (credentials included) and body — from stdin, so nothing secret is on a command line."""
    prefix = env("VERIFY_SSH")
    marker = "__LIVE_VERIFY_HTTP_CODE__"
    lines = [
        f"url = {_curl_quote(url)}",
        f"request = {_curl_quote(method)}",
        "silent", "show-error",
        f"max-time = {timeout}",
        f"user-agent = {_curl_quote('aicountly-live-verify/1 (server)')}",
        f"write-out = {_curl_quote(chr(10) + marker + '%{http_code}')}",
    ]
    for k, v in headers.items():
        if k.lower() == "user-agent":
            continue
        lines.append(f"header = {_curl_quote(f'{k}: {v}')}")
    if data is not None:
        lines.append(f"data-binary = {_curl_quote(data.decode('utf-8', 'replace'))}")
    try:
        proc = subprocess.run(shlex.split(prefix) + ["curl -K -"], input="\n".join(lines) + "\n",
                              capture_output=True, text=True, timeout=timeout + 40)
    except Exception as exc:  # noqa: BLE001
        return Answer(0, b"", {}, "server", f"ssh failed: {type(exc).__name__}: {exc}")
    out = proc.stdout
    idx = out.rfind(marker)
    if idx < 0:
        return Answer(0, b"", {}, "server", f"no answer from the server's own request: {proc.stderr.strip()[:200]}")
    code = out[idx + len(marker):].strip()[:3]
    body = out[:idx]
    if body.endswith("\n"):
        body = body[:-1]
    status = int(code) if code.isdigit() else 0
    return Answer(status, body.encode("utf-8"), {}, "server", "" if status else proc.stderr.strip()[:200])


def request(method: str, url: str, *, headers: dict[str, str] | None = None, json_body: Any = None,
            data: bytes | str | None = None, cookies: dict[str, str] | None = None,
            bearer: str | None = None, timeout: int | None = None) -> Answer:
    """One request; asked again from the server (VERIFY_SSH) only when the runner was shown the
    anti-bot page or got no answer at all."""
    timeout = timeout or int(env("VERIFY_TIMEOUT", "25"))
    hdrs = {"Accept": "application/json", "Cache-Control": "no-cache",
            "User-Agent": "aicountly-live-verify/1 (runner)"}
    hdrs.update(headers or {})
    if bearer:
        hdrs["Authorization"] = f"Bearer {bearer}"
    if cookies:
        hdrs["Cookie"] = _cookie_header(cookies)
    body: bytes | None = None
    if json_body is not None:
        body = json.dumps(json_body).encode("utf-8")
        hdrs.setdefault("Content-Type", "application/json")
    elif data is not None:
        body = data.encode("utf-8") if isinstance(data, str) else data
    if body is None and method in ("POST", "PUT", "PATCH"):
        body = b""
    ans = _from_runner(method, url, hdrs, body, timeout)
    splash = ans.status != 0 and SPLASH.search(ans.text or "") is not None
    if (ans.status == 0 or splash) and env("VERIFY_SSH"):
        why = "anti-bot page" if splash else "no answer"
        srv = _from_server(method, url, hdrs, body, timeout)
        if srv.status != 0 and not SPLASH.search(srv.text or ""):
            print(f"  (runner got {why}; the server's own request answered)", flush=True)
            return srv
        return srv if srv.status != 0 else ans
    if splash:
        ans.error = "the host's anti-bot page, and no VERIFY_SSH to ask from the server"
    return ans


def dig(obj: Any, *paths: str) -> Any:
    """First value found at any dotted path ('data.token', 'token' …)."""
    for path in paths:
        cur = obj
        for part in path.split("."):
            if isinstance(cur, dict) and part in cur:
                cur = cur[part]
            else:
                cur = None
                break
        if cur is not None:
            return cur
    return None


def find_key(obj: Any, needle: str) -> list[tuple[str, Any]]:
    """Every (path, value) whose key contains needle, anywhere in a JSON document."""
    found: list[tuple[str, Any]] = []

    def walk(node: Any, path: str) -> None:
        if isinstance(node, dict):
            for k, v in node.items():
                p = f"{path}.{k}" if path else str(k)
                if needle in str(k):
                    found.append((p, v))
                walk(v, p)
        elif isinstance(node, list):
            for i, v in enumerate(node):
                walk(v, f"{path}[{i}]")

    walk(obj, "")
    return found


def truthy(value: Any) -> bool:
    return value is True or (isinstance(value, str) and value.lower() in ("ok", "true", "yes", "configured", "1"))


# ── mail ────────────────────────────────────────────────────────────────────────────────────

def message_text(msg: email.message.Message) -> str:
    """The plain text of a message (text/plain preferred; HTML reduced to text otherwise)."""
    plain, rich = [], []
    for part in msg.walk() if msg.is_multipart() else [msg]:
        if part.get_content_maintype() == "multipart" or part.get_filename():
            continue
        try:
            payload = part.get_payload(decode=True) or b""
            text = payload.decode(part.get_content_charset() or "utf-8", "replace")
        except Exception:  # noqa: BLE001
            continue
        (plain if part.get_content_type() == "text/plain" else rich).append(text)
    if plain:
        return "\n".join(plain)
    joined = "\n".join(rich)
    joined = re.sub(r"(?is)<(script|style).*?</\1>", " ", joined)
    return html.unescape(re.sub(r"<[^>]+>", " ", joined))


def header_text(msg: email.message.Message, name: str) -> str:
    value = msg.get(name, "")
    try:
        return str(email.header.make_header(email.header.decode_header(str(value))))
    except Exception:  # noqa: BLE001
        return str(value)


class Mailbox:
    """A test mailbox read over IMAP (TLS). prefix VERIFY_IMAP reads VERIFY_IMAP_HOST etc."""

    def __init__(self, prefix: str = "VERIFY_IMAP"):
        self.prefix = prefix
        self.host = env(f"{prefix}_HOST")
        self.port = int(env(f"{prefix}_PORT", "993"))
        self.user = env(f"{prefix}_USER")
        self.password = secret(f"{prefix}_PASSWORD")
        self.folder = env(f"{prefix}_FOLDER", "INBOX")

    @property
    def configured(self) -> bool:
        return bool(self.host and self.user and self.password)

    def missing(self) -> str:
        return f"{self.prefix}_HOST / _USER / _PASSWORD"

    def _recent(self, since: float, limit: int = 40) -> list[email.message.Message]:
        day = time.strftime("%d-%b-%Y", time.gmtime(since - 86400))
        box = imaplib.IMAP4_SSL(self.host, self.port, ssl_context=ssl.create_default_context(), timeout=30)
        try:
            box.login(self.user, self.password)
            box.select(self.folder, readonly=True)
            status, data = box.search(None, "SINCE", day)
            if status != "OK" or not data or not data[0]:
                return []
            ids = data[0].split()[-limit:]
            out = []
            for num in reversed(ids):
                status, parts = box.fetch(num, "(BODY.PEEK[])")
                if status != "OK":
                    continue
                for part in parts:
                    if isinstance(part, tuple):
                        out.append(email.message_from_bytes(part[1], policy=email.policy.compat32))
            return out
        finally:
            try:
                box.logout()
            except Exception:  # noqa: BLE001
                pass

    def wait_for(self, match: Callable[[email.message.Message], bool], since: float, timeout: int,
                 poll: int = 15) -> email.message.Message | None:
        """The newest message received after `since` (2 minutes of clock slack) that matches."""
        deadline = time.time() + timeout
        while True:
            try:
                for msg in self._recent(since):
                    try:
                        when = email.utils.parsedate_to_datetime(msg.get("Date", "")).timestamp()
                    except Exception:  # noqa: BLE001
                        when = since
                    if when >= since - 120 and match(msg):
                        return msg
            except (imaplib.IMAP4.error, OSError) as exc:
                print(f"  (IMAP {self.host}: {type(exc).__name__}: {exc}; retrying)", flush=True)
            if time.time() >= deadline:
                return None
            time.sleep(poll)


def send_mail(prefix: str, to: str, subject: str, body: str) -> str:
    """Send one plain-text message through {prefix}_SMTP_HOST/_PORT/_USER/_PASSWORD/_FROM.
    Port 465 is implicit TLS, anything else STARTTLS. Returns the Message-ID."""
    host, port = env(f"{prefix}_SMTP_HOST"), int(env(f"{prefix}_SMTP_PORT", "587"))
    user, password = env(f"{prefix}_SMTP_USER"), secret(f"{prefix}_SMTP_PASSWORD")
    sender = env(f"{prefix}_SMTP_FROM", user)
    msg = EmailMessage()
    msg["From"], msg["To"], msg["Subject"] = sender, to, subject
    msg["Message-ID"] = email.utils.make_msgid(domain=sender.split("@")[-1] or "verify.invalid")
    msg["Date"] = email.utils.formatdate(localtime=False)
    msg.set_content(body)
    ctx = ssl.create_default_context()
    if port == 465:
        with smtplib.SMTP_SSL(host, port, context=ctx, timeout=60) as smtp:
            smtp.login(user, password)
            smtp.send_message(msg)
    else:
        with smtplib.SMTP(host, port, timeout=60) as smtp:
            smtp.starttls(context=ctx)
            smtp.login(user, password)
            smtp.send_message(msg)
    return str(msg["Message-ID"])


# ── Console sign-in for the verification accounts (password + the e-mailed code) ─────────────

class Identity:
    """One Console verification account (VERIFY_<NAME>_EMAIL / _PASSWORD). Use identity(name):
    each account signs in to Console once per run, whichever checks need it."""

    def __init__(self, name: str):
        self.name = name
        self.email = env(f"VERIFY_{name}_EMAIL")
        self.password = secret(f"VERIFY_{name}_PASSWORD")
        self._token: str | None = None
        self._tried = False

    @property
    def configured(self) -> bool:
        return bool(self.email and self.password)

    def missing(self) -> str:
        return f"VERIFY_{self.name}_EMAIL / VERIFY_{self.name}_PASSWORD"

    def console_token(self, report: Report, mailbox: Mailbox) -> str | None:
        """Sign in to Console once per run; None (already reported) when it cannot."""
        if self._tried:
            return self._token
        self._tried = True
        label = f"Console sign-in as the {self.name.lower()} verification account"
        if not self.configured:
            report.skip(label, f"{self.missing()} not set")
            return None
        if not mailbox.configured:
            report.skip(label, f"the sign-in code mailbox ({mailbox.missing()}) is not set")
            return None
        self._token = console_login(report, label, self.email, self.password, mailbox)
        return self._token


_IDENTITIES: dict[str, Identity] = {}


def identity(name: str) -> Identity:
    if name not in _IDENTITIES:
        _IDENTITIES[name] = Identity(name)
    return _IDENTITIES[name]


def _otp_from(msg: email.message.Message) -> str | None:
    text = message_text(msg)
    near = re.search(r"code[^0-9]{0,80}(\d{6})(?!\d)", text, re.I)
    if near:
        return near.group(1)
    found = re.findall(r"(?<![\d#])(\d{6})(?!\d)", text)
    return found[0] if found else None


def console_login(report: Report, label: str, address: str, password: str, mailbox: Mailbox) -> str | None:
    api = console_api()
    for attempt in (1, 2):
        started = time.time()
        ans = request("POST", f"{api}/auth/login", json_body={"email": address, "password": password})
        pending = dig(ans.json(), "data.pending_token")
        if ans.status != 200 or not pending:
            report.fail(label, f"POST /auth/login did not ask for the e-mailed code: {ans.brief()}")
            return None
        mask(pending)
        want = address.lower()

        def is_code(msg: email.message.Message) -> bool:
            to = (header_text(msg, "To") + " " + header_text(msg, "Delivered-To")).lower()
            return "verification code" in header_text(msg, "Subject").lower() and want in to

        msg = mailbox.wait_for(is_code, started, timeout=int(env("VERIFY_OTP_TIMEOUT", "240")))
        if msg is None:
            report.fail(label, f"no Console sign-in code reached {mailbox.user} within the timeout")
            return None
        code = _otp_from(msg)
        if not code:
            report.fail(label, "the Console sign-in e-mail carried no 6-digit code")
            return None
        mask(code)
        done = request("POST", f"{api}/auth/login/complete", json_body={"pending_token": pending, "otp": code})
        token = dig(done.json(), "data.token")
        if done.status == 200 and token:
            mask(token)
            report.ok(label, f"signed in ({address})")
            return str(token)
        if attempt == 1 and done.status == 400:
            # Another run signed the same account in at the same moment and its code replaced ours.
            print("  (code refused — another sign-in may have replaced it; trying once more)", flush=True)
            time.sleep(20)
            continue
        report.fail(label, f"POST /auth/login/complete refused the code: {done.brief()}")
        return None
    return None


def console_introspect(token: str, app_code: str) -> Answer:
    return request("POST", f"{console_api()}/auth/introspect", bearer=token, json_body={"app_code": app_code})


# ── the controller portals: role sync, sign-in refusal, refresh re-check, revocation drill ─────

@dataclass
class Portal:
    """How one controller portal is reached. `session_url` signs in with the Console cookie (or
    `session_body_key` in the body); `refresh` names how a token is re-checked."""
    app_code: str
    name: str
    health_url: str
    session_url: str
    refresh_url: str
    refresh_mode: str = "body_token"          # body_token | body_refresh_token | bearer | me
    session_body_key: str | None = None        # Flow: console_token in the body instead of the cookie
    token_paths: tuple[str, ...] = ("data.token", "token", "data.access_token", "access_token")
    refresh_token_paths: tuple[str, ...] = ("data.refresh_token", "refresh_token")
    role_paths: tuple[str, ...] = ("data.user.roles", "data.roles", "roles", "user.roles", "data.user.app_roles")
    deny_in_body: bool = False                 # Finance: a refused sign-in is 200 {active: false}, no token


def _signin_refused(p: Portal, ans: Answer, statuses: tuple[int, ...]) -> bool:
    if dig(ans.json(), *p.token_paths):
        return False
    if p.deny_in_body:
        return ans.status == 200 and dig(ans.json(), "data.active") is False
    return ans.status in statuses


def _portal_sign_in(p: Portal, console_token: str | None) -> Answer:
    if p.session_body_key:
        return request("POST", p.session_url, json_body={p.session_body_key: console_token or ""})
    cookies = {CONTROLLER_COOKIE: console_token} if console_token else None
    return request("POST", p.session_url, json_body={}, cookies=cookies)


def _portal_refresh(p: Portal, token: str, refresh_token: str | None = None) -> Answer:
    if p.refresh_mode == "body_token":
        return request("POST", p.refresh_url, json_body={"token": token})
    if p.refresh_mode == "body_refresh_token":
        return request("POST", p.refresh_url, json_body={"refresh_token": refresh_token or token})
    if p.refresh_mode == "bearer":
        return request("POST", p.refresh_url, bearer=token, json_body={})
    return request("GET", p.refresh_url, bearer=token)  # "me": every request re-checks roles


def _roles_of(p: Portal, ans: Answer) -> list[str]:
    roles = dig(ans.json(), *p.role_paths)
    if isinstance(roles, list):
        return [str(r.get("slug", r) if isinstance(r, dict) else r) for r in roles]
    return []


def portal_health(report: Report, p: Portal) -> None:
    label = f"{p.name} health reports Console role sync configured"
    ans = request("GET", p.health_url)
    doc = ans.json()
    if doc is None:
        report.fail(label, f"no JSON health answer: {ans.brief()}")
        return
    hits = [(k, v) for k, v in find_key(doc, "console_role_sync")]
    if not hits:
        report.fail(label, f"the health answer has no console_role_sync flag (deploy the build that reports it): {ans.brief(120)}")
        return
    bad = [f"{k}={v}" for k, v in hits if not truthy(v)]
    report.expect(not bad, label, ", ".join(f"{k}={v}" for k, v in hits),
                  f"{', '.join(bad)} — set CONSOLE_API_URL and this portal's CONSOLE_API_TOKEN in its api/.env "
                  "(token refresh cannot re-check roles with Console without them)")


def portal_access_suite(report: Report, p: Portal, mailbox: Mailbox) -> None:
    """The checks every controller portal gets."""
    report.run(f"{p.name} health", lambda: portal_health(report, p))

    # Anonymous / forged: no Console session → 401; a garbage token is not refreshed.
    ans = _portal_sign_in(p, None)
    report.expect(_signin_refused(p, ans, (400, 401)), f"{p.name} sign-in without a Console session is refused",
                  f"HTTP {ans.status}, no token", f"expected a refusal without a token: {ans.brief()}")
    forged = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOjF9.live-verify-not-a-signature"
    ans = _portal_refresh(p, forged)
    report.expect(ans.status in (400, 401, 403, 422) and not dig(ans.json(), *p.token_paths),
                  f"{p.name} refresh with a forged token is refused", f"HTTP {ans.status}",
                  f"expected a refusal without a new token: {ans.brief()}")

    # The no-role account: Console signs it in, the portal must refuse it.
    norole = identity("NOROLE")
    tok = norole.console_token(report, mailbox)
    if tok:
        intro = console_introspect(tok, p.app_code)
        data = dig(intro.json(), "data") or {}
        report.expect(intro.status == 200 and data.get("active") is False and data.get("app_roles") in ([], None),
                      f"Console introspect: no-role account has no {p.app_code} access",
                      "active false, app_roles []", f"unexpected: {intro.brief()}")
        ans = _portal_sign_in(p, tok)
        report.expect(_signin_refused(p, ans, (403,)), f"{p.name} refuses the no-role account",
                      f"HTTP {ans.status}, no token: {' '.join(ans.text.split())[:90]}",
                      f"expected 403 without a token: {ans.brief()}")

    # The staff account: signed in with its Console roles, and a refresh re-checks them.
    staff = identity("STAFF")
    tok = staff.console_token(report, mailbox)
    if tok and expected_roles(p.app_code) == []:
        ans = _portal_sign_in(p, tok)
        report.expect(_signin_refused(p, ans, (403,)), f"{p.name} refuses the staff account (no {p.app_code} role by design)",
                      f"HTTP {ans.status}, no token", f"expected a refusal: {ans.brief()}")
        tok = None
    if tok:
        ans = _portal_sign_in(p, tok)
        token = dig(ans.json(), *p.token_paths)
        if ans.status != 200 or not token:
            report.fail(f"{p.name} signs the staff account in from its Console session", ans.brief())
        else:
            mask(str(token))
            roles = _roles_of(p, ans)
            want = expected_roles(p.app_code)
            if want is None:
                report.ok(f"{p.name} signs the staff account in",
                          f"roles {roles or '(not listed in the answer)'}; set VERIFY_STAFF_EXPECTED_ROLES to compare them")
            else:
                report.expect(sorted(set(want)) == sorted(set(roles)), f"{p.name} staff roles match Console",
                              f"roles {roles}", f"portal roles {roles} != expected {want}")
            rt = dig(ans.json(), *p.refresh_token_paths)
            if rt:
                mask(str(rt))
            again = _portal_refresh(p, str(token), str(rt) if rt else None)
            report.expect(again.status == 200, f"{p.name} refresh re-checks the staff account with Console",
                          "HTTP 200 (access still assigned)", f"refresh refused for an account that holds a role: {again.brief()}")

    if flag("LIVE_VERIFY_REVOCATION_DRILL"):
        revocation_drill(report, p, mailbox)
    else:
        report.skip(f"{p.name} refresh after revocation (drill)",
                    "run the workflow with revocation_drill=true to revoke the drill account live and prove the refresh is refused")


def revocation_drill(report: Report, p: Portal, mailbox: Mailbox) -> None:
    """A human-in-the-loop drill, never automatic: the drill account signs in to the portal while it
    holds a role; the operator revokes that role in Console (Roles & Access) while the job waits; the
    portal must then refuse the next refresh. Console grants and revokes stay superadmin-only — the
    job never holds a credential that could change roles."""
    label = f"{p.name} refresh after revocation (drill)"
    drill = identity("DRILL")
    tok = drill.console_token(report, mailbox)
    if not tok:
        report.fail(label, "the drill account could not sign in to Console")
        return
    wait = int(env("VERIFY_DRILL_WAIT", "900"))

    def access() -> bool | None:
        data = dig(console_introspect(tok, p.app_code).json(), "data") or {}
        return data.get("active") if isinstance(data, dict) else None

    if access() is not True:
        print(f"::notice title=Revocation drill::Grant {drill.email} a {p.app_code} role in Console → "
              f"Roles & Access now; waiting up to {wait // 60} minutes.", flush=True)
        deadline = time.time() + wait
        while access() is not True and time.time() < deadline:
            time.sleep(20)
        if access() is not True:
            report.fail(label, f"{drill.email} was not granted a {p.app_code} role within the wait")
            return
    ans = _portal_sign_in(p, tok)
    token = dig(ans.json(), *p.token_paths)
    rt = dig(ans.json(), *p.refresh_token_paths)
    if ans.status != 200 or not token:
        report.fail(label, f"the drill account (holding a role) could not sign in: {ans.brief()}")
        return
    mask(str(token))
    if rt:
        mask(str(rt))
    print(f"::notice title=Revocation drill::Signed in to {p.name}. NOW revoke every {p.app_code} role of "
          f"{drill.email} in Console → Roles & Access (reason: live revocation drill). Waiting up to {wait // 60} minutes.",
          flush=True)
    deadline = time.time() + wait
    while access() is not False and time.time() < deadline:
        time.sleep(20)
    if access() is not False:
        report.fail(label, f"the {p.app_code} role of {drill.email} was not revoked within the wait")
        return
    ans = _portal_refresh(p, str(token), str(rt) if rt else None)
    refused = ans.status in (401, 403) and not dig(ans.json(), *p.token_paths)
    report.expect(refused, label,
                  f"HTTP {ans.status} — the portal re-checked Console and ended the session",
                  f"the portal still refreshed a revoked account: {ans.brief()}")
    print(f"::notice title=Revocation drill::Done. Restore {drill.email}'s {p.app_code} role if the next drill needs it.", flush=True)


# ── my.aicountly: the verification customer (VERIFY_PORTAL_EMAIL / _PASSWORD) ────────────────

def portal_base() -> str:
    return env("VERIFY_PORTAL_BASE", "https://my.aicountly.com").rstrip("/")


def portal_login(report: Report, label: str) -> str | None:
    """Sign the verification customer in to my.aicountly (password sign-in, no OTP): the
    aic_auth_token, or None (already reported)."""
    address, password = env("VERIFY_PORTAL_EMAIL"), secret("VERIFY_PORTAL_PASSWORD")
    if not address or not password:
        report.skip(label, "VERIFY_PORTAL_EMAIL / VERIFY_PORTAL_PASSWORD (the verification customer) not set")
        return None
    ans = request("POST", f"{portal_base()}/api/auth/login", json_body={"LoginName": address, "Password": password})
    token = dig(ans.json(), "aic_auth_token", "data.aic_auth_token")
    if ans.status != 200 or not token:
        report.fail(label, f"my.aicountly refused the verification customer's sign-in: {ans.brief()}")
        return None
    mask(str(token))
    report.ok(label, f"signed in ({address})")
    return str(token)


def portal_ses_key(token: str) -> str | None:
    """The short-lived ses_key products (Manage) accept, from an aic_auth_token."""
    ans = request("POST", f"{portal_base()}/api/seskey", bearer=token, json_body={})
    key = dig(ans.json(), "ses_key", "data.ses_key")
    if key:
        mask(str(key))
    return str(key) if key else None


def portal_logout(token: str) -> None:
    request("POST", f"{portal_base()}/api/logout", bearer=token, json_body={})


# ── GitHub API (the workflow's own token) ─────────────────────────────────────────────────────

def github_api(path: str) -> Answer:
    token = env("GITHUB_TOKEN")
    api = env("GITHUB_API_URL", "https://api.github.com").rstrip("/")
    hdrs = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "aicountly-live-verify/1"}
    if token:
        hdrs["Authorization"] = f"Bearer {token}"
    return _from_runner("GET", f"{api}/{path.lstrip('/')}", hdrs, None, 30)


# ── SSH: one read-only command on the server ─────────────────────────────────────────────────

def on_server(command: str, timeout: int = 120) -> tuple[int, str, str]:
    prefix = env("VERIFY_SSH")
    if not prefix:
        return 255, "", "VERIFY_SSH is not set"
    try:
        proc = subprocess.run(shlex.split(prefix) + [command], capture_output=True, text=True, timeout=timeout)
        return proc.returncode, proc.stdout, proc.stderr
    except Exception as exc:  # noqa: BLE001
        return 255, "", f"{type(exc).__name__}: {exc}"


def unique_token(prefix: str = "LV") -> str:
    return f"{prefix}-{time.strftime('%Y%m%d%H%M', time.gmtime())}-{uuid.uuid4().hex[:10]}"


def main_guard(fn: Callable[[], int]) -> None:
    try:
        sys.exit(fn())
    except KeyboardInterrupt:
        sys.exit(130)


__all__: Iterable[str] = [
    "Answer", "Identity", "Mailbox", "Portal", "Report", "console_api", "console_introspect", "console_login",
    "dig", "env", "expected_roles", "identity", "find_key", "flag", "github_api", "header_text", "main_guard", "mask",
    "message_text", "on_server", "portal_access_suite", "portal_base", "portal_health", "portal_login",
    "portal_logout", "portal_ses_key", "request", "revocation_drill",
    "secret", "send_mail", "strict", "truthy", "unique_token",
]
