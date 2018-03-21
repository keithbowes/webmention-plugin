<?php

require '_evo_webmention.class.php';

class webmention_plugin extends Plugin
{
	var $author = 'Keith Bowes';
	var $code = 'b2evWebmention';
	var $group = 'ping';
	var $number_of_installs = 1;
	var $version = '0.2';

	function PluginInit( & $params )
	{
		global $app_name, $app_version;
		$this->name = T_('Webmention plugin');
		$this->short_desc = T_('Sends and receives Webmentions');

		$this->webmention = new evo_Webmention();
		$this->webmention->setUserAgent(sprintf("%s/%s", $app_name, $app_version));

		if ($params['is_installed'] && is_object($this->Settings))
		{
			$this->webmention->accept_from_current_host = !$this->Settings->get('webmention_block_current_host');
			$this->webmention->accept_from_loopback = !$this->Settings->get('webmention_block_localhost');
			$this->webmention->comment_type = $this->Settings->get('webmention_comment_type');
			$this->webmention->webmentions_enabled = $this->Settings->get('webmention_enable');
		}

		if ($this->BeforeEnable() !== TRUE)
			$this->set_status('disabled');
	}

	/* The post has been created through the back-office */
	function AdminBeforeItemEditCreate( & $params )
	{
		$item = $params['Item'];
		$this->webmention->sendWebmention($item, $item->get_permanent_url(), $item->content);

	}

	function BeforeBlogDisplay( & $params )
	{
		global $Item;

		// Only deal with Webmentions on items
		if (is_object($Item))
		{
			add_headline(sprintf('<link rel="webmention" href="%s?webmention&amp;csrf=%s&amp;redir=no" />', $Item->get_permanent_url(), $this->webmention->getSecret()));

			// Everything seems OK; try to process the Webmention
			if ($this->webmention->isValidToken() !== NULL)
			{
				$blacklisted_hosts = explode("\r\n", $this->Settings->get('webmention_blacklist'));
				foreach ($blacklisted_hosts as $host)
				{
					if (strpos($host, parse_url($this->webmention->source, PHP_URL_HOST)) !== FALSE)
					{
						$this->webmention->giveResponse('400 Bad Request', 'Blacklisted source host');
						exit(0);
					}
				}

				$this->webmention->validateWebmention($Item, $this->webmention->source, $Item->get_permanent_url());
			}
			elseif ($this->webmention->source || $this->webmention->target)
				$this->webmention->giveResponse('400 Bad Request', 'Invalid token');
			else
				return TRUE;
		}
		elseif ($this->webmention->source || $this->webmention->target)
		{
			global $disp;
			if ($disp == 404)
				$this->webmention->giveResponse('410 Gone', 'This post doesn\'t exist');
			elseif ($disp != 'posts')
				$this->webmention->giveResponse('500 Internal Server Error', 'This web server couldn\'t process this Webmention');
			else
				$this->webmention->giveResponse('400 Bad Request', 'It looks like someone tried to send a Webmention to some resource that\'s not an item');
		}
	}

	function BeforeEnable()
	{
		if ( !extension_loaded ('curl'))
			return T_('This plugin requires the PHP curl extension');

		return TRUE;
	}

	function GetDefaultSettings( & $params )
	{
		return array(
			'webmention_enable' => array(
				'defaultvalue' => 1,
				'label' => T_('Enable Webmention'),
				'note' => T_('Disabling can tell senders that you don\'t accept Webmentions'),
				'type' => 'checkbox',
			),
			'webmention_comment_type' => array(
				'defaultvalue' => 'pingback',
				'label' => T_('Save WebMentions as'),
				'note' => T_('The database must be altered to allow saving as WebMention'),
				'options' => array(
					'comment' => T_('Comment'),
					'linkback' =>T_('Linkback'),
					'trackback' => T_('Trackback'),
					'pingback' => T_('Pingback'),
					'mention' => T_('WebMention'),
				),
				'type' => 'select',
			),
			'webmention_block_current_host' => array(
				'defaultvalue' => 1,
				'label' => T_('Block Webmentions originating from the server\'s host'),
				'note' => T_('The purpose of Webmentions is social networking, so you probably don\'t want to be notified when you mention yourself'),
				'type' => 'checkbox',
			),
			'webmention_block_localhost' => array(
				'defaultvalue' => 1,
				'label' => T_('Block Webmentions originating from the server\'s computer'),
				'note' => T_('If this ever happen, it\'s probably an attack'),
				'type' => 'checkbox',
			),
			'webmention_blacklist' => array(
				'defaultvalue' => '',
				'label' => T_('Blacklist'),
				'type' => 'html_textarea',
			),
		);
	}

	/* Requires the earliest version with all the DB tables and columns I access
	 * (the comment_item_ID/comment_last_touched_ts columns were added in DB version 11200). */
	/* Recommends the earliest version with the $Collection var
	 * (so that it can properly access the current collection instead of using a default value). */
	/* For old versions that used api_min instead of app_min, using a higher API version than
	 * they supported. */
	function GetDependencies()
	{
		return array(
			'requires' => array('app_min' => '5.1', 'api_min' => array(1, 3)),
			'recommends' => array('app_min' => '6.6.2', 'api_min' => 2),
		);
	}

	/* When the post is updated, or when it's posted via XML-RPC */
	function PrependItemUpdateTransact( & $params )
	{
		$item = $params['Item'];
		$this->webmention->sendWebmention($item, $item->get_permanent_url(), $item->content);
	}
}

?>