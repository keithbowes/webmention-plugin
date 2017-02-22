<?php

class webmention_plugin extends Plugin
{
	var $author = 'Keith Bowes';
	var $code = 'b2_webmention';
	var $group = 'ping';
	// After all the other plugins (e.g. Markdown, Auto Links) generate links
	var $priority = 100;
	var $version = '0.1';

	function PluginInit( & $params )
	{
		global $app_name, $app_version;
		$this->name = T_('Webmention plugin');
		$this->short_desc = T_('Generates and receives Webmentions');

		$this->user_agent = sprintf("%s/%s Webmention/1.0", $app_name, $app_version);
	}

	/* The post has been created through the back office */
	function AdminBeforeItemEditCreate( & $params)
	{
		// TODO: Where we inspect the posts?
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
		// TODO: This is probably where we capture sent Webmentions
		// Ensure the source actually contains the URLs
		// If the post was deleted, send a 410 GONE HTTP response
		$source = $_POST['source'];
		$target = $POST['target'];
		// TODO: Ensure $source and $target are valid HTTP(S) URLs
		// TODO: Validate asynchronously
		// TODO: Make sure the target URL is referenced in <a href> or <* src>
		// TODO: If the request is invalid (target URL not found, target URL does not accept Webmention requests, unsupported URL scheme), send a 400 Bad Request
		// TODO: If the request can't be processed, send a 500 Internal Server Error
		// TODO: Inspect the request and be sure the source isn't already in the comments for the target item's comment
		// TODO: If the source server gives a 410 gone or the link can't be found on the source server, delete the existing Webmention
		// TODO: No content changes on the source or target shouldn't get shown as another comment entry
		// TODO: Encode data as not to be the target of an XSS or CSFR attack
		// TODO: NO response from the web server in five seconds is a failure
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
			)
		);
	}

	/* When the post is updated or posted via XML-RPC */
	function PrependItemUpdateTransact( & $params )
	{
		// TODO: Where we inspect the posts?

		$this->sendWebmention($params['Item']);
	}

	function SkinEndHtmlHead( & $params )
	{
		//TODO: Output a <link> here, or output an HTTP header before blog display?
	}

	function sendWebmention( & $item )
	{
		global $basepath;
		global $DB;

		$document = new DOMDocument();
		$document->loadHTML($item->post_content);
		$links = document.getElementsByTagName('a');
		for ($i = 0 ; $i < $links.length; $i++)
		{
			$link = $links.item($i).href;
			$endpoints = $this->getEndpoints($link);
			// TODO: Send a webmention here
			// TODO: Send FETCH request to the endpoint
			// endpoint?source=$item->post_permalink&target=$link
			// TODO: Support the Link: <http://example.com>; rel=webmention syntax
			// TODO: NO response from the web server in five seconds is a failure
			// TODO: Handle CSFR parameters?
			// TODO: Respect caching headers <https://tools.ietf.org/html/rfc7234>
			foreach ($endpoints as $endpoint)
			{
				curl_setopt($ch, CURL_POST, TRUE);
				curl_setopt($ch, CURLOPT_POSTDFIELDS, sprintf("source=%s&target=%s",
					htmlspecialchars($item->post_permalink), htmlspecialchars($link)));
				$ch = curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				$ch = curl_setopt($ch, CURLOPT_URL, $endpoint);
				$ch = curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
				curl_close($fh);
			}
		}

	}

	function getEndpoints($link)
	{
		// TODO: Discover the endpoint
		// TODO: NO response from the web server in five seconds is a failure
		$ch = curl_init();
		$ch = curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$ch = curl_setopt($ch, CURLOPT_URL, $link);
		$ch = curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);

		$document = new DOMDocument();
		$document->loadHTML(curl_exec($ch));
		// TODO: Loop through <a> and <link> elements looking for ones with rel="webmention"
		$elems = $document->getElementsByTagName('*');
		for ($i = 0; $i < $elems.length; $i++)
		{
			if (array_search(array('a', 'link'), $elems.item($i).tagName) &&
				strtolower($elems.item($i).rel) == 'webmention')
			{
				$endpoint = $elems.item($i).href;
				// Resolve relative URLS
				$endpoint = array_merge(parse_url($link), parse_url($endpoint));
				$endpoints[] = $endpoint;
			}
		}
		curl_close($ch);
		return $endpoints;
	}
}

?>