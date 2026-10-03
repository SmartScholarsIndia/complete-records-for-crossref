#!/usr/bin/env python3
"""tools/crossref_compare.py — before a deposit, what would change at Crossref (3 Oct 2026).

OJS's own file and the plugin's completed file for the two example articles, compared with Crossref's record: article A's
record as Crossref holds it (fixtures/crossref-work-a.json, saved from api.crossref.org), and for article B a record
written HERE for the test (marked as such) with one deliberate difference. Nothing is fetched: --record supplies them.
"""
import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(HERE, '..', 'tools', 'crossref_compare.py')
DOI_A = '10.5555/ejt.2025.v10.i12.pp35-41'
DOI_B = '10.5555/ejt.2020.v5.i3.pp15-18'
n, failed = 0, []


def ck(name, ok, detail=''):
    global n
    n += 1
    print(('  OK   ' if ok else ' FAIL  ') + name + ('' if ok else '  <- ' + str(detail)[:1500]))
    if not ok:
        failed.append(name)


def run(*args):
    p = subprocess.run([sys.executable, TOOL] + list(args), stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
    return p.returncode, p.stdout.decode('utf-8', 'replace')


def block(out, doi):
    """The lines printed for one DOI."""
    s = out.split('\n   ' + doi + '\n', 1)
    return s[1].split('\n\n', 1)[0] if len(s) == 2 else ''


t = tempfile.mkdtemp(prefix='crc-')
p = subprocess.run(['php', os.path.join(HERE, 'emit_completed.php'), t], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
ck('the sample files were written (OJS\'s own and the plugin\'s)', p.returncode == 0, p.stdout.decode())
rec_a = os.path.join(HERE, 'fixtures', 'crossref-work-a.json')
# TEST RECORD, not Crossref's: article B as a registered record whose online date differs from OJS's by two days
rec_b = os.path.join(t, 'test-record-b.json')
json.dump({'message': {
    'DOI': DOI_B, 'title': ['Tables &amp; Figures in an Example Article'], 'volume': '5', 'issue': '3',
    'page': '15-18', 'published-online': {'date-parts': [[2020, 4, 25]]}, 'ISSN': ['1234-5679'],
    'resource': {'primary': {'URL': 'https://journal.example.org/index.php/pub/article/view/326'}},
    'author': [{'given': 'Leo K.', 'family': 'Tester'}, {'given': 'Ana R.', 'family': 'Example'}, {'given': 'Ravi T', 'family': 'Example'}],
    'deposited': {'date-parts': [[2026, 9, 20]]}}}, open(rec_b, 'w'))
recs = ['--record', DOI_A + '=' + rec_a, '--record', DOI_B + '=' + rec_b]

rc, out = run(*(recs + [os.path.join(t, 'in-ojs34-5.3.1.xml')]))
a = block(out, DOI_A)
ck('OJS\'s own file, article A: title, date, volume, issue, landing page, licence, ISSN, abstract, author and ORCID iD all the same',
   all(x in a for x in ('title', 'online date', 'volume', 'issue', 'landing page', 'licence', 'ISSN', 'abstract', 'authors (1)', 'ORCID iDs (1)')) and 'same' in a, a)
ck('…CHANGES: the pages — Crossref has the first page only, OJS the range', 'CHANGES  pages: Crossref 35 → file 35-41' in a, a)
ck('…LACKS: the 11 references Crossref holds and OJS does not (the case this comparison exists for)', 'LACKS' in a and 'references: 0 — Crossref has 11' in a, a)
b = block(out, DOI_B)
ck('article B (test record): CHANGES the online date, both values shown; the title matches across the &amp; entity', 'online date: Crossref 2020-04-25 → file 2020-04-27' in b and 'title' in b.split('same', 1)[-1], b)
ck('a file with changes: exit 1 (a person looks first), and the summary counts them', rc == 1 and '2 record(s): 0 only add' in out and '2 need a person to look first' in out, out[-300:])

rc, out = run(*(recs + [os.path.join(t, 'done-crossmark-ojs34-5.3.1.xml')]))
a = block(out, DOI_A)
ck('the completed file, article A: ADDS the affiliation and Crossmark; the 11 references now match', 'ADDS' in a and '1 affiliations (Crossref has 0)' in a and 'Crossmark' in a.split('ADDS', 1)[1].split('\n')[0] and 'references (11)' in a, a)
b = block(out, DOI_B)
ck('…article B: ADDS 2 affiliations (authors 1 and 3; author 2 has none in OJS) with 2 ROR IDs', '2 affiliations (Crossref has 0)' in b and '2 ROR IDs (Crossref has 0)' in b, b)

rc, out = run('--record', DOI_A + '=404', '--record', DOI_B + '=' + rec_b, os.path.join(t, 'in-ojs34-5.3.1.xml'))
ck('a DOI Crossref does not know: "would register it new", not compared', 'would register it new' in block(out, DOI_A) and '1 new' in out, out)
rc, out = run('--record', DOI_A + '=' + os.path.join(t, 'nowhere.json'), '--record', DOI_B + '=' + rec_b, os.path.join(t, 'in-ojs34-5.3.1.xml'))
ck('a record that cannot be read: said so, NOT compared, exit 2', rc == 2 and 'NOT compared' in out and '1 not read' in out, out)
bad = os.path.join(t, 'not.xml'); open(bad, 'w').write('<record xmlns="urn:x"/>')
rc, out = run(bad)
ck('not a Crossref deposit file: exit 2, said so', rc == 2 and 'not a Crossref deposit file' in out, out)
rc, out = run(*(recs + [os.path.join(t, 'done-plain-ojs35-5.4.0.xml')]))
ck('the 5.4.0 file (OJS 3.5) is read the same way', DOI_A in out and DOI_B in out and 'references (11)' in block(out, DOI_A), out[:800])
rc, out = run()
ck('no file named: the usage, exit 2', rc == 2 and 'crossref_compare.py FILE.xml' in out)

print('\n%d checks, %d failed%s' % (n, len(failed), (' — ' + '; '.join(failed)) if failed else ' — ALL OK'))
sys.exit(1 if failed else 0)
