<?php

/*
	Plugin Name: ElasticSearch Integration
	Plugin URI:
	Plugin Description: Replaces search engine with ElasticSearch
	Plugin Version: 1.0
	Plugin Date: 2014-03-26
	Plugin Author: Fabio Dellutri
	Plugin Author URI: http://www.mitecube.com/
	Plugin License: GPLv2
	Plugin Minimum Question2Answer Version: 1.5
	Plugin Update Check URI:
*/

if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
    header('Location: ../../');
    exit;
}

qa_register_plugin_module('search', 'qa_elasticsearch_search.php', 'qa_elasticsearch_search', 'ElasticSearch Integration');