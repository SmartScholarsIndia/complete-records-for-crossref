# Changelog

## 1.0.0.5 — 3 October 2026

- The Crossmark button keeps Crossref's own size, 150 pixels wide, on every theme. On a journal's live page the theme's
  rules for images overrode the snippet's `width="150"` and stretched the button across the whole column. The image now
  carries its size in a style of its own, marked important, which outranks any rule in a theme's stylesheet; it still
  shrinks inside a column narrower than 150 pixels, keeping its proportions. Nothing else changed.

## 1.0.0.4 — 3 October 2026

- Crossref's Crossmark button on the article page. When the journal has set its Crossmark policy DOI, an article that
  went to the live Crossref with Crossmark through this plugin shows Crossref's own button (widget v2.0, unaltered) just
  under its title, and its DOI in the `<meta name="dc.identifier" content="doi:…">` tag the button reads. An article
  that has not gone to Crossref with Crossmark — never deposited, deposited before Crossmark was set, or deposited to
  Crossref's test system — shows no button, so a reader never opens a Crossmark window for a record without Crossmark
  data. Each such deposit is noted as one small file in OJS's files directory
  (`crossrefComplete/crossmark/<journal>/<md5 of the DOI>.txt`); deposits made with 1.0.0.3 that carried Crossmark are
  read from the read-back's own record. The page is otherwise unchanged; a theme that already shows a Crossmark button
  is left alone.
- The deposits remembered for the read-back now always fit OJS 3.3's plugin-settings column (TEXT, 65,535 bytes): the
  oldest are dropped first. Before, about 150 deposits would have outgrown it — on a database in strict mode that error
  would have come inside OJS's own deposit request. Any database error while keeping them now goes to the error log and
  never reaches OJS's deposit.

## 1.0.0.3 — 3 October 2026

- The check against Crossref's schema can now finish for OJS 3.3's schema, 4.3.6. That schema names an xml.xsd beside
  Crossref's own files, and crossref.org answers 404 at that address (seen on a member journal's OJS, 3 Oct 2026), so every check
  stopped at "not checked". W3C's copy of that standard file — the one Crossref's own 5.4.0 schema imports,
  http://www.w3.org/2009/01/xml.xsd — now stands in, and the status window says so whenever it was used. Nothing else is
  ever stood in for: any other missing file still leaves a file "not checked", never "valid". tools/crossref_validate.py
  does the same.

## 1.0.0.2 — 3 October 2026

- The copy the additions are made in is now OJS's file written out and read back, every character kept. Before, it was
  DOMDocument::cloneNode(); run inside a real OJS 3.3.0.23 on PHP 8.4, that moved each abstract's xml:lang into
  Crossref's namespace whenever something was added — a file Crossref refuses.
- Every finished file is now checked to be exactly OJS's own file plus the additions (canonical XML, indentation set
  aside); anything else sends OJS's own file. Added elements are indented like OJS's own.
- The status window shows what the plugin did with the last file OJS's Crossref plugin built (an Export or a deposit):
  what was added to each article, or why nothing was ("references: none entered in OJS", "affiliations: none entered
  in OJS") — so a file with nothing added still shows that the plugin ran.

## 1.0.0.1 — 3 October 2026

- Knows when OJS's Crossref plugin is set to Crossref's test system: such deposits are listed as test deposits ("sent
  to Crossref's test system — not live") and not read back from the live Crossref, and the status window says that
  deposits from the journal do not reach the live Crossref while that setting is on (OJS marks them registered anyway).

## 1.0.0.0 — 3 October 2026

First release, for OJS 3.3, 3.4 and 3.5 (a trial install on one journal's OJS 3.3).

- Completes each deposit made by OJS's Crossref plugin with the article's reference list and the authors'
  affiliations (with ROR IDs from schema 5.3.0 on, where stored), and with Crossmark when a policy DOI is set.
- Works on a copy; sends OJS's own file unchanged when the completed one would break the schema's order.
- Runs after the Funding plugin and never duplicates its funding element.
- Reads deposited records back from Crossref's REST API and shows, element by element, what landed.
- Checks each completed file against Crossref's published schema for its version before it is sent (schemas fetched
  once into OJS's files directory); a file that does not pass is replaced by OJS's own. The settings can switch the
  check off; the status window shows the last result. Checked against Crossref's 5.3.1 and 5.4.0 schemas (copies) in
  the test suite.
