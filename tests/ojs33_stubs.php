<?php
/**
 * Stand-ins for the few OJS 3.3 classes the plugin calls, so its own code — the hooks, the lookups, the read-back — runs
 * here without an OJS installation. Each stand-in has the signature of the OJS 3.3 class it replaces (read from
 * pkp/ojs stable-3_3_0 and pkp/pkp-lib on 3 Oct 2026). What it cannot prove — that OJS really calls the hooks this way —
 * is proved on the test server, where the same plugin runs inside OJS 3.3 itself.
 */
define('HOOK_SEQUENCE_CORE', 0x000);
define('HOOK_SEQUENCE_NORMAL', 0x100);
define('HOOK_SEQUENCE_LATE', 0x200);
define('HOOK_SEQUENCE_LAST', 0x300);
define('ROUTE_COMPONENT', 'component');

function import($path) {}
function __($key, $params = array()) { return $key . ($params ? ' ' . json_encode($params) : ''); }

class Config { static function getVar($section, $key) { return $section === 'general' && $key === 'installed' ? true : ($section === 'files' && $key === 'files_dir' ? (isset($GLOBALS['OJS_FILES_DIR']) ? $GLOBALS['OJS_FILES_DIR'] : null) : null); } }

class HookRegistry {
    static $hooks = array();
    static function register($name, $callback, $sequence = HOOK_SEQUENCE_NORMAL) { self::$hooks[$name][] = array($sequence, $callback); return true; }
    /** As OJS 3.3 calls them: in sequence order, stopping when one returns true. */
    static function call($name, $args = array()) {
        if (empty(self::$hooks[$name])) return false;
        $list = self::$hooks[$name];
        usort($list, function ($a, $b) { return $a[0] - $b[0]; });
        foreach ($list as $h) if (call_user_func($h[1], $name, $args)) return true;
        return false;
    }
}

class GenericPlugin {
    public $settings = array();
    public $enabledContexts = array(1);
    function register($category, $path, $mainContextId = null) { return true; }
    function getSetting($contextId, $name) { return isset($this->settings[$contextId][$name]) ? $this->settings[$contextId][$name] : null; }
    function updateSetting($contextId, $name, $value, $type = null) { $this->settings[$contextId][$name] = $value; }
    function getEnabled($contextId = null) { return in_array($contextId, $this->enabledContexts, true); }
    function getName() { return 'crossrefcompleteplugin'; }
    function getTemplateResource($t) { return $t; }
    function getActions($request, $verb) { return array(); }
    function manage($args, $request) { return null; }
    function import($c) { require_once __DIR__ . '/../build/ojs-3.3/crossrefComplete/' . $c . '.inc.php'; }
}

class StubAuthor {
    private $d;
    function __construct($d) { $this->d = $d; }
    function getGivenName($l) { return $this->d['given']; }
    function getFamilyName($l) { return $this->d['family']; }
    function getAffiliation($l) { return isset($this->d['affiliation'][$l]) ? $this->d['affiliation'][$l] : ''; }
    function getLocalizedAffiliation() { return isset($this->d['affiliation']['*']) ? $this->d['affiliation']['*'] : ''; }
}
class StubPublication {
    private $id; private $data;
    function __construct($id, $data) { $this->id = $id; $this->data = $data; }
    function getId() { return $this->id; }
    function getData($k) { return isset($this->data[$k]) ? $this->data[$k] : null; }
}
class StubSubmission {
    public $contextId; public $publication; public $doi;
    function __construct($contextId, $publication, $doi) { $this->contextId = $contextId; $this->publication = $publication; $this->doi = $doi; }
    function getContextId() { return $this->contextId; }
    function getCurrentPublication() { return $this->publication; }
    function getStoredPubId($t) { return $t === 'doi' ? $this->doi : null; }
}
class StubCitation { private $t; function __construct($t) { $this->t = $t; } function getRawCitation() { return $this->t; } }
class StubResult { private $a; function __construct($a) { $this->a = $a; } function toArray() { return $this->a; } }

class StubSubmissionDAO {
    public $byDoi = array(); public $calls = array();
    function getByPubId($type, $pubId, $contextId = null) {
        $this->calls[] = array($type, $pubId, $contextId);
        // OJS compares the stored text; on PostgreSQL case-sensitively — so the stand-in does too
        if ($type !== 'doi' || !isset($this->byDoi[$pubId])) return null;
        $s = $this->byDoi[$pubId];
        return ($contextId === null || $s->getContextId() == $contextId) ? $s : null;
    }
}
class StubCitationDAO {
    public $byPub = array();
    function getByPublicationId($id, $rangeInfo = null) { return new StubResult(isset($this->byPub[$id]) ? $this->byPub[$id] : array()); }
}
class DAORegistry {
    static $daos = array();
    static function getDAO($name) { return isset(self::$daos[$name]) ? self::$daos[$name] : null; }
}

class StubResponse {
    private $code; private $body;
    function __construct($code, $body) { $this->code = $code; $this->body = $body; }
    function getStatusCode() { return $this->code; }
    function getBody() { return $this->body; }
}
class StubHttp {
    public $answers = array(); public $asked = array(); public $fail = null; public $handler = null;
    function request($method, $url, $opts) {
        $this->asked[] = $url;
        if ($this->fail) throw new Exception($this->fail);
        if ($this->handler) { $h = $this->handler; return $h($url); }
        foreach ($this->answers as $match => $resp) if (strpos($url, $match) !== false) return $resp;
        return new StubResponse(404, '{}');
    }
}
class Application {
    static $http;
    static function get() { return new self(); }
    function getHttpClient() { return self::$http; }
}
