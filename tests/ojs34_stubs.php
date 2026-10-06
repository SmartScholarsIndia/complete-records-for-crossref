<?php
/**
 * Stand-ins for the OJS 3.4 / 3.5 classes the plugin calls, with their namespaces and signatures as read from
 * pkp/ojs stable-3_4_0 and stable-3_5_0 (3 Oct 2026). Hook::call/run behave as in lib/pkp/classes/plugins/Hook.php:
 * call() hands the callback ($hookName, $args); run() spreads its args after the hook name; sequence order; stop on true.
 * Set $GLOBALS['OJS_LINE'] to '3.4' or '3.5' before including: the author and citation shapes differ between the two.
 */
namespace {
    define('PKP_STRICT_MODE', true);
    function __($key, $params = []) { return $key . ($params ? ' ' . json_encode($params) : ''); }
}

namespace PKP\config {
    class Config { public static function getVar($s, $k) { return $s === 'general' && $k === 'installed' ? true : ($s === 'files' && $k === 'files_dir' ? ($GLOBALS['OJS_FILES_DIR'] ?? null) : null); } }
}

namespace PKP\plugins {
    class Hook {
        public const SEQUENCE_CORE = 0; public const SEQUENCE_NORMAL = 256; public const SEQUENCE_LATE = 512; public const SEQUENCE_LAST = 768;
        public const CONTINUE = false; public const ABORT = true;
        public static array $hooks = [];
        public static function add(string $name, callable $cb, int $seq = self::SEQUENCE_NORMAL): void { self::$hooks[$name][$seq][] = $cb; }
        public static function call(string $name, array $args = []): mixed { return self::run($name, [$args]); }
        public static function run(string $name, $args): bool {
            if (!isset(self::$hooks[$name])) return self::CONTINUE;
            ksort(self::$hooks[$name], SORT_NUMERIC);
            foreach (self::$hooks[$name] as $list) foreach ($list as $cb) if (call_user_func_array($cb, array_merge([$name], $args)) === self::ABORT) return self::ABORT;
            return self::CONTINUE;
        }
    }
    class GenericPlugin {
        public array $settings = []; public array $enabledContexts = [1];
        public function register($category, $path, $mainContextId = null) { return true; }
        public function getSetting($c, $n) { return $this->settings[$c][$n] ?? null; }
        public function updateSetting($c, $n, $v, $t = null) { $this->settings[$c][$n] = $v; }
        public function getEnabled($c = null) { return in_array($c, $this->enabledContexts, true); }
        public function getName() { return 'crossrefcompleteplugin'; }
        public function getTemplateResource($t) { return $t; }
        public function getActions($request, $verb) { return []; }
        public function manage($args, $request) { return null; }
    }
}

namespace PKP\core { class JSONMessage { public function __construct(...$a) {} } }
namespace PKP\linkAction { class LinkAction { public function __construct(...$a) {} } }
namespace PKP\linkAction\request { class AjaxModal { public function __construct(...$a) {} } }
namespace APP\template {
    // one page's template manager: what the plugin puts in the page's <head> and the output filters it registers
    class TemplateManager {
        public static $last = null; public array $headers = []; public array $filters = [];
        public static function getManager($r) { return self::$last ??= new self(); }
        public function addHeader($name, $header, $args = []) { $this->headers[$name] = $header; }
        public function registerFilter($type, $cb) { $this->filters[] = [$type, $cb]; }
        public function unregisterFilter($type, $cb) { $this->filters = array_values(array_filter($this->filters, fn ($f) => !($f[0] === $type && $f[1] === $cb))); }
    }
}

namespace PKP\db {
    class DAORegistry {
        public static array $daos = [];
        public static function getDAO($name) { return self::$daos[$name] ?? null; }
    }
}

namespace APP\facades {
    class Repo {
        public static $submissions;
        public static function submission() { return self::$submissions; }
    }
}

namespace APP\core {
    class Application {
        public static $http; public static $contexts = [1, 2];
        public static function get() { return new self(); }
        public static function isUnderMaintenance(): bool { return false; }
        public function getHttpClient() { return self::$http; }
        public function getRequest() { return null; }   // a queued job: no request, no journal in it
        public static function getContextDAO() { return new \StubContextDao(self::$contexts); }
    }
}

namespace {
    class StubContext {
        private $id; public $contactEmail = '';
        public function __construct($id, $contactEmail = '') { $this->id = $id; $this->contactEmail = $contactEmail; }
        public function getId() { return $this->id; }
        public function getData($k) { return $k === 'contactEmail' ? $this->contactEmail : null; }
    }
    class StubContextDao {
        private $ids; public function __construct($ids) { $this->ids = $ids; }
        public function getAll() { return new StubIter(array_map(fn ($i) => new StubContext($i), $this->ids)); }
    }
    class StubIter { private $a; public function __construct($a) { $this->a = $a; } public function next() { return array_shift($this->a); } }
    class StubAffiliation {
        private $name; private $ror;
        public function __construct($name, $ror) { $this->name = $name; $this->ror = $ror; }
        public function getLocalizedName(?string $l = null): ?string { return $this->name; }
        public function getRor(): ?string { return $this->ror; }
    }
    class StubAuthor {
        private $d; public function __construct($d) { $this->d = $d; }
        public function getGivenName($l) { return $this->d['given']; }
        public function getFamilyName($l) { return $this->d['family']; }
        public function getAffiliation($l) { return $this->d['affiliation'] ?? ''; }   // 3.4
        public function getLocalizedAffiliation() { return ''; }
        public function getData($k) { return $this->d[$k] ?? null; }
        public function getAffiliations(): array { return $this->d['affiliations'] ?? []; }   // 3.5
    }
    class StubPublication {
        private $id; private $data;
        public function __construct($id, $data) { $this->id = $id; $this->data = $data; }
        public function getId() { return $this->id; }
        public function getData($k) { return $this->data[$k] ?? null; }
        public function getStoredPubId($t) { return $t === 'doi' ? ($this->data['doi'] ?? null) : null; }
    }
    class StubSubmission {
        public function __construct(public $contextId, public $publication, public $doi) {}
        public function getCurrentPublication() { return $this->publication; }
        public function getStoredPubId($t) { return $t === 'doi' ? $this->doi : null; }
        public function getData($k) { return $k === 'contextId' ? $this->contextId : null; }
    }
    class StubSubmissionRepo {
        public array $byDoi = []; public array $calls = [];
        public function getByDoi(string $doi, int $contextId) {
            $this->calls[] = [$doi, $contextId];
            $s = $this->byDoi[$doi] ?? null;
            return ($s && $s->contextId === $contextId) ? $s : null;
        }
    }
    class StubCitation { private $t; public function __construct($t) { $this->t = $t; } public function getRawCitation() { return $this->t; } }
    class StubList { private $a; public function __construct($a) { $this->a = $a; } public function toArray() { return $this->a; } public function all() { return $this->a; } }
    class StubCitationDAO {
        public array $byPub = [];
        public function getByPublicationId($id, $rangeInfo = null) { return new StubList(array_map(fn ($t) => new StubCitation($t), $this->byPub[$id] ?? [])); }   // 3.4
        public function getRawCitationsByPublicationId(int $id) { return new StubList($this->byPub[$id] ?? []); }   // 3.5
    }
    class StubResponse {
        public function __construct(private $code, private $body) {}
        public function getStatusCode() { return $this->code; }
        public function getBody() { return $this->body; }
    }
    class StubHttp {
        public array $answers = []; public array $asked = []; public array $options = []; public $fail = null; public $handler = null;
        /** seconds the answer takes; past the request's 'timeout' option it is an error, not an answer, as with Guzzle's curl handler (CURLOPT_TIMEOUT_MS) */
        public float $delay = 0;
        public function request($m, $url, $o) {
            $this->asked[] = $url;
            $this->options[] = $o;
            if ($this->fail) throw new \Exception($this->fail);
            if ($this->delay > 0) {
                if (isset($o['timeout']) && $this->delay > $o['timeout']) {
                    usleep((int) ($o['timeout'] * 1000000));
                    throw new \Exception('cURL error 28: Operation timed out after ' . (int) ($o['timeout'] * 1000) . ' milliseconds');
                }
                usleep((int) ($this->delay * 1000000));
            }
            if ($this->handler) return ($this->handler)($url);
            foreach ($this->answers as $match => $r) if (str_contains($url, $match)) return $r;
            return new StubResponse(404, '{}');
        }
    }
}
