# -*- coding: utf-8 -*-
"""Отрисовка инфографики: HTML -> PNG через браузер."""
import pathlib, sys
from playwright.sync_api import sync_playwright

here = pathlib.Path(__file__).parent
pages = ["1-flow", "2-evening", "3-map"]

with sync_playwright() as pw:
    b = pw.chromium.launch(executable_path="/opt/pw-browsers/chromium-1194/chrome-linux/chrome")
    p = b.new_page(viewport={"width": 1260, "height": 900}, device_scale_factor=1.6)
    for name in pages:
        p.goto((here / (name + ".html")).as_uri())
        p.wait_for_timeout(300)
        el = p.query_selector(".sheet")
        out = here / (name + ".jpg")
        el.screenshot(path=str(out), type="jpeg", quality=88)
        box = el.bounding_box()
        print("%-10s %4dx%-4d  %6d КБ" % (name, box["width"], box["height"],
                                          out.stat().st_size // 1024))
    b.close()
