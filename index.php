<?php

/**
 * @defgroup plugins_generic_algolia Algolia Plugin
 */

/**
 * @file plugins/generic/algolia/index.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_algolia
 * @brief Wrapper for Algolia plugin.
 *
 */

require_once('AlgoliaPlugin.inc.php');

return new AlgoliaPlugin();

