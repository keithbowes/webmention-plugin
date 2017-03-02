<?php

interface iWebmention
{
	function deleteWebmention($item, $source);
	function getFirstPostDate();
	function getWebmentionInfo($item, $source, & $title, & $url, & $already_exists);
	function saveWebmention($item, $source, $title, $url, $text);
	function updateWebmention($item, $source, $title, $url, $text);
}

?>