<?php
/**
 * Bootstrap of the explayouts_core tests that need no kernel and no database: the transfer classes that do not
 * touch the database. expLayoutsCoreLayoutService is loaded too, as the importer extends it (nothing of it runs).
 *
 * @license GPL-2.0-or-later
 */
$classes = dirname( __DIR__ ) . '/classes/';
require_once $classes . 'explayoutscorelayoutservice.php';
require_once $classes . 'explayoutscoretransferformat.php';
require_once $classes . 'explayoutscoretransferreferences.php';
require_once $classes . 'explayoutscoretransferdiff.php';
require_once $classes . 'explayoutscoretransferimporter.php';
