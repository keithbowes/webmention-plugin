<?php

class webmention_plugin extends Plugin
{
	var $author = 'Keith Bowes';
	var $code = 'b2_webmention';
	var $group = 'ping';
	var $version = '0.1';

	function PluginInit( & $params )
	{
		global $app_name, $app_version;
		$this->name = T_('Webmention plugin');
		$this->short_desc = T_('Generates and receives Webmentions');

		$this->user_agent = sprintf("%s/%s Webmention/1.0", $app_name, $app_version);
	}

	/* The post has been created through the back-office */
	function AdminBeforeItemEditCreate( & $params )
	{
		$this->sendWebmention($params['Item']);

	}

	function BeforeBlogDisplay( & $params )
	{
		global $Item;
		global $app_version, $baseurl;

		$source = @$_POST['source'];
		$target = @$_POST['target'];

		// Only deal with Webmentions on items
		if (is_object($Item))
		{
			$csrf = @$_GET['csrf'];

			// TODO: Improve!
			$token = md5(serialize(array('url' => $Item->get_permanent_url(), 'version' => $app_version)));
			add_headline(sprintf('<link rel="webmention" href="%s?webmention&amp;csrf=%s&amp;redir=no" />', $Item->get_permanent_url(), $token));

			// Everything seems OK; try to process the Webmention
			// TODO: Don't process blacklisted sources
			if ($source && $target && $csrf == md5(serialize(array('url' => $target, 'version' => $app_version))))
			{
				// If it's not an HTTP(S) source, don't process it
				if (!preg_match(',^https?://,', $source) || !$this->Settings->get('webmention_enable'))
				{
					header_http_response('400 Bad Request');
					exit(0);
				}


				$blacklisted_hosts = explode("\r\n", $this->Settings->get('webmention_blacklist'));
				foreach ($blacklisted_hosts as $host)
				{
					if (strpos($host, parse_url($source, PHP_URL_HOST)) !== FALSE)
					{
						header_http_response('400 Bad Request');
						exit(0);
					}
				}

				// TODO: Validate asynchronously (SHOULD)
				// TODO: If the source server gives a 410 gone or the link can't be found on the source server, delete the existing Webmention (SHOULD)

				if ($this->validateWebmention($Item, $source))
				{
					if (FALSE)
						header_http_response('400 Bad Request');
					return TRUE;
				}
				else
				{
					header_http_response('400 Bad Request');
					exit(0);
				}
			}
			elseif ($source || $target)
			{
				header_http_response('400 Bad Request');
				exit(0);
			}
			else
				return TRUE;
		}
		elseif ($source || $target)
		{
			global $disp;
			if ($disp == 404)
			{
				header_http_response('410 Gone');
				exit(0);
			}
			elseif ($disp != 'posts')
			{
				header_http_response('500 Internal Server Error');
				exit(0);
			}
			else
			{
				header_http_response('400 Bad Request');
				exit(0);
			}
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
		global $basehost;
		return array(
			'webmention_blacklist' => array(
				'defaultvalue' => "$basehost\nlocalhost\n127.0.0.1",
				'label' => T_('Blacklist'),
				'type' => 'html_textarea',
			),
			'webmention_enable' => array(
				'defaultvalue' => 1,
				'label' => T_('Enable Webmention'),
				'note' => T_('Disabling can tell senders that you don\'t accept Webmentions'),
				'type' => 'checkbox',
			),
		);
	}

	/* When the post is updated, or when it's posted via XML-RPC */
	function PrependItemUpdateTransact( & $params )
	{
		$this->sendWebmention($params['Item']);
	}

	function getEndpoints($link)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_URL, $link);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);

		$document = new DOMDocument();
		@$document->loadHTML(curl_exec($ch));
		$elems = $document->getElementsByTagName('*');

		for ($i = 0; $i < $elems->length; $i++)
		{
			if (in_array($elems->item($i)->tagName, array('a', 'link')) &&
				strtolower($elems->item($i)->getAttribute('rel')) == 'webmention')
			{
				$endpoint = $elems->item($i)->getAttribute('href');
				// Resolve relative URLS
				$u = array_merge(parse_url($link), parse_url($endpoint));
				// Reassemble the URL
				$endpoint = sprintf('%s://%s:%s@%s:%s%s?%s#%s',
					$u['scheme'], $u['user'], $u['pass'], $u['host'], $u['port'],
					$u['path'], $u['query'], $u['fragment']);
				// Cleanup the reassembled URL
				$endpoint = str_replace(array(':@', ':/', ':?', '?#'), array('', '/', '?', '#'), $endpoint);
				$endpoint = preg_replace(',^(\w+)(//),', '$1:$2', $endpoint);

				$endpoints[] = $endpoint;
			}
		}

		curl_close($ch);
		return $endpoints;
	}

	function sendWebmention( & $item )
	{
		if (!is_object($item))
			return;

		$document = new DOMDocument();
		// Don't spit a boatload of warnings for bad HTML
		@$document->loadHTML($item->content);

		// Convert all URLs in text nodes to links
		$url_re = ',(\w+://[^\'"\)]+),';
		$elems = $document->getElementsByTagName('*');
		for ($i = 0; $i < $elems->length; $i++)
		{
			$text = $elems->item($i)->firstChild->nodeValue;
			if ($elems->item($i)->tagName != 'a' &&
				$elems->item($i)->firstChild->nodeType == XML_TEXT_NODE &&
				preg_match($url_re, $text, $matches))
			{
				$link = $document->createElement('a');
				$link->setAttribute('href', $matches[1]);
				$document->appendChild($link);
			}
		}

		// Get all links on the page
		$links = $document->getElementsByTagName('a');
		for ($i = 0 ; $i < $links->length; $i++)
		{
			$link = $links->item($i)->getAttribute('href');
			$endpoints = $this->getEndpoints($link);
			// TODO: Support the Link: <http://example.com>; rel=webmention syntax (MAY)
			// TODO: Only retrieve sources that are < 1 mb (MAY)
			// TODO: Respect caching headers <https://tools.ietf.org/html/rfc7234> (SHOULD)
			foreach ($endpoints as $endpoint)
			{
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_POSTFIELDS,
					http_build_query(array('source' => $item->get_permanent_url(), 'target' => $link)));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_URL, $endpoint);
				curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
				curl_exec($ch);
				curl_close($ch);
			}
		}

	}

	function validateWebmention($item, $source)
	{
		$ch = curl_init($source);
		if ($ch !== FALSE)
		{
			global $DB;

			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
			$page = curl_exec($ch);
			curl_close($ch);

			$document = new DOMDocument();
			@$document->loadHTML($page);

			$elems = $document->getElementsByTagName('*');
			for ($i = 0; $i < $elems->length; $i++)
			{
				if ($elems->item($i)->tagName == 'a')
				{
					$link = $elems->item($i)->getAttribute('href');
				}
				elseif(in_array($elems->item($i)->tagName, array('audio', 'img', 'video')))
				{
					$link = $elems->item($i)->getAttribute('src');
				}

				$link_found = $link == $item->get_permanent_url();
				if ($link_found)
					break;
			}

			if (!$link_found)
				return FALSE;

			global $servertimenow;
			$title = $document->getElementsByTagName('title')->item(0)->nodeValue;
			$comment_content = $DB->quote(sprintf('<strong>%s</strong><br />%s', $title, $item->title));

			// I wish there were some way to do this with the comment class without messing with the database
			$already_exists = $DB->query('SELECT *  FROM T_comments WHERE comment_author=\'' . $source . '\' AND comment_item_ID=' . $item->ID);

			if ($already_exists)
			{
				$comment_id = $DB->get_var(sprintf('SELECT comment_ID FROM T_comments WHERE comment_item_ID=\'%s\' AND comment_author=\'%s\' ORDER BY comment_ID DESC LIMIT 1', $item->ID, $source));
				$DB->query(sprintf('UPDATE T_comments SET comment_author=%s, comment_last_touched_ts=\'%s\', comment_content=\'%s\' WHERE comment_ID=%d', $DB->quote($source), $comment_content, date2mysql($servertimenow), $comment_id));
			}
			else
			{
				$nextid = $DB->get_var('SELECT MAX(comment_ID) + 1 FROM T_comments');

				// TODO: Get the collection's default comment status (publish, review, ...)
				$date = date2mysql($servertimenow);
				$DB->query(sprintf('INSERT INTO T_comments (comment_ID, comment_item_ID, comment_type, comment_status, comment_author, comment_author_IP, comment_date, comment_last_touched_ts, comment_content, comment_renderers, comment_secret) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $nextid, $item->ID, $DB->quote('pingback'), $DB->quote('published'), $DB->quote($source), $DB->quote($_SERVER['REMOTE_ADDR']), $DB->quote($date), $DB->quote($date), $comment_content, $DB->quote('default'), $DB->quote(generate_random_key())));
			}

			return TRUE;
		}

		return FALSE;
	}
}

?>