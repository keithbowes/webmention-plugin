<?php

class Webmention
{
	var $accept_from_current_host = FALSE;
	var $accept_from_loopback = FALSE;
	var $hash_algo = 'md5';
	var $webmentions_enabled = TRUE;

	private $user_agent;

	function __construct()
	{
		$this->source = @$_POST['source'];
		$this->target = @$_POST['target'];
		$this->token = @$_GET['csrf'];

		$this->setUserAgent();
	}

	function getEndpoints($link)
	{
		$ch = curl_init($link);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
		$page_content = curl_exec($ch);

		$document = new DOMDocument();
		@$document->loadHTML(curl_exec($ch));
		$elems = $document->getElementsByTagName('*');
		curl_close($ch);

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

		return $endpoints;
	}

	function getSecret()
	{
		return hash($this->hash_algo, $this->getFirstPostDate());
	}

	function giveResponse($msg, $exp)
	{
		header("HTTP/1.1 $msg");
		header('Content-type: text/plain; charset=utf-8');
		echo "$exp";
		exit(0);
	}

	function isValidToken()
	{
		$secret = $this->getSecret();
		$is_wm = $this->source && $this->target;
		if ($is_wm)
		{
			$is_valid = $this->token == $secret;

			if (!$is_valid)
				$this->giveResponse('400 Bad Request', 'Invalid token');
			return $is_valid;
		}

		return NULL;
	}

	function sendWebmention( $item, $url, $content )
	{
		$document = new DOMDocument();
		// Don't spit a boatload of warnings for bad HTML
		@$document->loadHTML($content);

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
				$ch = curl_init($endpoint);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_POSTFIELDS,
					http_build_query(array('source' => $url, 'target' => $link)));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
				curl_exec($ch);
				curl_close($ch);
			}
		}

	}

	function setUserAgent($ua = '')
	{
		$this->user_agent = ltrim($ua . ' Webmention/1.0');
	}

	// TODO: Validate asynchronously (SHOULD)
	function validateWebmention($item, $source, $item_url)
	{
		if ($this->isValidToken() === NULL)
			return;

		if (!$this->webmentions_enabled)
			$this->giveResponse('400 Bad Request', 'Webmentions aren\'t enabled on this server');

		if ((!$this->accept_from_loopback && $this->isLoopback()) ||
			($this->accept_from_current_host && strpos($_SERVER['HTTP_HOST'], $source) !== FALSE))
			$this->giveResponse('400 Bad Request', 'Request from prohibited source');

		if (preg_match(',^https?://,', $source) === FALSE)
			$this->giveResponse('400 Bad Request', 'Unsupported protocol');

		$ch = curl_init($source);
		if ($ch !== FALSE)
		{
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
			$page = curl_exec($ch);

			$respcode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			if ($respcode == 410)
			{
				$this->deleteWebmention($item, $source);
				curl_close($ch);
				return FALSE;
			}

			curl_close($ch);

			$document = new DOMDocument();
			@$document->loadHTML($page);

			$elems = $document->getElementsByTagName('*');
			$link_found = FALSE;
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

				$link_found = $link == $item_url;
				if ($link_found)
					break;
			}

			if (!$link_found)
			{
				$this->deleteWebmention($item, $source);
				$this->giveResponse('400 Bad Request', 'The link was not found on the source server');
			}

			$already_exists = FALSE;
			$this->getWebmentionInfo($item, $source, $title, $url, $already_exists);
			$method = $already_exists ? 'updateWebmention' : 'saveWebmention';
			$res = $this->$method($item, $source, $document->getElementsByTagName('title')->item(0)->nodeValue, NULL);
			if (!$res)
				$this->giveResponse('400 Bad Request', 'Webmention didn\'t validate');
			return $res;
		}

		return FALSE;
	}

	private function isLoopBack()
	{
		$host = $_SERVER['HTTP_HOST'];
		return preg_match('/^127\.|0\.0\.|192\.168\.|localhost/', $host);
	}
}
?>