<?php

/**
 * @defgroup plugins_generic_crossrefComplete Complete Records for Crossref
 */

/**
 * @file plugins/generic/crossrefComplete/index.php
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_crossrefComplete
 * @brief Wrapper for Complete Records for Crossref (OJS 3.3).
 */

require_once('CrossrefCompletePlugin.inc.php');

return new CrossrefCompletePlugin();
