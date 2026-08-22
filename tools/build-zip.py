#!/usr/bin/env python3
"""Cross-platform packager for the WordPress.org zip (core) and the companion.

Usage: python tools/build-zip.py [--no-build]
Excludes dev-only paths (tests, vendor, src-js, node_modules, composer/phpunit files).
"""
import os
import re
import subprocess
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, 'dist')
EXCLUDE_DIRS = {'tests', 'vendor', 'src-js', 'node_modules', '.git', '.phpunit.cache'}
EXCLUDE_FILES = {'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpstan.neon.dist', '.phpcs.xml.dist', '.phpcs.xml', '.DS_Store', 'Thumbs.db'}


def version(main_file):
    with open(main_file, encoding='utf-8') as fh:
        m = re.search(r'^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)', fh.read(), re.M)
    return m.group(1) if m else '0.0.0'


def pack(folder, out):
    base = os.path.join(ROOT, folder)
    count = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for dirpath, dirnames, filenames in os.walk(base):
            rel = os.path.relpath(dirpath, base)
            parts = [] if rel == '.' else rel.split(os.sep)
            if parts and parts[0] in EXCLUDE_DIRS:
                dirnames[:] = []
                continue
            dirnames[:] = [d for d in dirnames if not (rel == '.' and d in EXCLUDE_DIRS)]
            for name in filenames:
                if name in EXCLUDE_FILES or name.endswith('.map'):
                    continue
                full = os.path.join(dirpath, name)
                arc = os.path.join(folder, os.path.relpath(full, base)).replace(os.sep, '/')
                zf.write(full, arc)
                count += 1
    return count


def main():
    if '--no-build' not in sys.argv:
        subprocess.run(['npm', 'run', 'build'], cwd=ROOT, check=True, shell=(os.name == 'nt'), stdout=subprocess.DEVNULL)
    os.makedirs(DIST, exist_ok=True)
    core_v = version(os.path.join(ROOT, 'agentyllo', 'agentyllo.php'))
    comp_v = version(os.path.join(ROOT, 'agentyllo-local-ai', 'agentyllo-local-ai.php'))
    core = os.path.join(DIST, f'agentyllo-{core_v}.zip')
    comp = os.path.join(DIST, f'agentyllo-local-ai-{comp_v}.zip')
    n1 = pack('agentyllo', core)
    n2 = pack('agentyllo-local-ai', comp)
    for path, n in ((core, n1), (comp, n2)):
        print(f'{os.path.basename(path)}: {n} files, {os.path.getsize(path) // 1024} KB')


if __name__ == '__main__':
    main()
