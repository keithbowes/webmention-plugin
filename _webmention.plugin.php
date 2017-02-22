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

	function AdminBeforeItemEditDelete( & $params)
	{
		// TODO: Where we delete Webmentions associated with the deleted item?
		$item &= $params['Item'];
	}

	function AfterItemDelete( & $paras)
	{
		// TODO: Where we delete Webmentions associated with the deleted item?
		$item &= $params['Item'];
	}

	function BeforeBlogDisplay( & $params )
	{
		global $Item;
		global $app_version, $baseurl;
		global $basepath;

		// Only deal with Webmentions on items
		if (is_object($Item))
		{
			// TODO: Ensure the source actually contains the URLs (MUST)
			// TODO: If the post was deleted, send a 410 GONE HTTP response (SHOULD)
			$csrf = @$_GET['csrf'];
			$source = @$_POST['source'];
			$target = @$_POST['target'];

			$fh = fopen($basepath . '/webmention', 'a');
			fwrite($fh, $source . PHP_EOL);
			fwrite($fh, $target . PHP_EOL);

			// TODO: Improve!
			$token = md5(serialize(array('url' => $Item->get_permanent_url(), 'version' => $app_version)));
			add_headline(sprintf('<link rel="webmention" href="%s?webmention&amp;csrf=%s&amp;redir=no" />', $Item->get_permanent_url(), $token));

			// Everything seems OK; try to process the Webmention
			// TODO: Don't process blacklisted sources
			if ($source && $target && $token == md5(serialize(array('url' => $target, 'version' => $app_version))))
			{
				$fh = fopen($basepath . '/webmention', 'a');
				fwrite($fh, sprintf('"%s" and "%s" are the same. Continue.%s', $Item->get_permanent_url(), $target, PHP_EOL));
				fclose($fh);
				// If it's not an HTTP(S) source, don't process it
				if (!preg_match(',^https?://,', $source))
				{
					header_http_response('400 Bad Request');
					exit(0);
				}
			}
		

			// TODO: Ensure $source and $target are valid HTTP(S) URLs (MUST)
			// TODO: Validate asynchronously (SHOULD)
			// TODO: Make sure the target URL is referenced in <a href> or <* src> (SHOULD)
			// TODO: If the request is invalid (target URL not found, target URL does not accept Webmention requests, unsupported URL scheme), send a 400 Bad Request (MUST)
			// TODO: If the request can't be processed, send a 500 Internal Server Error (SHOULD)
			// TODO: Inspect the request and be sure the source isn't already in the comments for the target item's comment (MUST)
			// TODO: If the source server gives a 410 gone or the link can't be found on the source server, delete the existing Webmention (SHOULD)
			// TODO: No content changes on the source or target shouldn't get shown as another comment entry (SHOULD)
			// TODO: Encode data as not to be the target of an XSS or CSRF attack (MUST)
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_close($ch);
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
				'defaultvalue' => "$basehost\nlocalhost\n127.0.0.1/8",
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

	function sendWebmention( & $item )
	{
		global $basepath;
		global $DB;

		if (!is_object($item))
			return;

		$document = new DOMDocument();
		// Don't spit a boatload of warnings for bad HTML
		@$document->loadHTML($item->content);

		// Convert all URLs in text nodes to links
		$url_re = ',\w+://[^\'"\)]+,';
		$elems = $document->getElementsByTagName('*');
		for ($i = 0; $i < $elems->length; $i++)
		{
			$text = $elems->item($i)->nodeValue;
			if ($elems->item($i)->tagName != 'a' &&
				preg_match($url_re, $text))
			{
				$link = $document->createElement('a');
				$link->setAttribute('href', $text);
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
			// TODO: Handle CSRF parameters? (SHOULD)
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
				global $basepath;
				$fh = fopen($basepath . '/webmention', 'a');
				fwrite($fh, sprintf('Found endpoint "%s"%s', $endpoint, PHP_EOL));
				fclose($fh);

				$endpoints[] = $endpoint;
			}
		}

		curl_close($ch);
		return $endpoints;
	}
}

?>