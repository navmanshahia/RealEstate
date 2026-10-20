"""Exercise Twilio signature validation, reply routing, STOP suppression, and delivery status without Twilio network access."""
import base64
import hashlib
import hmac
import json
import shutil
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TMP = Path(tempfile.mkdtemp(prefix="estate-sms-"))
APP = TMP / "estate"
PORT = 18768
ACCOUNT = "AC" + "0" * 32
TOKEN = "Test-Twilio-Signing-Secret"
ORIGIN = f"http://127.0.0.1:{PORT}"
FROM = "+16045550999"
TO = "+16045550123"
DB = TMP / "sms.db"

def webhook(action, payload, *, invalid=False):
    url = f"{ORIGIN}/api.php?action={action}"
    source = url + "".join(k + str(payload[k]) for k in sorted(payload))
    signature = base64.b64encode(hmac.new(TOKEN.encode(), source.encode(), hashlib.sha1).digest()).decode()
    if invalid:
        signature = "invalid"
    req = urllib.request.Request(
        url,
        data=urllib.parse.urlencode(payload).encode(),
        headers={"X-Twilio-Signature": signature, "Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(req) as response:
            return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()

try:
    shutil.copytree(ROOT, APP, ignore=shutil.ignore_patterns(".git", "config.php", "node_modules"))
    (APP / "app/config.php").write_text(
        "<?php return ["
        + "'dsn'=>'sqlite:" + str(DB) + "',"
        + "'db_user'=>null,'db_pass'=>null,'app_url'=>'" + ORIGIN + "',"
        + "'install_key'=>'" + "x" * 48 + "',"
        + "'session_secure'=>false,'mail_enabled'=>false,"
        + "'twilio_sid'=>'" + ACCOUNT + "','twilio_token'=>'" + TOKEN + "',"
        + "'twilio_from'=>'" + FROM + "'];"
    )
    conn = sqlite3.connect(DB)
    conn.executescript("""
        CREATE TABLE records (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32),kind VARCHAR(30),data TEXT,created VARCHAR(30),updated VARCHAR(30));
        CREATE TABLE events (id VARCHAR(150) PRIMARY KEY,created VARCHAR(30));
        CREATE TABLE audit (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32),user_id VARCHAR(32),action VARCHAR(100),record_id VARCHAR(100),created VARCHAR(30));
    """)
    contacts = [
        ("a" * 32, "1" * 32, "Alice"),
        ("b" * 32, "2" * 32, "Bob"),
    ]
    for contact_id, workspace, name in contacts:
        conn.execute("INSERT INTO records VALUES (?,?,?,?,?,?)", (
            contact_id, workspace, "contact",
            json.dumps({"name": name, "email": name.lower() + "@example.com", "phone": TO, "sms_consent": True, "sms_consent_source": "Documented paper consent"}),
            "2026-10-20 00:00:00", "2026-10-20 00:00:00",
        ))
    conn.executescript("""
        CREATE TABLE sms_messages (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32),contact_id VARCHAR(32),direction VARCHAR(12),sender VARCHAR(20),recipient VARCHAR(20),body TEXT,provider_sid VARCHAR(64),status VARCHAR(30),created VARCHAR(30));
        CREATE TABLE sms_suppressions (sender VARCHAR(20),recipient VARCHAR(20),created VARCHAR(30),PRIMARY KEY(sender,recipient));
    """)
    first_sid = "SM" + "1" * 32
    conn.execute("INSERT INTO sms_messages VALUES (?,?,?,?,?,?,?,?,?,?)", (
        "c" * 32, "1" * 32, "a" * 32, "outbound", FROM, TO,
        "Hello from Alice", first_sid, "queued", "2026-10-20 12:00:00"
    ))
    conn.commit()
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", str(APP)],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1)
    base = {"AccountSid": ACCOUNT, "To": FROM, "From": TO, "Body": "Can we book a viewing?", "MessageSid": "SM" + "2" * 32}
    assert webhook("twilio_inbound", base, invalid=True)[0] == 403
    assert webhook("twilio_inbound", base)[0] == 200
    assert conn.execute("SELECT COUNT(*) FROM sms_messages WHERE direction='inbound' AND workspace=?", ("1" * 32,)).fetchone()[0] == 1
    assert conn.execute("SELECT COUNT(*) FROM records WHERE kind='activity' AND workspace=?", ("2" * 32,)).fetchone()[0] == 0
    assert webhook("twilio_inbound", base)[0] == 200
    assert conn.execute("SELECT COUNT(*) FROM sms_messages WHERE direction='inbound'").fetchone()[0] == 1

    update = {"AccountSid": ACCOUNT, "MessageSid": first_sid, "MessageStatus": "delivered", "From": FROM, "To": TO}
    assert webhook("twilio_status", update)[0] == 200
    assert conn.execute("SELECT status FROM sms_messages WHERE provider_sid=?", (first_sid,)).fetchone()[0] == "delivered"

    conn.execute("INSERT INTO sms_messages VALUES (?,?,?,?,?,?,?,?,?,?)", (
        "d" * 32, "2" * 32, "b" * 32, "outbound", FROM, TO,
        "Hello from Bob", "SM" + "3" * 32, "sent", "2026-10-20 12:10:00"
    ))
    conn.commit()
    ambiguous = dict(base, MessageSid="SM" + "4" * 32, Body="Which agent is this?")
    assert webhook("twilio_inbound", ambiguous)[0] == 200
    assert conn.execute("SELECT COUNT(*) FROM sms_messages WHERE direction='inbound'").fetchone()[0] == 1

    stop = dict(base, MessageSid="SM" + "5" * 32, Body="STOP")
    assert webhook("twilio_inbound", stop)[0] == 200
    assert conn.execute("SELECT COUNT(*) FROM sms_suppressions WHERE recipient=?", (TO,)).fetchone()[0] == 1
    for (data,) in conn.execute("SELECT data FROM records WHERE kind='contact'"):
        parsed = json.loads(data)
        assert parsed["sms_opt_out"] is True and parsed["sms_consent"] is False
    print("PASS: Twilio signature guard, idempotent inbound replies, tenant-safe routing, status callbacks and STOP opt-out")
finally:
    if "server" in locals():
        server.terminate()
        server.wait()
    if "conn" in locals():
        conn.close()
    shutil.rmtree(TMP)
