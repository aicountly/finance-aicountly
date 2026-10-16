#!/usr/bin/env python3
"""live-feature-checks.py — Finance's Console staff roles, proven against the live Finance.

    python3 scripts/ci/live-feature-checks.py production

Run by verify-live.yml (workflow_dispatch) and by deploy-production.yml after its post-deploy checks. Signs the
verification accounts in (a Finance session each); changes nothing else.

  * GET /api/health reports console_role_sync configured (CONSOLE_API_URL + this portal's own
    CONSOLE_API_TOKEN: without them a token refresh cannot re-check roles with Console).
  * Sign-in without a Console session is refused; a forged token is not refreshed.
  * The no-role verification account (Console signs it in) is refused by Finance.
  * The staff verification account signs in with its Console roles (VERIFY_STAFF_EXPECTED_ROLES
    compares them) and a refresh — which re-checks Console — succeeds.
  * With LIVE_VERIFY_REVOCATION_DRILL=1 (input revocation_drill): the drill account signs in while
    it holds a finance role, an operator revokes it in Console while the job waits, and the next
    refresh must be refused (live_verify.revocation_drill).

Secrets: the shared VERIFY_IMAP_*, VERIFY_STAFF_*, VERIFY_NOROLE_*, VERIFY_DRILL_* (live_verify.py).
VERIFY_BASE_URL overrides https://finance.aicountly.org (tests).
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import live_verify as lv  # noqa: E402

BASE = lv.env("VERIFY_BASE_URL", "https://finance.aicountly.org").rstrip("/")
API = f"{BASE}/api"

PORTAL = lv.Portal(
    app_code="finance", name="Finance",
    health_url=f"{API}/health",
    session_url=f"{API}/v1/auth/console-session",
    refresh_url=f"{API}/v1/auth/me", refresh_mode="me",
    deny_in_body=True,  # a refused sign-in is 200 {active: false}; roles are re-checked on every request (me)
)


def main() -> int:
    target = sys.argv[1] if len(sys.argv) > 1 else "production"
    if target != "production":
        print("usage: live-feature-checks.py production    (Finance has production only)", file=sys.stderr)
        return 2
    r = lv.Report("Finance live feature checks")
    lv.portal_access_suite(r, PORTAL, lv.Mailbox())
    return r.finish()


if __name__ == "__main__":
    lv.main_guard(main)
