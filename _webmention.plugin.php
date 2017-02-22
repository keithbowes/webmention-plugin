<?php

class webmention_plugin extends Plugin
{
	var $author = 'Keith Bowes';
	// After all the other plugins (e.g. Markdown, Auto Links) generate links
	var $priority = 100;
	var $version = '0.1';

	function PluginInit( & $params )
	{
		$this->name = T_('Webmention plugin');
		$this->short_desc = T_('Generates and receives webmentions');
	}

	function BeforeEnable()
	{
		if ( !extension_loaded ('curl'))
			return T_('This plugin requires the PHP curl extension');

		return TRUE;
	}
}