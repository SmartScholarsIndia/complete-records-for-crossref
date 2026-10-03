#!/usr/bin/env python3
"""Write the three deposit files the tests complete — one per OJS version — in the shape OJS's own Crossref plugin writes.

    python3 ojs-plugin/tests/make_fixtures.py

The CONTENT is example data in the shape of two real records: article A as Crossref's REST API returns a work
(fixtures/crossref-work-a.json), article B as a line of a DOI registry export (fixtures/record-b.json). Names, DOIs,
titles and hosts are example values; the reference list is a real article's, kept for the parsing tests.
The SHAPE follows PKP's filter code line by line (ArticleCrossrefXmlFilter in OJS 3.3 / 3.4 / 3.5, read 3 Oct 2026):
titles, contributors (given_name, surname, ORCID), jats:abstract, publication_date, pages, ai:program, doi_data with the
crawler-based and text-mining collections — and, in OJS 3.5 only, <affiliations> inside person_name.
3 Oct 2026, corrected after the files were checked against copies of Crossref's 5.3.1 and 5.4.0 schemas: on <journal_article>
OJS 3.3 writes publication_type, metadata_distribution_opts="any" and language; OJS 3.4 and 3.5 write publication_type and
language only (metadata_distribution_opts is not in the 5.x schemas, and their filters do not write it). Every version
writes xml:lang on jats:abstract.

These files are NOT a substitute for OJS's real output: the same tests are run again on the test server against files
OJS itself writes, and validated against Crossref's published schemas there.
"""
import json, os, html

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'fixtures')

# both source records are kept in this folder, so the tests stand on their own
work = json.load(open(os.path.join(OUT, 'crossref-work-a.json')))
b = json.load(open(os.path.join(OUT, 'record-b.json')))['record']

def e(s): return html.escape(str(s), quote=False)

def split_name(full):
    parts = full.split()
    return (' '.join(parts[:-1]), parts[-1]) if len(parts) > 1 else ('', parts[0])

A = {
    'doi': work['DOI'], 'title': work['title'][0], 'url': work['resource']['primary']['URL'],
    'pdf': 'https://journal.example.org/index.php/pub/article/download/1445/1032',
    'authors': [{'given': a['given'], 'family': a['family'], 'orcid': a.get('ORCID', '')} for a in work['author']],
    'abstract': html.unescape(work['abstract'].replace('<jats:p>', '').replace('</jats:p>', '')),
    'year': 2025, 'month': 12, 'day': 15, 'first': '35', 'last': '41', 'licence': work['license'][0]['URL'],
    'volume': work['volume'], 'issue': work['issue'],
}
B = {
    'doi': b['doi'], 'title': b['t'], 'url': b['url'], 'pdf': '',
    'authors': [dict(zip(('given', 'family'), split_name(x['f'])), orcid='') for x in b['au']],
    'abstract': '', 'year': 2020, 'month': 4, 'day': 27, 'first': b['pg']['first'], 'last': b['pg']['last'], 'licence': '',
    'volume': b['vol'], 'issue': b['iss'],
}

# test-only affiliations for the OJS 3.5 file (what OJS 3.5 itself would already write for author 1 of article B)
OJS35_AFF = {'name': 'Test Institute of Technology (test value)', 'ror': 'https://ror.org/0abcdef12'}

def article(a, version, ojs35=False):
    people = ''
    for i, au in enumerate(a['authors']):
        aff = ''
        if ojs35 and a is B and i == 0:
            aff = (f'<affiliations><institution><institution_name>{e(OJS35_AFF["name"])}</institution_name>'
                   f'<institution_id type="ror">{e(OJS35_AFF["ror"])}</institution_id></institution></affiliations>')
        orc = f'<ORCID>{e(au["orcid"])}</ORCID>' if au['orcid'] else ''
        if au['given']:
            people += (f'<person_name contributor_role="author" sequence="{"first" if i == 0 else "additional"}" language="en">'
                       f'<given_name>{e(au["given"])}</given_name><surname>{e(au["family"])}</surname>{aff}{orc}</person_name>')
        else:
            people += (f'<person_name contributor_role="author" sequence="{"first" if i == 0 else "additional"}">'
                       f'<surname>{e(au["family"])}</surname>{aff}{orc}</person_name>')
    abst = (f'<jats:abstract xmlns:jats="http://www.ncbi.nlm.nih.gov/JATS1" xml:lang="en"><jats:p>{e(a["abstract"])}</jats:p></jats:abstract>'
            if a['abstract'] else '')
    lic = (f'<ai:program xmlns:ai="http://www.crossref.org/AccessIndicators.xsd" name="AccessIndicators">'
           f'<ai:license_ref>{e(a["licence"])}</ai:license_ref></ai:program>') if a['licence'] else ''
    colls = ''
    if a['pdf']:
        colls = (f'<collection property="crawler-based"><item crawler="iParadigms"><resource>{e(a["pdf"])}</resource></item></collection>'
                 f'<collection property="text-mining"><item><resource mime_type="application/pdf">{e(a["pdf"])}</resource></item></collection>')
    # ArticleCrossrefXmlFilter: 3.3 sets publication_type, metadata_distribution_opts, language; 3.4 and 3.5 publication_type, language
    opts = ' metadata_distribution_opts="any"' if version == '4.3.6' else ''
    return (f'<journal_article xmlns:jats="http://www.ncbi.nlm.nih.gov/JATS1" publication_type="full_text"{opts} language="en">'
            f'<titles><title>{e(a["title"])}</title></titles>'
            f'<contributors>{people}</contributors>{abst}'
            f'<publication_date media_type="online"><month>{a["month"]:02d}</month><day>{a["day"]:02d}</day><year>{a["year"]}</year></publication_date>'
            f'<pages><first_page>{e(a["first"])}</first_page><last_page>{e(a["last"])}</last_page></pages>{lic}'
            f'<doi_data><doi>{e(a["doi"])}</doi><resource>{e(a["url"])}</resource>{colls}</doi_data></journal_article>')

def batch(version, ojs35=False):
    arts = ''
    for a in (A, B):
        arts += ('<journal><journal_metadata><full_title>Example Journal of Testing &amp; Practice</full_title>'
                 '<issn media_type="electronic">1234-5679</issn></journal_metadata>'
                 f'<journal_issue><publication_date media_type="online"><year>{a["year"]}</year></publication_date>'
                 f'<journal_volume><volume>{e(a["volume"])}</volume></journal_volume><issue>{e(a["issue"])}</issue></journal_issue>'
                 + article(a, version, ojs35) + '</journal>')
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            f'<doi_batch xmlns="http://www.crossref.org/schema/{version}" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            f'version="{version}" xsi:schemaLocation="http://www.crossref.org/schema/{version} https://www.crossref.org/schemas/crossref{version}.xsd">'
            '<head><doi_batch_id>test_3oct2026</doi_batch_id><timestamp>20261003010203</timestamp>'
            '<depositor><depositor_name>Example Publishers</depositor_name><email_address>editor@journal.example.org</email_address></depositor>'
            '<registrant>Example Publishers</registrant></head>'
            f'<body>{arts}</body></doi_batch>\n')

os.makedirs(OUT, exist_ok=True)
for name, version, o35 in (('ojs33-4.3.6.xml', '4.3.6', False), ('ojs34-5.3.1.xml', '5.3.1', False), ('ojs35-5.4.0.xml', '5.4.0', True)):
    open(os.path.join(OUT, name), 'w', encoding='utf-8').write(batch(version, o35))
# the references OJS holds for article A: the journal's own list as Crossref shows it, one line each
refs = [r.get('unstructured', '') for r in work['reference']]
json.dump({'doi': A['doi'], 'references': refs}, open(os.path.join(OUT, 'references-a.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
json.dump(work, open(os.path.join(OUT, 'crossref-work-a.json'), 'w', encoding='utf-8'), ensure_ascii=False)
print('written:', sorted(os.listdir(OUT)))
