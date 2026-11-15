#!/usr/bin/env python3
"""Generate the images used when the website is shared: Open Graph cards (one
per site language, og-image-{locale}.png), GitHub social preview and icons.

    python3 tools/make_assets.py

Needs Google Chrome or Chromium (headless) to render, and Pillow to check the
sizes. The output goes to pages/templates/assets/, which the site builder copies
to assets/. The rates shown come from the live API, so the cards show real ECB
numbers; they are an example and do not need to be redrawn when rates change.
"""

from __future__ import annotations

import html
import json
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "pages" / "templates" / "assets"
API = "https://codecruz.github.io/currency-flow-api/v1/"
URL = "codecruz.github.io/currency-flow-api"
SHOWN = ("USD", "GBP", "JPY", "CHF", "MXN")

TEXTS = {
    "en": {
        "title": "Euro exchange rates from the ECB",
        "subtitle": "Every day since 1999. Static JSON API, PHP library and self-hosted HTTP API.",
        "stats": ("active currencies", "first date", "base"),
    },
    "es": {
        "title": "Tipos de cambio del euro del BCE",
        "subtitle": "Todos los días desde 1999. API JSON estática, librería PHP y API HTTP propia.",
        "stats": ("divisas activas", "primer día", "base"),
    },
    "de": {
        "title": "Euro-Wechselkurse der EZB",
        "subtitle": "Jeder Tag seit 1999. Statische JSON-API, PHP-Bibliothek und eigene HTTP-API.",
        "stats": ("aktive Währungen", "erster Tag", "Basis"),
    },
    "fr": {
        "title": "Taux de change de l’euro de la BCE",
        "subtitle": "Chaque jour depuis 1999. API JSON statique, bibliothèque PHP et API HTTP auto-hébergée.",
        "stats": ("devises actives", "premier jour", "base"),
    },
    "it": {
        "title": "Tassi di cambio dell’euro della BCE",
        "subtitle": "Ogni giorno dal 1999. API JSON statica, libreria PHP e API HTTP sul tuo server.",
        "stats": ("valute attive", "primo giorno", "base"),
    },
    "pt": {
        "title": "Taxas de câmbio do euro do BCE",
        "subtitle": "Todos os dias desde 1999. API JSON estática, biblioteca PHP e API HTTP própria.",
        "stats": ("moedas ativas", "primeiro dia", "base"),
    },
}

# The same palette as pages/templates/index.html (light theme).
BG = "#f8f9fb"
SURFACE = "#ffffff"
TEXT = "#1b1d21"
MUTED = "#5d636b"
BORDER = "#dfe3e8"
ACCENT = "#1f5fbf"
CODE_BG = "#eff2f6"
MONO = '"DejaVu Sans Mono", "Ubuntu Mono", ui-monospace, monospace'
SANS = '"Ubuntu Sans", "Noto Sans", system-ui, sans-serif'

# The icon: two opposite arrows (an exchange) on the accent colour.
ICON_SVG = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="{ACCENT}"/>
  <g fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M14 24h34M40 15l9 9-9 9"/>
    <path d="M50 40H16M24 31l-9 9 9 9" stroke-opacity=".8"/>
  </g>
</svg>
"""


def fetch(path: str) -> dict:
    with urllib.request.urlopen(API + path, timeout=20) as res:
        return json.load(res)


def card_page(width: int, height: int, lang: str, latest: dict, active: int) -> str:
    t = TEXTS[lang]
    pad = 72
    lines = [
        f'<span class="p">$</span> curl …/v1/latest.json',
        "",
        f'<span class="k">"base"</span>: <span class="s">"EUR"</span>',
        f'<span class="k">"date"</span>: <span class="s">"{latest["date"]}"</span>',
        '<span class="k">"rates"</span>: {',
    ]
    for code in SHOWN:
        lines.append(f'  <span class="k">"{code}"</span>: <span class="s">"{latest["rates"][code]}"</span>')
    lines.append('  <span class="m">…</span>')
    lines.append("}")
    stats = "".join(
        f"<div><b>{value}</b><span>{label}</span></div>"
        for value, label in zip((str(active), "1999", "EUR"), t["stats"])
    )
    return f"""<!doctype html><html lang="{lang}"><head><meta charset="utf-8"><style>
  * {{ box-sizing: border-box; margin: 0; }}
  html, body {{ width: {width}px; height: {height}px; overflow: hidden; }}
  body {{ background: {BG}; color: {TEXT}; font-family: {SANS}; position: relative; }}
  body::before {{ content: ""; position: absolute; inset: 0 0 auto 0; height: 10px; background: {ACCENT}; }}
  .main {{ position: absolute; inset: {pad}px {pad}px {pad + 8}px; display: flex; align-items: center; gap: 52px; }}
  .text {{ width: 52%; flex: none; }}
  .brand {{ display: flex; align-items: center; gap: 14px; font: 600 26px {MONO}; margin-bottom: 28px; }}
  .brand svg {{ width: 44px; height: 44px; }}
  h1 {{ font-size: {54 if width < 1250 else 58}px; line-height: 1.08; letter-spacing: -0.02em; font-weight: 700; }}
  p {{ font-size: 23px; line-height: 1.45; color: {MUTED}; margin-top: 20px; }}
  .stats {{ display: flex; gap: 34px; margin-top: 30px; }}
  .stats b {{ display: block; font-size: 34px; font-variant-numeric: tabular-nums; color: {ACCENT}; }}
  .stats span {{ font-size: 18px; color: {MUTED}; }}
  .card {{ flex: 1; min-width: 0; background: {SURFACE}; border: 1px solid {BORDER}; border-radius: 18px;
          overflow: hidden; box-shadow: 0 18px 40px -24px rgba(27, 29, 33, .35); }}
  .head {{ display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid {BORDER}; }}
  .head i {{ width: 12px; height: 12px; border-radius: 50%; background: {BORDER}; }}
  pre {{ font: 17px/1.75 {MONO}; padding: 22px 24px; background: {CODE_BG}; white-space: pre; overflow: hidden; }}
  .p, .m {{ color: {MUTED}; }} .k {{ color: {TEXT}; }} .s {{ color: {ACCENT}; }}
  .url {{ position: absolute; left: {pad}px; bottom: {pad // 2 - 6}px; font: 20px {MONO}; color: {MUTED}; }}
  .url b {{ color: {ACCENT}; font-weight: 600; }}
</style></head><body>
  <div class="main">
    <div class="text">
      <div class="brand">{ICON_SVG}currency-flow</div>
      <h1>{html.escape(t["title"])}</h1>
      <p>{html.escape(t["subtitle"])}</p>
      <div class="stats">{stats}</div>
    </div>
    <div class="card"><div class="head"><i></i><i></i><i></i></div><pre>{chr(10).join(lines)}</pre></div>
  </div>
  <div class="url"><b>→</b> {URL}</div>
</body></html>"""


def icon_page(size: int) -> str:
    return f"""<!doctype html><html><head><meta charset="utf-8"><style>
  * {{ margin: 0; }} html, body {{ width: {size}px; height: {size}px; overflow: hidden; background: transparent; }}
  svg {{ display: block; width: {size}px; height: {size}px; }}
</style></head><body>{ICON_SVG}</body></html>"""


def find_chrome() -> str:
    for name in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser", "chrome"):
        path = shutil.which(name)
        if path:
            return path
    sys.exit("Google Chrome or Chromium is needed to render the images.")


def render(chrome: str, page: str, out: Path, width: int, height: int, tmp: Path) -> None:
    src = tmp / (out.stem + ".html")
    src.write_text(page, encoding="utf-8")
    subprocess.run(
        [
            chrome,
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            "--default-background-color=00000000",
            f"--window-size={width},{height}",
            f"--screenshot={out}",
            src.as_uri(),
        ],
        check=True,
        capture_output=True,
    )


def main() -> None:
    from PIL import Image

    latest = fetch("latest.json")
    active = sum(1 for c in fetch("currencies.json")["currencies"] if c["active"])
    chrome = find_chrome()
    OUT.mkdir(parents=True, exist_ok=True)
    jobs = [(f"og-image-{lang}.png", card_page(1200, 630, lang, latest, active), 1200, 630) for lang in TEXTS]
    jobs += [
        ("social-preview.png", card_page(1280, 640, "en", latest, active), 1280, 640),
        ("apple-touch-icon.png", icon_page(180), 180, 180),
        ("icon-192.png", icon_page(192), 192, 192),
        ("icon-512.png", icon_page(512), 512, 512),
    ]
    with tempfile.TemporaryDirectory() as tmp:
        for name, page, width, height in jobs:
            out = OUT / name
            render(chrome, page, out, width, height, Path(tmp))
            with Image.open(out) as img:
                if img.size != (width, height):
                    sys.exit("%s is %dx%d, expected %dx%d" % (name, *img.size, width, height))
                if name.startswith(("og-", "social-")):
                    img.convert("RGB").save(out, optimize=True)
            print("%-22s %dx%d" % (name, width, height))
    (OUT / "icon.svg").write_text(ICON_SVG, encoding="utf-8")
    print("icon.svg")


if __name__ == "__main__":
    main()
