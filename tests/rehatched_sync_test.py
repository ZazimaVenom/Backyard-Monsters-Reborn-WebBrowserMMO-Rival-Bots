#!/usr/bin/env python3
"""Check the complete browser/client source distribution using Python 3 only."""
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
CLIENT = ROOT / "Backyard-Monsters-Client-Source"
WEB_ASSETS = ROOT / "public/assets"
CLIENT_ASSETS = CLIENT / "Assets"


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def inventory(directory):
    return {p.relative_to(directory).as_posix(): p for p in directory.rglob("*") if p.is_file()}


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def main():
    manifest = json.loads((CLIENT / "assets-manifest.json").read_text(encoding="utf-8-sig"))
    catalog = (ROOT / "app/Catalog.php").read_text(encoding="utf-8")
    version = re.search(r"'version'\s*=>\s*'([^']+)'", catalog).group(1)
    require(manifest["gameVersion"] == version, "Client manifest and server game versions differ")
    require(version != "0.13.0", "Rebranded assets must invalidate the earlier game cache")
    web = inventory(WEB_ASSETS)
    client = inventory(CLIENT_ASSETS)
    records = {record["path"]: record for record in manifest["files"]}
    require(len(records) == len(manifest["files"]), "Manifest has duplicate asset paths")
    require(web.keys() == client.keys() == records.keys(), "Web, client and manifest asset paths differ")
    for relative, path in web.items():
        digest = sha256(path)
        require(digest == sha256(client[relative]) == records[relative]["sha256"],
                f"Asset content mismatch: {relative}")
        require(path.stat().st_size == records[relative]["size"], f"Manifest size mismatch: {relative}")
    print(f"PASS: all {len(web):,} browser and client assets match their SHA-256 manifest")

    old_brand = re.compile(rb"reborn|r\s+e\s+b\s+o\s+r\s+n", re.IGNORECASE)
    visible = list((ROOT / "public").rglob("*")) + list((CLIENT / "Client.Windows").glob("*"))
    visible += list((ROOT / "app/languages").glob("*.json"))
    for path in visible:
        if path.is_file():
            require(not old_brand.search(path.read_bytes()), f"Old branding remains: {path.relative_to(ROOT)}")
    for path in [ROOT / "public/index.php", ROOT / "public/setup.php", CLIENT / "Client.Windows/MainWindow.xaml"]:
        require("Backyard Monsters Rehatched" in path.read_text(encoding="utf-8"),
                f"Full game title is missing: {path.relative_to(ROOT)}")
    print("PASS: browser pages, game assets, translations and client shell use Rehatched branding")

    queries = 0
    for path in [ROOT / "public/index.php", ROOT / "public/setup.php", *WEB_ASSETS.rglob("*.js"), *WEB_ASSETS.rglob("*.css")]:
        for found in re.findall(r"[?&]v=([0-9]+\.[0-9]+\.[0-9]+)", path.read_text(encoding="utf-8")):
            require(found == version, f"Stale cache version in {path.relative_to(ROOT)}: {found}")
            queries += 1
    require(queries > 0, "No browser cache version markers were found")
    print(f"PASS: all {queries} browser cache version markers match game release {version}")


if __name__ == "__main__":
    main()
