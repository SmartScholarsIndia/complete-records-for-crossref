#!/usr/bin/env python3
"""Check Crossref deposit files against Crossref's own published schema, before anything is sent.

    python3 crossref_validate.py FILE.xml [FILE2.xml ...]
    python3 crossref_validate.py --cache /root/crossref-schemas FILE.xml
    python3 crossref_validate.py --offline FILE.xml            (no downloads: the copies already in the cache only)

Complete Records for Crossref, 3 Oct 2026. Each file names its own schema version (doi_batch xmlns=
"http://www.crossref.org/schema/5.3.1"); the file is checked against https://www.crossref.org/schemas/crossref<version>.xsd
and every file that schema includes or imports, on any host (Crossref's common, fundref, AccessIndicators, relations,
clinicaltrials and JATS files, and W3C's MathML and xml.xsd). Those are copied once into the cache folder, laid out by
host and path, so every relative schemaLocation resolves on disk exactly as it does on the web; later runs read the
copies and use no network at all.

It writes only into the cache folder. It sends nothing to Crossref, deposits nothing and changes no file it checks.

What is checked, said plainly in the output:
  * schemas 5.3.1 (OJS 3.4) and 5.4.0 (OJS 3.5) are XML Schema 1.0, so lxml checks them COMPLETELY;
  * a schema that uses XML Schema 1.1 assertions (<xsd:assert>, in 5.5.0) cannot be read by lxml as it stands, so the
    assertions are removed from a temporary copy, everything else is checked, and the output says how many were
    skipped. Nothing is reported as fully checked when it was not.
  * if a schema file cannot be fetched, the file is NOT checked and the output names what is missing.

Exit 0 when every file is valid, 1 when any file is invalid (or is not a Crossref deposit file), 2 when a file could
not be checked at all.
"""
import os
import re
import shutil
import sys
import tempfile
import urllib.parse
import urllib.request

try:
    from lxml import etree
except ImportError:  # pragma: no cover - reported, not raised
    etree = None

SCHEMAS = 'https://www.crossref.org/schemas/'
XS = '{http://www.w3.org/2001/XMLSchema}'
NS_RE = re.compile(r'^http://www\.crossref\.org/schema/(\d+\.\d+\.\d+)$')
UA = 'complete-records-for-crossref/1.0 (schema check; https://smartscholars.in)'
# Crossref's 4.3.6 schema set (OJS 3.3) names xml.xsd beside its own files, https://www.crossref.org/schemas/xml.xsd, and
# crossref.org answers 404 there (checked 3 Oct 2026). The schema of the XML namespace is W3C's own; Crossref's 5.4.0 schema
# imports it from this address. When a set's xml.xsd cannot be had where the set names it, this copy stands in, and the
# run says so.
XML_XSD = 'http://www.w3.org/2009/01/xml.xsd'
XML_NS = 'http://www.w3.org/XML/1998/namespace'


def local_path(cache, url):
    """https://www.crossref.org/schemas/a.xsd -> <cache>/www.crossref.org/schemas/a.xsd (never outside the cache)."""
    u = urllib.parse.urlparse(url)
    parts = [p for p in (u.netloc + '/' + u.path).split('/') if p and p not in ('.', '..')]
    parts = [re.sub(r'[^A-Za-z0-9._-]', '_', p) for p in parts]
    return os.path.join(cache, *parts)


def fetch(url, timeout=60):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept': 'application/xml,text/xml,*/*'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def schema_children(data, url):
    """Absolute URLs of every file this schema includes, imports, redefines or overrides. An import that names no
    schemaLocation (JATS imports xlink this way) has nothing to fetch: the schema that does name it supplies it."""
    root = etree.fromstring(data, etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=True))
    out = []
    for tag in ('include', 'import', 'redefine', 'override'):
        for el in root.iter(XS + tag):
            loc = (el.get('schemaLocation') or '').strip()
            if loc:
                out.append(urllib.parse.urljoin(url, loc))
    return out


def keep(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.part'
    with open(tmp, 'wb') as fh:
        fh.write(data)
    os.replace(tmp, path)


def is_xml_namespace_schema(data):
    try:
        root = etree.fromstring(data, etree.XMLParser(resolve_entities=False, no_network=True))
    except etree.XMLSyntaxError:
        return False
    return root.tag == XS + 'schema' and root.get('targetNamespace') == XML_NS


def xml_stand_in(url, cache, offline):
    """W3C's xml.xsd for a set's xml.xsd that cannot be had where the set names it; None when that does not apply."""
    if url == XML_XSD or os.path.basename(urllib.parse.urlparse(url).path) != 'xml.xsd':
        return None
    p = local_path(cache, XML_XSD)
    data = open(p, 'rb').read() if os.path.isfile(p) and os.path.getsize(p) > 0 else None
    if data is None and not offline:
        try:
            data = fetch(XML_XSD)
        except Exception:
            data = None
        if data and is_xml_namespace_schema(data):
            keep(p, data)
    return data if data and is_xml_namespace_schema(data) else None


def mirror(root_url, cache, offline=False, log=None, stood_in=None):
    """Copy root_url and everything it reaches into the cache. Returns (files, missing): files are the local paths of
    the whole set; missing lists (url, why) for anything that could not be had — then the set is incomplete. A dict
    passed as stood_in receives {url: W3C's address} for each xml.xsd that W3C's copy stood in for."""
    queue, seen, files, missing = [root_url], set(), [], []
    if stood_in is None:
        stood_in = {}
    while queue:
        url = queue.pop(0)
        if url in seen:
            continue
        seen.add(url)
        path = local_path(cache, url)
        data = None
        if os.path.isfile(path) and os.path.getsize(path) > 0:
            data = open(path, 'rb').read()
            if os.path.isfile(path + '.stand-in'):
                stood_in[url] = open(path + '.stand-in').read().strip()
        else:
            why = 'not in the cache, and --offline'
            if not offline:
                try:
                    got = fetch(url)
                except Exception as e:  # the reason is shown, never swallowed
                    got, why = None, '%s: %s' % (type(e).__name__, str(e)[:140])
                if got is not None and b'http://www.w3.org/2001/XMLSchema' not in got[:400000]:
                    got, why = None, 'not a schema: %d bytes beginning %r' % (len(got), got[:60])
                data = got
            if data is None:
                alt = xml_stand_in(url, cache, offline)
                if alt is None:
                    missing.append((url, why))
                    continue
                data = alt
                stood_in[url] = XML_XSD
                os.makedirs(os.path.dirname(path), exist_ok=True)
                with open(path + '.stand-in', 'w') as fh:
                    fh.write(XML_XSD)
                if log:
                    log('   %s could not be had (%s); W3C\'s copy of that standard file, %s, stands in' % (url, why, XML_XSD))
            keep(path, data)
            if log and url not in stood_in:
                log('   fetched %s (%d bytes)' % (url, len(data)))
        try:
            kids = schema_children(data, url)
        except etree.XMLSyntaxError as e:
            missing.append((url, 'the copy is not well-formed XML (%s)' % str(e)[:120]))
            continue
        files.append(path)
        queue.extend(k for k in kids if k.startswith(('http://', 'https://')) and k not in seen)
    return files, missing


def without_assertions(files, cache):
    """A temporary copy of the set with every <xsd:assert>/<xsd:assertion> removed. Returns (dir, count)."""
    n = 0
    dst = tempfile.mkdtemp(prefix='crossref-schema-')
    for src in files:
        rel = os.path.relpath(src, cache)
        out = os.path.join(dst, rel)
        os.makedirs(os.path.dirname(out), exist_ok=True)
        doc = etree.parse(src, etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=True))
        hits = [el for el in doc.iter(XS + 'assert', XS + 'assertion')]
        for el in hits:
            el.getparent().remove(el)
        n += len(hits)
        if hits:
            doc.write(out, xml_declaration=True, encoding='utf-8')
        else:
            shutil.copyfile(src, out)
    return dst, n


def count_assertions(files):
    n = 0
    for f in files:
        doc = etree.parse(f, etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=True))
        n += sum(1 for _ in doc.iter(XS + 'assert', XS + 'assertion'))
    return n


def compile_schema(root_url, base_dir):
    """The schema, read from base_dir only: any http(s) location is answered from the copy, never the network."""
    class FromCopy(etree.Resolver):
        def resolve(self, url, pubid, context):
            if url and url.startswith(('http://', 'https://')):
                p = local_path(base_dir, url)
                if os.path.isfile(p):
                    return self.resolve_filename(p, context)
            return None
    parser = etree.XMLParser(no_network=True, huge_tree=True, resolve_entities=False)
    parser.resolvers.add(FromCopy())
    return etree.XMLSchema(etree.parse(local_path(base_dir, root_url), parser))


def carries(doc, ns):
    """One line on what the file holds — so the plugin's additions can be seen in the file OJS exported."""
    q = lambda p: len(doc.getroot().xpath(p, namespaces={'cr': ns, 'fr': 'http://www.crossref.org/fundref.xsd'}))
    return ('%d article(s); %d reference(s); %d affiliation(s), %d with a ROR ID; %d ORCID iD(s); %d abstract(s); '
            'Crossmark on %d; funding on %d' % (
                q('//cr:journal_article'), q('//cr:journal_article/cr:citation_list/cr:citation'),
                q('//cr:person_name/cr:affiliations/cr:institution') + q('//cr:person_name/cr:affiliation'),
                q('//cr:person_name/cr:affiliations/cr:institution/cr:institution_id[@type="ror"]'),
                q('//cr:person_name/cr:ORCID'),
                len(doc.getroot().xpath('//*[local-name()="abstract"]')),
                q('//cr:journal_article/cr:crossmark'), len(doc.getroot().xpath('//fr:program', namespaces={'fr': 'http://www.crossref.org/fundref.xsd'}))))


def main(argv):
    if etree is None:
        print('lxml is not installed for this Python, so nothing can be checked (pip install lxml).')
        return 2
    cache = os.path.expanduser('~/crossref-schemas')
    schemas_url, offline, files = SCHEMAS, False, []
    i = 1
    while i < len(argv):
        a = argv[i]
        if a == '--cache' and i + 1 < len(argv):
            cache = argv[i + 1]; i += 2; continue
        if a == '--schemas-url' and i + 1 < len(argv):   # tests: a stand-in for www.crossref.org/schemas/
            schemas_url = argv[i + 1].rstrip('/') + '/'; i += 2; continue
        if a == '--xml-xsd-url' and i + 1 < len(argv):   # tests: a stand-in for W3C's xml.xsd
            global XML_XSD
            XML_XSD = argv[i + 1]; i += 2; continue
        if a == '--offline':
            offline = True; i += 1; continue
        if a.startswith('--'):
            print('unknown option %s' % a); return 2
        files.append(a); i += 1
    if not files:
        print(__doc__)
        return 2
    cache = os.path.abspath(cache)
    worst = 0
    compiled = {}
    for f in files:
        print('== %s' % os.path.basename(f))
        if not os.path.isfile(f):
            print('   not found: %s' % f); worst = max(worst, 1); continue
        try:
            doc = etree.parse(f, etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=True))
        except etree.XMLSyntaxError as e:
            print('   not well-formed XML: %s' % e); worst = max(worst, 1); continue
        root = doc.getroot()
        ns = etree.QName(root).namespace or ''
        m = NS_RE.match(ns)
        if etree.QName(root).localname != 'doi_batch' or not m:
            print('   not a Crossref deposit file (root <%s>, namespace %s)' % (etree.QName(root).localname, ns or 'none'))
            worst = max(worst, 1); continue
        version = m.group(1)
        root_url = schemas_url + 'crossref%s.xsd' % version
        if version not in compiled:
            stood = {}
            set_files, missing = mirror(root_url, cache, offline, log=print, stood_in=stood)
            if stood:
                print('   note: ' + '; '.join('%s is not served where the schema names it — W3C\'s copy (%s) was used' % (u, w) for u, w in stood.items()))
            if missing:
                compiled[version] = (None, 'the schema is INCOMPLETE, so this file was NOT checked. Missing:\n' +
                                     '\n'.join('      %s — %s' % m_ for m_ in missing))
            else:
                skipped = count_assertions(set_files)
                base, tmp = cache, None
                if skipped:
                    tmp, skipped = without_assertions(set_files, cache)
                    base = tmp
                try:
                    sch = compile_schema(root_url, base)
                    how = ('lxml, complete (XML Schema 1.0; %d schema files)' % len(set_files) if not skipped else
                           'lxml, %d XML Schema 1.1 assertion(s) NOT checked (lxml reads 1.0 only); everything else checked; '
                           '%d schema files' % (skipped, len(set_files)))
                    compiled[version] = (sch, how)
                except etree.XMLSchemaParseError as e:
                    compiled[version] = (None, 'the schema could not be read here, so this file was NOT checked: %s' % str(e)[:300])
                finally:
                    if tmp:
                        shutil.rmtree(tmp, ignore_errors=True)
        sch, how = compiled[version]
        print('   schema %s — %s' % (version, root_url))
        if sch is None:
            print('   ' + how); worst = max(worst, 2); continue
        print('   checked with %s' % how)
        print('   carries: %s' % carries(doc, ns))
        if sch.validate(doc):
            print('   VALID')
            continue
        worst = max(worst, 1)
        errs = list(sch.error_log)
        print('   INVALID — %d problem(s):' % len(errs))
        for e in errs[:40]:
            print('   line %s, column %s: %s' % (e.line, e.column, e.message))
        if len(errs) > 40:
            print('   … and %d more' % (len(errs) - 40))
    print()
    print({0: 'All files valid.', 1: 'Not every file is valid — see above.',
           2: 'At least one file could not be checked — see above.'}[worst])
    return worst


if __name__ == '__main__':
    sys.exit(main(sys.argv))
