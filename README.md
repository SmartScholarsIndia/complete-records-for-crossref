# Complete Records for Crossref

An OJS plugin that completes every record your journal deposits with Crossref through OJS's own Crossref plugin,
using what the journal already holds in OJS — and then reads each record back from Crossref to show what landed.

Developed and maintained by **Smart Scholars** (Hyderabad, India) · mou@smartscholars.in

## What it adds to each deposit

| Added | OJS 3.3 | OJS 3.4 | OJS 3.5 |
|---|---|---|---|
| The article's reference list (`<citation_list>`), as entered in OJS | yes | yes | yes |
| Each author's affiliation | plain `<affiliation>` | `<affiliations>` with institution name (and ROR ID when one is stored) | already sent by OJS 3.5 — left as it is |
| Crossmark — only when you enter your Crossmark policy DOI | yes | yes | yes |
| Crossref's Crossmark button under the title of each article that went to Crossref with Crossmark | yes | yes | yes |

OJS's own Crossref plugin (stable-3_3_0 / 3_4_0 / 3_5_0, read October 2026) sends titles, authors with ORCID iDs,
abstracts, dates, pages, licences and full-text links, but no reference list, no affiliations before OJS 3.5 and no
Crossmark. Those are part of what Crossref's Participation Reports measure. This plugin closes that gap without asking
anyone to type anything twice: the references and affiliations are the ones already in OJS.

A reference gets a `<doi>` only when it names exactly one DOI, written in full and matching Crossref's pattern for
modern DOIs; everything else is sent as the text the journal entered, for Crossref's own reference matching.

Funding is left to the [Funding plugin](https://github.com/ajnyga/funding), which adds it to the same deposit; this
plugin runs after it and never writes a second funding element.

## How it works, and why it is safe

OJS hands each Crossref deposit file to a hook (`articlecrossrefxmlfilter::execute`) before sending it — the same hook
the Funding plugin uses. This plugin, registered to run **last**:

1. works on a **copy** of OJS's file and only **adds** elements, each at the place Crossref's schema for that file's
   version (4.3.6 for OJS 3.3, 5.3.1 for 3.4, 5.4.0 for 3.5) requires;
2. never changes or removes anything OJS wrote, and never adds an element the file already has;
3. checks the finished copy against the schema's order rules — including the rule that Crossmark and the bare
   funding/licence programs are alternatives — and **if anything is wrong, OJS's own file is sent exactly as OJS made
   it** and the reason is written to the server's error log;
4. matches each author to the right `<person_name>` by position **and** surname, and leaves a person alone rather
   than risk giving them someone else's institution;
5. checks the completed file against **Crossref's own published schema** for its version before OJS sends it (on by
   default). The schema files are fetched once from crossref.org (and w3.org, for MathML) into OJS's files directory
   and read from there afterwards. If the completed file does not pass, **OJS's own file is sent unchanged** and the
   schema's message is written to the error log and shown in the plugin's status window — so the plugin never turns a
   deposit Crossref would have accepted into one it refuses. If the schema cannot be had (no outbound access, say), the
   status window says the file was not checked, and the completed file — already through the order checks of step 3 —
   is sent.

It sends nothing by itself: it completes the deposits you already make (by hand, or by OJS's scheduled task).

## After the deposit: read-back

**Settings → Website → Plugins → Complete Records for Crossref → Crossref read-back** lists every record deposited
since the plugin was turned on. Each time the window opens, up to ten records are read from Crossref's public REST API
and compared, element by element, with what was sent: *landed*, *partly*, *missing*, or *waiting* (Crossref processes
deposits in its own time; a record is never called missing before Crossref shows our deposit).

## Install

1. OJS 3.3, 3.4 or 3.5 with the Crossref plugin set up (your Crossref login stays in that plugin; this one never asks for it).
2. Download the package for your OJS line from the repository's **Releases** page
   (https://github.com/SmartScholarsIndia/complete-records-for-crossref/releases — `crossrefComplete-<release>-ojs3.3.tar.gz`,
   `-ojs3.4` or `-ojs3.5`) and use **Settings → Website → Plugins → Upload a new plugin**.
   (Not yet in PKP's Plugin Gallery: it will be offered there once PKP has reviewed it.)
3. Enable **Complete Records for Crossref**. References and affiliations are on by default.
4. Only when your Crossmark policy page has its own DOI registered at Crossref: enter that DOI in the plugin's settings.
   Every deposit then carries Crossmark, and each article that has gone to Crossref with it shows Crossref's Crossmark
   button just under its title (Crossref's own widget, unaltered, held at Crossref's 150 pixels whatever the theme's
   own rules for images say). Articles not yet deposited with Crossmark show none.

## Supported versions and maintenance

| Release line | OJS | PHP |
|---|---|---|
| 1.x for OJS 3.3 | 3.3.0 | 7.3 – 8.x |
| 1.x for OJS 3.4 | 3.4.0 | 8.0 – 8.2 |
| 1.x for OJS 3.5 | 3.5.0 | 8.2 + |

Smart Scholars maintains the plugin: a release follows each new OJS minor version and each new Crossref schema
version the Crossref plugin adopts; issues reported on the repository are answered within two working days; every
release is listed in [CHANGELOG.md](CHANGELOG.md). Security problems: mou@smartscholars.in.

## Development

`php tests/run_tests.php` runs the suite (the core against deposit files in each schema version, and the plugins for
OJS 3.3, 3.4 and 3.5 against stand-ins for OJS). `php tools/build.php --tar` assembles the three releases. GitHub runs
the whole suite on every push (`.github/workflows/tests.yml`). The articles, people and DOIs in the test files are
example values in the shape of real records (DOIs under Crossref's example prefix 10.5555).

`python3 tools/crossref_validate.py FILE.xml` checks a deposit file — for instance one exported from OJS with the plugin
on — against Crossref's own published schema for that file's version, before anything is sent (it needs `lxml`, keeps a
copy of the schema files in `~/crossref-schemas`, and sends nothing to Crossref); where a schema set names an xml.xsd that cannot be had at that address — crossref.org does not serve 4.3.6's — W3C's copy of that standard file stands in, and the output says so). `python3 tests/test_validate.py` tests
it; with `CR_SCHEMA_CACHE` set to a copy of the schemas (`tests/schema_copies.sh` builds one) it also checks the plugin's
completed files for OJS 3.4 (schema 5.3.1) and 3.5 (5.4.0).

`python3 tools/crossref_compare.py FILE.xml` says, before a deposit, what it would change at Crossref: each record in the file
is compared with the record Crossref holds now (read from Crossref's public REST API) — what the file ADDS, what it CHANGES
(both values shown), what Crossref holds that the file LACKS, and which DOIs would be registered new. For a journal whose
OJS holds a migrated copy of its articles, this is how a date or page range damaged by the migration is caught before it
reaches Crossref. It sends nothing and writes nothing (`tests/test_compare.py`).

## Licence

GNU General Public License v3 — see [LICENSE](LICENSE). Copyright © 2026 Smart Scholars.
