<?php
/**
 * Complete Records for Crossref — Crossref's Crossmark button on the article page.
 *
 * Crossref asks a journal that deposits Crossmark metadata to show its Crossmark button with the content: close to the
 * article's title, made from Crossref's own widget and not altered (crossref.org/documentation/crossmark/), and the
 * article's DOI in a <meta name="dc.identifier" content="doi:…"> tag, which is where the widget reads it from
 * (widget v2.0: it looks for a meta name starting "dc.identifier" and takes the DOI after "doi:").
 *
 * The button is shown only for an article whose DOI went to the live Crossref WITH Crossmark through this plugin — so a
 * reader never opens a Crossmark window for a record Crossref holds no Crossmark data for. The plugin notes each such
 * DOI as one small file, <files_dir>/crossrefComplete/crossmark/<journal id>/<md5 of the DOI>.txt: no database setting
 * grows with the journal (OJS 3.3 keeps a plugin setting in a TEXT column of 64 KB), and the note travels with OJS's
 * files in every backup.
 *
 * 1.0.0.6 (6 Oct 2026): a journal whose records reach Crossref another way — a sponsor registers its DOIs, or another
 * service deposits the metadata, Crossmark included — never deposits through this plugin, so no note is ever left and
 * no button was ever shown there. With the journal's choice "Our records reach Crossref another way", the button is
 * decided from Crossref's own public record of the article instead (api.crossref.org/works/<doi>, its update-policy
 * field, crossref.org/documentation/retrieve-metadata/rest-api/): shown only when that record names the journal's
 * Crossmark policy DOI. Crossref's documentation expects the button on every item registered with the policy page
 * (crossref.org/documentation/crossmark/participating-in-crossmark/), and the widget reads Crossref's record for the
 * DOI in the page, whoever deposited it. What Crossref said is kept per DOI as one small file beside the notes,
 * <files_dir>/crossrefComplete/crossmark-record/<journal id>/<md5 of the DOI>.json, so that Crossref is asked at most
 * once per DOI — and, for a record without the policy or a read that failed, once a day (Crossref's API etiquette:
 * cache, never repeat the same request; crossref.org/documentation/retrieve-metadata/rest-api/access-and-authentication/).
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

require_once __DIR__ . '/CcrCompleter.php';   // CcrCompleter::bareDoi, the one reading of a DOI the plugin uses

class CcrCrossmark
{
    /** Crossref's widget and its colour button with the words "Check for updates" (crossmark.crossref.org/widget/v2.0/readme.html). */
    const SCRIPT = 'https://crossmark-cdn.crossref.org/widget/v2.0/widget.js';
    const LOGO = 'https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg';

    /** The tag the widget reads the article's DOI from. */
    public static function metaTag($doi)
    {
        return '<meta name="dc.identifier" content="doi:' . htmlspecialchars(trim((string) $doi), ENT_QUOTES, 'UTF-8') . '" />';
    }

    /**
     * The button held at Crossref's own 150 pixels whatever the theme says (1.0.0.5). On a journal's live page the
     * theme's own rules for images overrode the snippet's width="150" and stretched the button across the whole column
     * (3 Oct 2026). A style written on the image itself, marked important, outranks every rule a theme's stylesheet can
     * have — a width, a min-width, or width auto; max-width keeps the button inside a column narrower than 150 pixels,
     * and height auto keeps its proportions.
     */
    const SIZE = 'width:150px !important;min-width:0 !important;max-width:100% !important;height:auto !important';

    /** Crossref's button as Crossref publishes it (widget v2.0), in a box of its own so a theme can space it. */
    public static function button()
    {
        return '<div class="crossrefComplete_crossmark">'
            . '<!-- Start Crossmark Snippet v2.0 -->'
            . '<script src="' . self::SCRIPT . '"></script>'
            . '<a data-target="crossmark"><img src="' . self::LOGO . '" width="150" style="' . self::SIZE . '" alt="Crossmark: check for updates" /></a>'
            . '<!-- End Crossmark Snippet -->'
            . '</div>';
    }

    /**
     * The button put in a whole page just after the article's title: the first </h1> after the page's <article> (every
     * OJS theme the plugin knows puts the article in one), else the first </h1>. A fragment (no </body> — a block, a
     * form fetched on its own) or a page with no title is given back unchanged; the button is never put twice.
     *
     * @param string $html
     * @param string $button
     * @return array [string $html, bool $inserted]
     */
    public static function insert($html, $button)
    {
        $html = (string) $html;
        if (stripos($html, '</body>') === false || strpos($html, 'data-target="crossmark"') !== false) return [$html, false];
        $start = stripos($html, '<article');
        $pos = stripos($html, '</h1>', $start === false ? 0 : $start);
        if ($pos === false) return [$html, false];
        $pos += strlen('</h1>');
        return [substr($html, 0, $pos) . $button . substr($html, $pos), true];
    }

    /** The note for one DOI of one journal; '' when OJS has no files directory set. */
    public static function markerPath($filesDir, $contextId, $doi)
    {
        $filesDir = rtrim((string) $filesDir, '/');
        $doi = strtolower(trim((string) $doi));
        if ($filesDir === '' || $doi === '') return '';
        return $filesDir . '/crossrefComplete/crossmark/' . (int) $contextId . '/' . md5($doi) . '.txt';
    }

    /**
     * Note that this DOI went to the live Crossref with Crossmark (the policy DOI it carried, and when).
     * @return bool whether the note is there now
     */
    public static function mark($filesDir, $contextId, $doi, $policy)
    {
        $path = self::markerPath($filesDir, $contextId, $doi);
        if ($path === '') return false;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        return @file_put_contents($path, strtolower(trim((string) $doi)) . "\t" . trim((string) $policy) . "\t" . gmdate('Y-m-d\TH:i:s\Z') . "\n") !== false;
    }

    /**
     * A later live deposit of this DOI went WITHOUT Crossmark (the journal had emptied its policy DOI): Crossref's record
     * no longer carries it, so the plugin's own note goes too.
     */
    public static function unmark($filesDir, $contextId, $doi)
    {
        $path = self::markerPath($filesDir, $contextId, $doi);
        return $path === '' || !is_file($path) || @unlink($path);
    }

    /** Whether this DOI went to the live Crossref with Crossmark through this plugin. */
    public static function marked($filesDir, $contextId, $doi)
    {
        $path = self::markerPath($filesDir, $contextId, $doi);
        return $path !== '' && is_file($path);
    }

    // ---- 1.0.0.6: the button decided from Crossref's own record (a journal whose records reach Crossref another way)

    /** A record that does not name the policy, or a read that failed, is read again only after this long. */
    const RECORD_RETRY_SECONDS = 86400;

    /**
     * The Crossmark policy DOI a record names — the REST API's update-policy field, given as a doi.org address — in the
     * plugin's own form: the bare DOI, lower case. '' when the record names none.
     * @param mixed $message the "message" of api.crossref.org/works/<doi>
     * @return string
     */
    public static function recordPolicy($message)
    {
        if (!is_array($message) || empty($message['update-policy']) || !is_string($message['update-policy'])) return '';
        return strtolower(CcrCompleter::bareDoi($message['update-policy']));
    }

    /** Where what Crossref said about one DOI of one journal is kept; '' when OJS has no files directory set. */
    public static function recordPath($filesDir, $contextId, $doi)
    {
        $filesDir = rtrim((string) $filesDir, '/');
        $doi = strtolower(trim((string) $doi));
        if ($filesDir === '' || $doi === '') return '';
        return $filesDir . '/crossrefComplete/crossmark-record/' . (int) $contextId . '/' . md5($doi) . '.json';
    }

    /**
     * What is kept about this DOI: 'policy' (the journal's policy DOI the answer was compared with), 'found' (the policy
     * the record named, '' for none, null when Crossref could not be read) and 'at' (when); null when nothing is kept.
     * @return array|null
     */
    public static function readRecord($path)
    {
        if ($path === '' || !is_file($path)) return null;
        $v = json_decode((string) @file_get_contents($path), true);
        if (!is_array($v) || !isset($v['at']) || !isset($v['policy']) || !array_key_exists('found', $v)) return null;
        return array('policy' => (string) $v['policy'], 'found' => $v['found'] === null ? null : (string) $v['found'], 'at' => (int) $v['at']);
    }

    /**
     * Keep what Crossref said about this DOI.
     * @param string|null $found the policy the record named ('' for none), null when Crossref could not be read
     * @return bool
     */
    public static function writeRecord($path, $doi, $policy, $found, $at = null)
    {
        if ($path === '') return false;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $v = array('doi' => strtolower(trim((string) $doi)), 'policy' => strtolower((string) $policy),
                   'found' => $found === null ? null : (string) $found, 'at' => $at === null ? time() : (int) $at);
        return @file_put_contents($path, json_encode($v) . "\n") !== false;
    }

    /**
     * From what is kept and the journal's Crossmark policy DOI as set now: 'show' — Crossref's record names this policy
     * (kept for good, until the journal's policy changes); 'hide' — a different policy, none, or a failed read, less than
     * RECORD_RETRY_SECONDS ago; 'read' — nothing kept, the policy has changed since Crossref was asked, or a day has passed.
     * @param array|null $kept readRecord()
     * @param string $policy the journal's policy DOI, bare and lower case; '' hides
     * @param int $now
     * @return string
     */
    public static function recordDecision($kept, $policy, $now)
    {
        $policy = strtolower(trim((string) $policy));
        if ($policy === '') return 'hide';
        if (!is_array($kept)) return 'read';
        if ($kept['found'] !== null && $kept['found'] !== '' && $kept['found'] === $policy) return 'show';
        if ($kept['policy'] !== $policy) return 'read';
        return ((int) $now - $kept['at']) >= self::RECORD_RETRY_SECONDS ? 'read' : 'hide';
    }

    /**
     * The deposits remembered for the read-back, cut from the oldest end until their JSON fits $maxBytes (OJS 3.3 keeps a
     * plugin setting in a TEXT column: 65,535 bytes). Newest first, as kept.
     * @return array
     */
    public static function fitDeposits(array $list, $maxEntries, $maxBytes)
    {
        $list = array_slice($list, 0, (int) $maxEntries, true);
        while ($list && strlen(json_encode($list)) > $maxBytes) {
            array_pop($list);
        }
        return $list;
    }
}
