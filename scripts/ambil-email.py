#!/usr/bin/env python3
"""Ambil email masuk support@ lewat IMAP untuk tiket:ambil-email (PHP tanpa ext-imap/mailparse).

  ambil-email.py ambil <berkas-kredensial> <folder-lampiran>   → JSON per baris untuk tiap email yang belum ditandai
  ambil-email.py tandai <berkas-kredensial> <uid> [uid ...]    → beri keyword VcTiket (sudah diproses)

Email dibaca dengan BODY.PEEK (status belum dibaca di webmail tidak berubah). Kredensial: baris host=, user=, pass=.
"""
import email
import email.policy
import imaplib
import json
import os
import re
import sys
from email.utils import parseaddr

FLAG = "VcTiket"


def masuk(berkas):
    k = dict(b.split("=", 1) for b in open(berkas).read().splitlines() if "=" in b)
    m = imaplib.IMAP4_SSL(k["host"], 993, timeout=60)
    m.login(k["user"], k["pass"])
    m.select("INBOX")
    return m, k["user"].lower()


def teks(msg):
    plain = msg.get_body(preferencelist=("plain",))
    if plain is not None:
        return plain.get_content()
    html = msg.get_body(preferencelist=("html",))
    if html is None:
        return ""
    s = re.sub(r"(?is)<(script|style).*?</\1>", "", html.get_content())
    s = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</tr>", "\n", s)
    s = re.sub(r"<[^>]+>", "", s)
    import html as h
    return re.sub(r"\n{3,}", "\n\n", h.unescape(s))


def ambil(berkas, folder):
    m, sendiri = masuk(berkas)
    _, data = m.uid("search", None, "UNKEYWORD", FLAG)
    for uid in data[0].split():
        _, isi = m.uid("fetch", uid, "(BODY.PEEK[])")
        raw = next((x[1] for x in isi if isinstance(x, tuple)), None)
        if raw is None:
            continue
        msg = email.message_from_bytes(raw, policy=email.policy.default)
        nama, alamat = parseaddr(str(msg.get("From", "")))
        alamat = alamat.lower()
        otomatis = (
            str(msg.get("Auto-Submitted", "no")).lower() not in ("", "no")
            or str(msg.get("Precedence", "")).lower() in ("bulk", "junk", "list")
            or bool(re.match(r"(mailer-daemon|postmaster|no-?reply)@", alamat))
            or alamat == sendiri
        )
        lampiran = []
        if not otomatis:
            for i, part in enumerate(msg.iter_attachments()):
                data_b = part.get_payload(decode=True) or b""
                nama_b = os.path.basename(part.get_filename() or f"lampiran-{i}")[:200]
                path = os.path.join(folder, f"{uid.decode()}-{i}")
                with open(path, "wb") as f:
                    f.write(data_b)
                lampiran.append({"path": path, "nama": nama_b, "mime": part.get_content_type(), "ukuran": len(data_b)})
        rujukan = " ".join(str(msg.get(h, "")) for h in ("In-Reply-To", "References"))
        print(json.dumps({
            "uid": uid.decode(),
            "message_id": str(msg.get("Message-ID", "")).strip() or None,
            "rujukan": re.findall(r"<[^>]+>", rujukan),
            "dari": alamat,
            "nama": nama,
            "subjek": str(msg.get("Subject", "")).strip(),
            "isi": "" if otomatis else teks(msg),
            "otomatis": otomatis,
            "lampiran": lampiran,
        }, ensure_ascii=False))
    m.logout()


def tandai(berkas, uids):
    m, _ = masuk(berkas)
    for uid in uids:
        m.uid("store", uid, "+FLAGS", f"({FLAG})")
    m.logout()


if __name__ == "__main__":
    perintah, berkas = sys.argv[1], sys.argv[2]
    if perintah == "ambil":
        ambil(berkas, sys.argv[3])
    elif perintah == "tandai":
        tandai(berkas, sys.argv[3:])
    else:
        sys.exit("perintah: ambil | tandai")
