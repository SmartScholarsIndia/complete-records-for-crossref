#!/usr/bin/env python3
"""Before a deposit: what would change at Crossref? Each record in a deposit file, compared with the record Crossref holds now.

    python3 crossref_compare.py FILE.xml [FILE2.xml ...]
    python3 crossref_compare.py --mailto someone@example.org FILE.xml     (Crossref's "polite pool": an address it may write to)
    python3 crossref_compare.py --record 10.1234/abc=work.json FILE.xml   (tests: Crossref's record read from a saved file)

Complete Records for Crossref, 3 Oct 2026. A deposit for a DOI that is already registered replaces the metadata Crossref
holds with the metadata in the file. For a journal whose OJS holds a migrated copy of its articles, OJS's copy can be worse
than Crossref's (a date stamped by the migration, a page range lost) — and depositing it would carry the damage to Crossref.
So before the first deposit from a journal, this reads every DOI in the file from Crossref's public REST API
(api.crossref.org/works/<doi>) and says, per record:

    ADDS     what the file carries and Crossref's record lacks (the point of completing a deposit)
    CHANGES  what both hold, with different values — Crossref's value and the file's, side by side
    LACKS    what Crossref's record holds and the file does not carry
    same     what matches

and, for a DOI Crossref does not know, that the deposit would register it new. Only public records are read; nothing is
sent to Crossref and nothing is written. Exit 0 when no record CHANGES or LACKS anything; 1 when a person should look
first; 2 when a record could not be read (then the comparison is incomplete and says so).
"""
import html
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

try:
    from lxml import etree
except ImportError:  # pragma: no cover
    etree = None

UA = 'complete-records-for-crossref/1.0 (pre-deposit comparison; https://smartscholars.in)'
NS_RE = re.compile(r'^http://www\.crossref\.org/schema/(\d+\.\d+\.\d+)$')
JATS = 'http://www.ncbi.nlm.nih.gov/JATS1'
AI = 'http://www.crossref.org/AccessIndicators.xsd'
FR = 'http://www.crossref.org/fundref.xsd'


def norm(s):
    """Text as a reader sees it: entities decoded, tags dropped, white space collapsed."""
    s = html.unescape(re.sub(r'<[^>]+>', ' ', html.unescape(s or '')))
    return re.sub(r'\s+', ' ', s).strip()


def date_parts(d):
    """Crossref's {'date-parts': [[y, m, d]]} as 'y-mm-dd' (as far as it goes), or ''."""
    try:
        p = d['date-parts'][0]
    except (TypeError, KeyError, IndexError):
        return ''
    return '-'.join(('%04d' if i == 0 else '%02d') % int(x) for i, x in enumerate(p) if x is not None)


def from_file(article, ns):
    """What one <journal_article> carries, with its journal and issue."""
    q = lambda el, path: el.xpath(path, namespaces={'cr': ns, 'jats': JATS, 'ai': AI, 'fr': FR})
    t = lambda el, path: norm(''.join(q(el, path + '//text()'))) if q(el, path) else ''
    journal = article.getparent()
    out = {'doi': norm(''.join(q(article, 'cr:doi_data/cr:doi/text()')))}
    out['title'] = t(article, 'cr:titles/cr:title')
    out['authors'] = [{'family': t(p, 'cr:surname'), 'given': t(p, 'cr:given_name'), 'orcid': t(p, 'cr:ORCID')}
                      for p in q(article, 'cr:contributors/cr:person_name[@contributor_role="author"]')]
    dates = {}
    for d in q(article, 'cr:publication_date'):
        parts = [t(d, 'cr:year'), t(d, 'cr:month'), t(d, 'cr:day')]
        val = '-'.join(x.zfill(2) if i else x for i, x in enumerate(parts) if x)
        dates[d.get('media_type') or 'online'] = val
    out['online'] = dates.get('online', '')
    out['print'] = dates.get('print', '')
    out['volume'] = t(journal, 'cr:journal_issue/cr:journal_volume/cr:volume')
    out['issue'] = t(journal, 'cr:journal_issue/cr:issue')
    first, last = t(article, 'cr:pages/cr:first_page'), t(article, 'cr:pages/cr:last_page')
    out['pages'] = first + ('-' + last if last and last != first else '')
    out['abstract'] = t(article, 'jats:abstract') or t(article, 'cr:abstract')
    out['licences'] = sorted(set(norm(x) for x in q(article, './/ai:license_ref/text()')))
    out['landing'] = t(article, 'cr:doi_data/cr:resource')
    out['references'] = len(q(article, 'cr:citation_list/cr:citation'))
    out['affiliations'] = len(q(article, 'cr:contributors/cr:person_name/cr:affiliations/cr:institution')) + \
        len(q(article, 'cr:contributors/cr:person_name/cr:affiliation'))
    out['ror'] = len(q(article, './/cr:institution_id[@type="ror"]'))
    out['funders'] = len(q(article, './/fr:assertion[@name="funder_name"]'))
    out['crossmark'] = bool(q(article, 'cr:crossmark'))
    out['issn'] = sorted(set(norm(x) for x in q(journal, 'cr:journal_metadata/cr:issn/text()')))
    return out


def from_crossref(m):
    """The same fields from Crossref's REST record (message)."""
    authors = [a for a in (m.get('author') or []) if a.get('family') or a.get('name')]
    return {
        'title': norm((m.get('title') or [''])[0]),
        'authors': [{'family': norm(a.get('family') or a.get('name') or ''), 'given': norm(a.get('given') or ''),
                     'orcid': a.get('ORCID') or ''} for a in authors],
        'online': date_parts(m.get('published-online')),
        'print': date_parts(m.get('published-print')),
        'volume': norm(m.get('volume') or ''),
        'issue': norm(m.get('issue') or ''),
        'pages': norm(m.get('page') or ''),
        'abstract': norm(m.get('abstract') or ''),
        'licences': sorted(set(norm(l.get('URL') or '') for l in (m.get('license') or []) if l.get('URL'))),
        'landing': norm(((m.get('resource') or {}).get('primary') or {}).get('URL') or ''),
        'references': int(m.get('reference-count') or len(m.get('reference') or [])),
        'affiliations': sum(len(a.get('affiliation') or []) for a in authors),
        'ror': sum(1 for a in authors for af in (a.get('affiliation') or [])
                   for i in (af.get('id') or []) if str(i.get('id-type', '')).upper() == 'ROR'),
        'funders': len(m.get('funder') or []),
        'crossmark': bool(m.get('update-policy')),
        'issn': sorted(set(m.get('ISSN') or [])),
        'deposited': date_parts(m.get('deposited')),
    }


LABEL = {'title': 'title', 'online': 'online date', 'print': 'print date', 'volume': 'volume', 'issue': 'issue',
         'pages': 'pages', 'abstract': 'abstract', 'licences': 'licence', 'landing': 'landing page', 'issn': 'ISSN',
         'references': 'references', 'affiliations': 'affiliations', 'ror': 'ROR IDs', 'funders': 'funders',
         'crossmark': 'Crossmark'}


def compare(f, c):
    """(adds, changes, lacks, same) — lists of sentences."""
    adds, changes, lacks, same = [], [], [], []
    show = lambda v: ', '.join(v) if isinstance(v, list) else str(v)
    for k in ('title', 'online', 'print', 'volume', 'issue', 'pages', 'landing', 'licences', 'issn'):
        fv, cv = f[k], c[k]
        if fv == cv:
            if fv:
                same.append(LABEL[k])
        elif fv and not cv:
            adds.append('%s %s' % (LABEL[k], show(fv)))
        elif cv and not fv:
            lacks.append('%s (Crossref: %s)' % (LABEL[k], show(cv)))
        else:
            changes.append('%s: Crossref %s → file %s' % (LABEL[k], show(cv), show(fv)))
    if f['abstract'] and c['abstract']:
        (same if f['abstract'] == c['abstract'] else changes).append('abstract' if f['abstract'] == c['abstract'] else 'abstract text differs')
    elif f['abstract']:
        adds.append('the abstract')
    elif c['abstract']:
        lacks.append('the abstract (Crossref has one)')
    # authors: in order, by family name; then their ORCID iDs
    fa, ca = f['authors'], c['authors']
    if [a['family'].lower() for a in fa] == [a['family'].lower() for a in ca]:
        if fa:
            same.append('authors (%d)' % len(fa))
    else:
        changes.append('authors: Crossref %s → file %s' % ('; '.join(a['family'] for a in ca) or 'none', '; '.join(a['family'] for a in fa) or 'none'))
    fo = sum(1 for a in fa if a['orcid']); co = sum(1 for a in ca if a['orcid'])
    if fo > co:
        adds.append('%d ORCID iD(s) (Crossref has %d)' % (fo, co))
    elif fo < co:
        lacks.append('%d ORCID iD(s) — Crossref has %d' % (fo, co))
    elif fo:
        same.append('ORCID iDs (%d)' % fo)
    for k in ('references', 'affiliations', 'ror', 'funders'):
        if f[k] > c[k]:
            adds.append('%d %s (Crossref has %d)' % (f[k], LABEL[k], c[k]))
        elif f[k] < c[k]:
            lacks.append('%s: %d — Crossref has %d' % (LABEL[k], f[k], c[k]))
        elif f[k]:
            same.append('%s (%d)' % (LABEL[k], f[k]))
    if f['crossmark'] and not c['crossmark']:
        adds.append('Crossmark')
    elif c['crossmark'] and not f['crossmark']:
        lacks.append('Crossmark (Crossref has it)')
    elif f['crossmark']:
        same.append('Crossmark')
    return adds, changes, lacks, same


def fetch(doi, mailto):
    url = 'https://api.crossref.org/works/' + urllib.parse.quote(doi, safe='/') + ('?mailto=' + urllib.parse.quote(mailto) if mailto else '')
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode('utf-8'))['message']
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return None
        raise


def main(argv):
    if etree is None:
        print('lxml is not installed for this Python (pip install lxml).')
        return 2
    mailto, records, files = '', {}, []
    i = 1
    while i < len(argv):
        a = argv[i]
        if a == '--mailto' and i + 1 < len(argv):
            mailto = argv[i + 1]; i += 2; continue
        if a == '--record' and i + 1 < len(argv) and '=' in argv[i + 1]:
            d, p = argv[i + 1].split('=', 1)
            records[d.strip().lower()] = p; i += 2; continue
        if a.startswith('--'):
            print('unknown option %s' % a); return 2
        files.append(a); i += 1
    if not files:
        print(__doc__); return 2
    worst, totals = 0, {'records': 0, 'new': 0, 'only_adds': 0, 'look': 0, 'unread': 0}
    for path in files:
        print('== %s' % path.split('/')[-1])
        try:
            doc = etree.parse(path, etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=True))
        except (OSError, etree.XMLSyntaxError) as e:
            print('   cannot be read as XML: %s' % e); worst = max(worst, 2); continue
        ns = etree.QName(doc.getroot()).namespace or ''
        if etree.QName(doc.getroot()).localname != 'doi_batch' or not NS_RE.match(ns):
            print('   not a Crossref deposit file'); worst = max(worst, 2); continue
        for art in doc.getroot().xpath('//cr:journal_article', namespaces={'cr': ns}):
            f = from_file(art, ns)
            totals['records'] += 1
            print('\n   %s' % (f['doi'] or '(no DOI in the file)'))
            if not f['doi']:
                worst = max(worst, 1); totals['look'] += 1; continue
            try:
                if f['doi'].lower() in records:
                    p = records[f['doi'].lower()]
                    m = None if p == '404' else json.load(open(p, encoding='utf-8'))
                    m = m.get('message', m) if isinstance(m, dict) else m
                else:
                    m = fetch(f['doi'], mailto)
            except Exception as e:
                print('   Crossref\'s record could not be read (%s: %s) — NOT compared' % (type(e).__name__, str(e)[:120]))
                worst = max(worst, 2); totals['unread'] += 1; continue
            if m is None:
                print('   not registered at Crossref: this deposit would register it new (Crossref\'s registration fee applies)')
                totals['new'] += 1
                continue
            c = from_crossref(m)
            adds, changes, lacks, same = compare(f, c)
            print('   registered at Crossref%s' % (' — last deposited %s' % c['deposited'] if c['deposited'] else ''))
            if adds:
                print('   ADDS     ' + '; '.join(adds))
            if changes:
                print('   CHANGES  ' + '\n            '.join(changes))
            if lacks:
                print('   LACKS    ' + '\n            '.join(lacks))
            if same:
                print('   same     ' + ', '.join(same))
            if changes or lacks:
                worst = max(worst, 1); totals['look'] += 1
            elif adds:
                totals['only_adds'] += 1
    print('\n%d record(s): %d only add to Crossref\'s record, %d need a person to look first (CHANGES or LACKS), '
          '%d new, %d not read.' % (totals['records'], totals['only_adds'], totals['look'], totals['new'], totals['unread']))
    return worst


if __name__ == '__main__':
    sys.exit(main(sys.argv))
