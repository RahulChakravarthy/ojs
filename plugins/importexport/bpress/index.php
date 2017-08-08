<?php

/**
 * @defgroup plugins_importexport_bpress
 */

/**
 * @file plugins/importexport/bpress/index.php
 *
 * Copyright (c) 2017 Simon Fraser University Library
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_nlm
 * @brief Wrapper for B press import plugin
 *
 */

require_once 'BPressImportPlugin.inc.php';

return new BPressImportPlugin();
?>