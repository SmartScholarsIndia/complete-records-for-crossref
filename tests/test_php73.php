<?php
/** OJS 3.3 runs on PHP 7.3: the shared core, the OJS 3.3 plugin and the OJS 3.3 fill tool use nothing newer (tools/check_php73.php). */
require __DIR__ . '/_ck.php';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/check_php73.php') . ' 2>&1', $out, $rc);
ck('the files that run on OJS 3.3 servers (core/, ojs-3.3/, and fill33/ where kept) use nothing PHP 7.3 lacks — ' . end($out), $rc === 0, implode(' | ', $out));
done();
