#!/usr/bin/env python3
"""Fulgur Cache translation tooling (no dependency besides Python 3).

    python3 bin/i18n.py pot       Extract strings into languages/fulgur-cache.pot
    python3 bin/i18n.py compile   Build .mo, .l10n.php and JS .json files from every languages/*.po

Edit translations in languages/fulgur-cache-<locale>.po (Poedit or any text editor), then run `compile`.
"""
import glob, hashlib, json, os, re, struct, sys, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'fulgur-cache'
JS_FILES = ['assets/admin.js']
STR = r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")"""


def unquote(s):
    body = s[1:-1]
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), body)


def extract():
    """Returns an ordered dict: msgid (or (singular, plural)) -> set of file:line references."""
    found = {}
    main = open(os.path.join(ROOT, DOMAIN + '.php'), encoding='utf-8').read()
    for field in ('Plugin Name', 'Description'):
        m = re.search(r'^\s*\*\s*' + field + r':\s*(.+)$', main, re.M)
        if m:
            found.setdefault(m.group(1).strip(), set()).add(DOMAIN + '.php')
    files = sorted(glob.glob(os.path.join(ROOT, '**', '*.php'), recursive=True)) + [os.path.join(ROOT, f) for f in JS_FILES]
    for path in files:
        rel = os.path.relpath(path, ROOT)
        src = open(path, encoding='utf-8').read()
        for m in re.finditer(r"\b(?:__|esc_html__|esc_attr__)\(\s*" + STR + r"\s*,\s*'" + DOMAIN + "'", src):
            line = src.count('\n', 0, m.start()) + 1
            found.setdefault(unquote(m.group(1)), set()).add(f'{rel}:{line}')
        for m in re.finditer(r"\b_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^;]*?'" + DOMAIN + "'", src):
            line = src.count('\n', 0, m.start()) + 1
            found.setdefault((unquote(m.group(1)), unquote(m.group(2))), set()).add(f'{rel}:{line}')
    return found


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def header(extra=''):
    return ('msgid ""\nmsgstr ""\n'
            '"Project-Id-Version: Fulgur Cache\\n"\n'
            '"MIME-Version: 1.0\\n"\n'
            '"Content-Type: text/plain; charset=UTF-8\\n"\n'
            '"Content-Transfer-Encoding: 8bit\\n"\n'
            f'"POT-Creation-Date: {time.strftime("%Y-%m-%d %H:%M+0000", time.gmtime())}\\n"\n'
            '"X-Domain: fulgur-cache\\n"\n' + extra + '\n')


def write_pot():
    out = [header('"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n')]
    for key, refs in extract().items():
        out.append('#: ' + ' '.join(sorted(refs)) + '\n')
        if isinstance(key, tuple):
            out.append(f'msgid "{po_escape(key[0])}"\nmsgid_plural "{po_escape(key[1])}"\nmsgstr[0] ""\nmsgstr[1] ""\n\n')
        else:
            out.append(f'msgid "{po_escape(key)}"\nmsgstr ""\n\n')
    path = os.path.join(LANG, DOMAIN + '.pot')
    open(path, 'w', encoding='utf-8').write(''.join(out))
    print('wrote', os.path.relpath(path, ROOT))


def parse_po(path):
    """Minimal .po parser. Returns (headers dict, list of entries)."""
    entries, cur, field = [], {}, None
    def flush():
        if cur.get('msgid') is not None:
            entries.append(dict(cur))
        cur.clear()
    for raw in open(path, encoding='utf-8'):
        line = raw.strip()
        if not line or line.startswith('#'):
            if not line and cur:
                flush()
            continue
        m = re.match(r'(msgid|msgid_plural|msgstr(?:\[\d\])?)\s+"(.*)"$', line)
        if m:
            if m.group(1) == 'msgid' and 'msgid' in cur:
                flush()
            field = m.group(1)
            cur[field] = unquote('"' + m.group(2) + '"')
        elif line.startswith('"') and field:
            cur[field] += unquote(line)
    flush()
    headers = {}
    for e in entries:
        if e['msgid'] == '':
            for h in e.get('msgstr', '').split('\n'):
                if ':' in h:
                    k, v = h.split(':', 1)
                    headers[k.strip()] = v.strip()
    return headers, [e for e in entries if e['msgid'] != '']


def translations(entries):
    """msgid key (singular or singular\\0plural) -> list of translated forms (empty forms skipped)."""
    out = {}
    for e in entries:
        if 'msgid_plural' in e:
            forms = [e.get(f'msgstr[{i}]', '') for i in range(2)]
            if all(forms):
                out[e['msgid'] + '\0' + e['msgid_plural']] = forms
        elif e.get('msgstr'):
            out[e['msgid']] = [e['msgstr']]
    return out


def write_mo(path, trans, plural_forms):
    items = {'': f'Content-Type: text/plain; charset=UTF-8\nPlural-Forms: {plural_forms}\n'}
    items.update({k: '\0'.join(v) for k, v in trans.items()})
    keys = sorted(items)
    ids = b''.join(k.encode() + b'\0' for k in keys)
    strs = b''.join(items[k].encode() + b'\0' for k in keys)
    n = len(keys)
    o_ids, o_strs = 28, 28 + n * 8
    data_start = 28 + n * 16
    id_table, str_table, off = [], [], data_start
    for k in keys:
        b = k.encode(); id_table.append((len(b), off)); off += len(b) + 1
    for k in keys:
        b = items[k].encode(); str_table.append((len(b), off)); off += len(b) + 1
    out = struct.pack('<Iiiiiii', 0x950412de, 0, n, o_ids, o_strs, 0, 0)
    out += b''.join(struct.pack('<ii', *t) for t in id_table) + b''.join(struct.pack('<ii', *t) for t in str_table)
    open(path, 'wb').write(out + ids + strs)


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'").replace('\0', "' . \"\\0\" . '") + "'"


def write_l10n_php(path, trans, locale, plural_forms):
    lines = ['<?php', 'return array(', f"\t'domain' => '{DOMAIN}',", f"\t'plural-forms' => {php_str(plural_forms)},", f"\t'language' => '{locale}',", "\t'messages' => array("]
    for k in sorted(trans):
        lines.append(f'\t\t{php_str(k)} => {php_str(chr(0).join(trans[k]))},')
    lines += ['\t),', ');', '']
    open(path, 'w', encoding='utf-8').write('\n'.join(lines))


def write_js_json(locale, trans, plural_forms, js_ids):
    for js in JS_FILES:
        messages = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': plural_forms}}
        for k, v in trans.items():
            singular = k.split('\0')[0]
            if k in js_ids:
                messages[singular] = v
        data = {'translation-revision-date': time.strftime('%Y-%m-%d %H:%M+0000', time.gmtime()), 'generator': 'fulgur-cache/bin/i18n.py',
                'domain': 'messages', 'locale_data': {'messages': messages}}
        name = f'{DOMAIN}-{locale}-{hashlib.md5(js.encode()).hexdigest()}.json'
        json.dump(data, open(os.path.join(LANG, name), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))


def compile_all():
    found = extract()
    js_ids = set()
    for key, refs in found.items():
        if any(r.split(':')[0] in JS_FILES for r in refs):
            js_ids.add('\0'.join(key) if isinstance(key, tuple) else key)
    for po in sorted(glob.glob(os.path.join(LANG, DOMAIN + '-*.po'))):
        locale = os.path.basename(po)[len(DOMAIN) + 1:-3]
        headers, entries = parse_po(po)
        plural_forms = headers.get('Plural-Forms', 'nplurals=2; plural=(n != 1);')
        trans = translations(entries)
        base = os.path.join(LANG, f'{DOMAIN}-{locale}')
        write_mo(base + '.mo', trans, plural_forms)
        write_l10n_php(base + '.l10n.php', trans, locale, plural_forms)
        write_js_json(locale, trans, plural_forms, js_ids)
        total = len(found)
        done = sum(1 for k in found if ('\0'.join(k) if isinstance(k, tuple) else k) in trans)
        print(f'{locale}: {done}/{total} strings translated')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'pot':
        write_pot()
    elif cmd == 'compile':
        compile_all()
    else:
        print(__doc__)
