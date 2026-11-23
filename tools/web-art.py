"""Export full-size WebP textures and 440px inventory previews. The original production art stays in assets/art."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import re
from PIL import Image

assets = Path(__file__).resolve().parents[1] / 'public/assets'
atlas = assets / 'atlas'
preview = assets / 'portraits'
atlas.mkdir(exist_ok=True)
preview.mkdir(exist_ok=True)

def export(path):
    full_target = atlas / (path.stem + '.webp')
    thumb_target = preview / (path.stem + '.webp')
    full = path.suffix == '.png' and (not full_target.is_file() or full_target.stat().st_mtime < path.stat().st_mtime)
    single = not re.search(r'-(walk|fly|work|attack|emerge|directions|poses)$', path.stem) and path.stem not in ('terrain','inferno-terrain','mushrooms','tribe-valley','quest-keeper')
    thumb = single and (not thumb_target.is_file() or thumb_target.stat().st_mtime < path.stat().st_mtime)
    if not (full or thumb):
        return
    with Image.open(path) as sprite:
        if full:
            sprite.save(full_target,'WEBP',quality=94,method=4)
        if thumb:
            sprite.thumbnail((440,440),Image.Resampling.LANCZOS)
            sprite.save(thumb_target,'WEBP',quality=91,method=4)

if __name__ == '__main__':
    sources = [p for p in (assets/'art').iterdir() if p.suffix in ('.webp','.png')]
    with ThreadPoolExecutor(5) as workers:
        list(workers.map(export,sources))
    print(f'{len(list(atlas.glob("*.webp")))} full-size textures and {len(list(preview.glob("*.webp")))} inventory previews')
