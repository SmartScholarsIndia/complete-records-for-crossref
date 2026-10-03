#!/usr/bin/env python3
"""tools/crossref_validate.py — the schema check run before any deposit (3 Oct 2026).

    python3 ojs-plugin/tests/test_validate.py
    CR_SCHEMA_CACHE=/path python3 ojs-plugin/tests/test_validate.py    (also: the plugin's files against Crossref's schemas)

Part A always runs: small stand-in schemas served from a local web server prove the mechanics — fetching and copying
the whole set, answering a cross-host import from the copy, the assertion path, an incomplete set refused (never
reported valid), offline runs, entity safety, the exit codes.

Part B runs when CR_SCHEMA_CACHE names a copy of crossref.org's and w3.org's schema folders laid out as the checker keeps
its cache (tests/schema_copies.sh builds one): OJS's own files and the plugin's completed files for OJS 3.4 (5.3.1) and
3.5 (5.4.0) must be VALID with nothing skipped, and broken copies of them must be INVALID — so a pass means something.
Without it Part B says SKIPPED; a skip is never counted as a pass.
"""
import functools
import http.server
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(HERE, '..', 'tools', 'crossref_validate.py')
n = 0
failed = []


def ck(name, ok, detail=''):
    global n
    n += 1
    print(('  OK   ' if ok else ' FAIL  ') + name + ('' if ok else '  <- ' + str(detail)[:900]))
    if not ok:
        failed.append(name)


def run(*args):
    p = subprocess.run([sys.executable, TOOL] + list(args), stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300)
    return p.returncode, p.stdout.decode('utf-8', 'replace')


def schema(version, body, extra=''):
    return ('<?xml version="1.0"?>\n<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            'xmlns="http://www.crossref.org/schema/%s" xmlns:o="urn:x-other" targetNamespace="http://www.crossref.org/schema/%s" '
            'elementFormDefault="qualified">%s%s</xsd:schema>\n' % (version, version, extra, body))


BATCH = ('<xsd:element name="doi_batch"><xsd:complexType><xsd:sequence><xsd:element name="head" type="xsd:string"/>'
         '<xsd:element name="body"><xsd:complexType><xsd:sequence><xsd:element ref="journal_article" maxOccurs="unbounded"/>'
         '</xsd:sequence></xsd:complexType></xsd:element></xsd:sequence><xsd:attribute name="version" type="xsd:string"/>'
         '</xsd:complexType></xsd:element>')
ARTICLE = ('<xsd:element name="journal_article"><xsd:complexType><xsd:sequence><xsd:element name="titles" type="xsd:string"/>'
           '<xsd:element ref="o:note" minOccurs="0"/><xsd:element name="doi_data" type="doi_t"/></xsd:sequence>'
           '<xsd:attribute name="n" type="xsd:integer"/>%s</xsd:complexType></xsd:element>')   # %s: an assertion, last (XSD 1.1)
COMMON = ('<xsd:complexType name="doi_t"><xsd:sequence><xsd:element name="doi"><xsd:simpleType><xsd:restriction base="xsd:string">'
          '<xsd:pattern value="10\\.[0-9]{4,9}/.+"/></xsd:restriction></xsd:simpleType></xsd:element></xsd:sequence></xsd:complexType>')
OTHER = ('<?xml version="1.0"?>\n<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:x-other" '
         'elementFormDefault="qualified"><xsd:element name="note" type="xsd:string"/></xsd:schema>\n')
IMPORT_OTHER = '<xsd:import namespace="urn:x-other" schemaLocation="http://other.example/x/other.xsd"/>'


def doc(version, body):
    return ('<?xml version="1.0" encoding="UTF-8"?>\n<doi_batch xmlns="http://www.crossref.org/schema/%s" version="%s">'
            '<head>h</head><body>%s</body></doi_batch>\n' % (version, version, body))


GOOD = '<journal_article n="1"><titles>T</titles><doi_data><doi>10.5555/abc</doi></doi_data></journal_article>'
WRONG_ORDER = '<journal_article n="1"><doi_data><doi>10.5555/abc</doi></doi_data><titles>T</titles></journal_article>'


def part_a():
    print('--- Part A: the checker, against stand-in schemas on a local web server ---')
    t = tempfile.mkdtemp(prefix='crv-')
    web = os.path.join(t, 'web')
    S = os.path.join(web, 'www.crossref.org', 'schemas')
    os.makedirs(S)
    w = lambda name, text: open(os.path.join(S, name), 'w').write(text)
    inc = lambda v: '<xsd:include schemaLocation="common%s.xsd"/>' % v
    # 9.9.9: XML Schema 1.0 — an include on the same host, an import from ANOTHER host (answered from the copy)
    w('crossref9.9.9.xsd', schema('9.9.9', BATCH + ARTICLE % '', inc('9.9.9') + IMPORT_OTHER))
    w('common9.9.9.xsd', schema('9.9.9', COMMON))
    # 9.9.8: carries an XML Schema 1.1 assertion, which lxml cannot read as it stands
    w('crossref9.9.8.xsd', schema('9.9.8', BATCH + ARTICLE % '<xsd:assert test="@n &gt; 0"/>', inc('9.9.8') + IMPORT_OTHER))
    w('common9.9.8.xsd', schema('9.9.8', COMMON))
    # 9.9.7: includes a file the server does not have (404)
    w('crossref9.9.7.xsd', schema('9.9.7', BATCH + ARTICLE % '', '<xsd:include schemaLocation="missing7.xsd"/>' + IMPORT_OTHER + inc('9.9.7')))
    w('common9.9.7.xsd', schema('9.9.7', COMMON))
    # 9.9.6: includes a "schema" the server answers with an HTML page
    w('crossref9.9.6.xsd', schema('9.9.6', BATCH + ARTICLE % '', '<xsd:include schemaLocation="page6.xsd"/>' + IMPORT_OTHER + inc('9.9.6')))
    w('page6.xsd', '<!DOCTYPE html><html><body>Service unavailable</body></html>\n')
    # 9.9.3: imports the XML namespace from "xml.xsd" beside itself — which the server does not have (404), as crossref.org
    # answers for 4.3.6's xml.xsd (3 Oct 2026); W3C's copy (here a stand-in on the same server) must stand in
    lang_article = ARTICLE % '<xsd:attribute ref="xml:lang"/>'
    w('crossref9.9.3.xsd', schema('9.9.3', BATCH + lang_article, inc('9.9.3') + IMPORT_OTHER +
                                  '<xsd:import namespace="http://www.w3.org/XML/1998/namespace" schemaLocation="xml.xsd"/>'))
    w('common9.9.3.xsd', schema('9.9.3', COMMON))
    os.makedirs(os.path.join(web, 'www.w3.org', '2009', '01'))
    open(os.path.join(web, 'www.w3.org', '2009', '01', 'xml.xsd'), 'w').write(
        '<?xml version="1.0"?>\n<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.w3.org/XML/1998/namespace" '
        'xml:lang="en"><xs:attribute name="lang" type="xs:language"/></xs:schema>\n')

    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *a):
            pass
    srv = http.server.ThreadingHTTPServer(('127.0.0.1', 0), functools.partial(Quiet, directory=web))
    port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    base = 'http://127.0.0.1:%d/www.crossref.org/schemas/' % port
    cache = os.path.join(t, 'cache')
    # the other host cannot be reached from a test; its file is put in the copy, where the checker must find it
    os.makedirs(os.path.join(cache, 'other.example', 'x'))
    open(os.path.join(cache, 'other.example', 'x', 'other.xsd'), 'w').write(OTHER)
    f = lambda name, text: (open(os.path.join(t, name), 'w').write(text), os.path.join(t, name))[1]
    good = f('good.xml', doc('9.9.9', GOOD))
    good_note = f('good-note.xml', doc('9.9.9', GOOD.replace('<titles>T</titles>', '<titles>T</titles><note xmlns="urn:x-other">n</note>')))
    bad_order = f('bad-order.xml', doc('9.9.9', WRONG_ORDER))
    bad_doi = f('bad-doi.xml', doc('9.9.9', GOOD.replace('10.5555/abc', 'not-a-doi')))
    not_cr = f('not-crossref.xml', '<?xml version="1.0"?>\n<record xmlns="urn:x-something"/>\n')
    broken = f('broken.xml', '<doi_batch xmlns="http://www.crossref.org/schema/9.9.9"><head>')
    a11 = f('assert.xml', doc('9.9.8', GOOD))
    a11_bad = f('assert-bad.xml', doc('9.9.8', WRONG_ORDER))
    inc7 = f('incomplete.xml', doc('9.9.7', GOOD))
    html6 = f('html.xml', doc('9.9.6', GOOD))
    lang_ok = f('lang-ok.xml', doc('9.9.3', GOOD.replace('<journal_article n="1">', '<journal_article n="1" xml:lang="en">')))
    lang_bad = f('lang-bad.xml', doc('9.9.3', GOOD.replace('<journal_article n="1">', '<journal_article n="1" lang="en">')))
    secret = os.path.join(t, 'secret.txt')
    open(secret, 'w').write('TOP-SECRET-LINE')
    xxe = f('xxe.xml', '<?xml version="1.0"?>\n<!DOCTYPE doi_batch [<!ENTITY x SYSTEM "file://%s">]>\n' % secret +
            doc('9.9.9', GOOD.replace('<titles>T</titles>', '<titles>&x;</titles>')).split('\n', 1)[1])
    try:
        rc, out = run('--cache', cache, '--schemas-url', base, good)
        ck('a valid file: exit 0, VALID, and the check says it was complete', rc == 0 and '   VALID' in out and 'complete (XML Schema 1.0; 3 schema files)' in out, out)
        ck('the first run fetched the schema and its include into the copy, laid out by host and path',
           'fetched ' + base + 'crossref9.9.9.xsd' in out and os.path.isfile(os.path.join(cache, '127.0.0.1_%d' % port, 'www.crossref.org', 'schemas', 'common9.9.9.xsd')), out)
        ck('the import from another host was answered from the copy (never fetched)', 'other.example' not in out and rc == 0, out)
        rc, out = run('--cache', cache, '--schemas-url', base, good_note)
        ck('an element from the imported schema is checked against it too', rc == 0 and '   VALID' in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, bad_order)
        ck('elements out of order: exit 1, INVALID, with the line and the schema\'s own words', rc == 1 and 'INVALID' in out and re.search(r'line \d+, column \d+: .*doi_data', out) is not None, out)
        rc, out = run('--cache', cache, '--schemas-url', base, bad_doi)
        ck('a value the schema\'s pattern refuses: INVALID', rc == 1 and 'INVALID' in out and 'pattern' in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, not_cr)
        ck('not a Crossref deposit file: exit 1, said so, not checked against anything', rc == 1 and 'not a Crossref deposit file' in out and 'VALID' not in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, broken)
        ck('not well-formed XML: exit 1, said so', rc == 1 and 'not well-formed XML' in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, os.path.join(t, 'nowhere.xml'))
        ck('a file that does not exist: exit 1, named', rc == 1 and 'not found' in out, out)
        w3c = 'http://127.0.0.1:%d/www.w3.org/2009/01/xml.xsd' % port
        rc, out = run('--cache', cache, '--schemas-url', base, '--xml-xsd-url', w3c, lang_ok)
        ck('xml.xsd not served where the schema names it: W3C\'s copy stands in, the run says so, the file is VALID',
           rc == 0 and '   VALID' in out and 'xml.xsd is not served where the schema names it' in out and w3c in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, '--xml-xsd-url', w3c, '--offline', lang_bad)
        ck('…offline afterwards (from the copy): a plain lang="en" is INVALID, and the stand-in is still named', rc == 1 and 'INVALID' in out and "'lang'" in out and 'is not served where the schema names it' in out, out)
        rc, out = run('--cache', os.path.join(t, 'cache-no-w3c'), '--schemas-url', base, '--xml-xsd-url', w3c.replace('xml.xsd', 'gone.xsd'), lang_ok)
        ck('W3C\'s copy not to be had either: exit 2, NOT checked, xml.xsd named', rc == 2 and 'NOT checked' in out and base + 'xml.xsd' in out and 'VALID' not in out.replace('INVALID', ''), out)
        rc, out = run('--cache', cache, '--schemas-url', base, a11)
        ck('a schema with an XML Schema 1.1 assertion: the rest is checked and the output says 1 assertion was NOT checked',
           rc == 0 and '1 XML Schema 1.1 assertion(s) NOT checked' in out and 'complete' not in out, out)
        kept = open(os.path.join(cache, '127.0.0.1_%d' % port, 'www.crossref.org', 'schemas', 'crossref9.9.8.xsd')).read()
        ck('…the assertion was removed from a temporary copy only; the kept copy is Crossref\'s file as fetched', '<xsd:assert' in kept)
        rc, out = run('--cache', cache, '--schemas-url', base, a11_bad)
        ck('…and an order error in that schema is still found', rc == 1 and 'INVALID' in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, inc7)
        ck('a schema file the server does not have: exit 2, INCOMPLETE, the file NOT checked, the missing file named',
           rc == 2 and 'INCOMPLETE' in out and 'NOT checked' in out and 'missing7.xsd' in out and 'VALID' not in out.replace('INVALID', ''), out)
        rc, out = run('--cache', cache, '--schemas-url', base, html6)
        ck('an HTML page where a schema should be: refused as "not a schema", exit 2, never kept',
           rc == 2 and 'not a schema' in out and not os.path.exists(os.path.join(cache, '127.0.0.1_%d' % port, 'www.crossref.org', 'schemas', 'page6.xsd')), out)
        rc, out = run('--cache', cache, '--schemas-url', base, xxe)
        ck('an entity pointing at a file on this machine is not read into the check', 'TOP-SECRET-LINE' not in out, out)
        rc, out = run('--cache', cache, '--schemas-url', base, good, bad_order)
        ck('two files, one valid and one not: both reported, exit 1', rc == 1 and out.count('== ') == 2 and '   VALID' in out and 'INVALID' in out, out)
    finally:
        srv.shutdown()
    rc, out = run('--offline', '--cache', cache, '--schemas-url', base, good)
    ck('with the server gone and --offline: checked from the copy, nothing fetched', rc == 0 and '   VALID' in out and 'fetched' not in out, out)
    rc, out = run('--offline', '--cache', os.path.join(t, 'empty-cache'), '--schemas-url', base, good)
    ck('--offline with no copy: exit 2, "not in the cache", NOT checked', rc == 2 and 'not in the cache' in out and 'NOT checked' in out, out)
    rc, out = run('--cache', cache, '--schemas-url', 'http://127.0.0.1:9/www.crossref.org/schemas/', f('fresh.xml', doc('9.9.5', GOOD)))
    ck('a schema that cannot be fetched at all: exit 2, the reason shown', rc == 2 and 'INCOMPLETE' in out and 'Error' in out, out)
    sys.path.insert(0, os.path.join(HERE, '..', 'tools'))
    import crossref_validate as cv
    p = cv.local_path('/c', 'http://h.example/../../../etc/passwd')
    ck('a schema location can never name a path outside the copy', p.startswith('/c/') and '..' not in p, p)
    rc, out = run()
    ck('no file named: the usage, exit 2', rc == 2 and 'crossref_validate.py FILE.xml' in out, out[:200])
    shutil.rmtree(t, ignore_errors=True)


def part_b():
    print('--- Part B: the plugin\'s files against Crossref\'s schemas ---')
    cache = os.environ.get('CR_SCHEMA_CACHE', '')
    if not cache or not os.path.isfile(os.path.join(cache, 'www.crossref.org', 'schemas', 'crossref5.3.1.xsd')):
        print('  SKIPPED — no copy of Crossref\'s schemas here (set CR_SCHEMA_CACHE; tests/schema_copies.sh builds one). '
              'Not counted as a pass.')
        return
    out_dir = tempfile.mkdtemp(prefix='crv-b-')
    p = subprocess.run(['php', os.path.join(HERE, 'emit_completed.php'), out_dir], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    ck('the plugin completed all six files (references, affiliations, Crossmark)', p.returncode == 0, p.stdout.decode())
    for v, fx in (('5.3.1', 'ojs34-5.3.1.xml'), ('5.4.0', 'ojs35-5.4.0.xml')):
        files = [os.path.join(out_dir, '%s-%s' % (k, fx)) for k in ('in', 'done-plain', 'done-crossmark')]
        rc, out = run('--offline', '--cache', cache, *files)
        ck('%s: OJS\'s own file and both completed files VALID, checked completely (nothing skipped)' % v,
           rc == 0 and out.count('   VALID') == 3 and out.count('complete (XML Schema 1.0') == 3, out)
        ck('%s: the completed file carries the 11 references, 3 affiliations (2 with ROR) and Crossmark on both articles' % v,
           '11 reference(s); 3 affiliation(s), 2 with a ROR ID' in out and 'Crossmark on 2' in out, out)
        done = open(files[2], encoding='utf-8').read()
        broken = {
            'the reference list moved before doi_data': re.sub(r'(<doi_data>.*?</doi_data>)(<citation_list>.*?</citation_list>)', r'\2\1', done, count=1, flags=re.S),
            'a ROR ID given an unknown type': done.replace('type="ror"', 'type="rorr"', 1),
            'the licence program left beside Crossmark instead of inside it': done.replace('<crossmark>', '<ai:program xmlns:ai="http://www.crossref.org/AccessIndicators.xsd" name="AccessIndicators"><ai:license_ref>https://creativecommons.org/licenses/by/4.0</ai:license_ref></ai:program><crossmark>', 1),
            'an affiliation placed after the ORCID iD': re.sub(r'(<affiliations>.*?</affiliations>)(<ORCID>[^<]*</ORCID>)', r'\2\1', done, count=1, flags=re.S),
        }
        for what, text in broken.items():
            if text == done:
                ck('%s: broken copy (%s) could be made' % (v, what), False, 'the substitution changed nothing')
                continue
            bf = os.path.join(out_dir, 'broken.xml')
            open(bf, 'w', encoding='utf-8').write(text)
            rc, out = run('--offline', '--cache', cache, bf)
            ck('%s: %s → INVALID' % (v, what), rc == 1 and 'INVALID' in out, out)
    shutil.rmtree(out_dir, ignore_errors=True)


part_a()
part_b()
print('\n%d checks, %d failed%s' % (n, len(failed), (' — ' + '; '.join(failed)) if failed else ' — ALL OK'))
sys.exit(1 if failed else 0)
