"""Online-uyumlu dump'i tabloya gore parcalara boler. Buyuk tablolari tuple bazli boler."""
import sys, re, os

fix, outdir = sys.argv[1], sys.argv[2]
src = open(fix, 'r', encoding='utf-8').read()

m_first = re.search(r'^--\s*\n--\s*Table structure', src, re.MULTILINE)
header = src[:m_first.start()] if m_first else ''
footer_idx = src.rfind('/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;')
footer = src[footer_idx:] if footer_idx > 0 else ''
blocks = [b for b in re.split(r'(?=^--\s*\n--\s*Table structure for table)', src, flags=re.MULTILINE) if 'Table structure' in b]

# 00-schema
schema = header + "\n"
for b in blocks:
    m = re.search(r'(DROP TABLE.*?CREATE TABLE.*?\)\s*ENGINE=[^;]+;)', b, re.DOTALL)
    if m:
        schema += "\n" + m.group(1) + "\n"
schema += "\n" + footer
open(os.path.join(outdir, '00-schema.sql'), 'w', encoding='utf-8').write(schema)

header_data = header + "\n/*!40101 SET FOREIGN_KEY_CHECKS=0 */;\n/*!40101 SET UNIQUE_CHECKS=0 */;\n\n"
idx = 1
MAX = 400_000
PACK = 1000

def parse_tuples(values_str):
    out = []
    depth = 0
    start = None
    in_str = False
    i = 0
    n = len(values_str)
    while i < n:
        c = values_str[i]
        if in_str:
            if c == chr(92) and i + 1 < n:  # backslash
                i += 2
                continue
            if c == "'":
                in_str = False
        else:
            if c == "'":
                in_str = True
            elif c == '(':
                if depth == 0:
                    start = i
                depth += 1
            elif c == ')':
                depth -= 1
                if depth == 0:
                    out.append(values_str[start:i+1])
        i += 1
    return out

for b in blocks:
    mn = re.search(r'-- Dumping data for table `(\w+)`', b)
    if not mn:
        continue
    table = mn.group(1)
    im = re.search(r'(LOCK TABLES `\w+` WRITE;.*?UNLOCK TABLES;)', b, re.DOTALL)
    if not im or 'INSERT' not in im.group(1):
        continue
    body = im.group(1)
    inserts = re.findall(r'INSERT INTO `\w+`[^;]+;', body, re.DOTALL)
    all_t = []
    for ins in inserts:
        av = re.split(r'VALUES\s*', ins, maxsplit=1)[1].rstrip(';\n ')
        all_t.extend(parse_tuples(av))

    prefix = "INSERT INTO `" + table + "` VALUES "
    cur = header_data + "-- " + table + "\n\n"
    cs = len(cur)
    fi = 1
    multi = len(all_t) > PACK
    for i in range(0, len(all_t), PACK):
        stmt = prefix + ','.join(all_t[i:i+PACK]) + ';\n'
        if cs + len(stmt) > MAX and cs > len(header_data) + 20:
            suf = ("-%02d" % fi) if multi else ""
            open(os.path.join(outdir, "%02d-%s%s.sql" % (idx, table, suf)), 'w', encoding='utf-8').write(cur)
            fi += 1
            cur = header_data + "-- " + table + " (devam)\n\n"
            cs = len(cur)
        cur += stmt
        cs += len(stmt)
    if cs > len(header_data) + 20:
        suf = ("-%02d" % fi) if multi else ""
        open(os.path.join(outdir, "%02d-%s%s.sql" % (idx, table, suf)), 'w', encoding='utf-8').write(cur)
    idx += 1

print("Toplam dosya: %d" % len(os.listdir(outdir)))
