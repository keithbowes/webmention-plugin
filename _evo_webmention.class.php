<?php
require '_webmention.class.php';
require '_webmention.interface.php';

class evo_Webmention extends Webmention implements iWebmention
{

	public $comment_type;

	function deleteWebmention($item, $source)
	{
		global $DB;
		$DB->query("UPDATE T_comments SET comment_status='trash' WHERE comment_author_url = '$source'"); 
	}

	function getFirstPostDate()
	{
		global $Collection, $DB;
		$id = is_object($Collection) ? $Collection->ID : 1;
		return $DB->get_var(sprintf('SELECT post_datecreated FROM T_items__item WHERE post_main_cat_ID IN (SELECT cat_ID FROM T_categories WHERE cat_blog_ID = %d) LIMIT 1', $id));
	}

	function getWebmentionInfo($item, $source, & $title, & $url, & $already_exists)
	{
		global $DB;
		$already_exists = $DB->query('SELECT *  FROM T_comments WHERE comment_author_url=\'' . $source . '\' AND comment_item_ID=' . $item->ID);
		$url = $title = $source;
	}

	function saveWebmention($item, $source, $title, $url, $text)
	{
		global $DB;
		global $servertimenow;

		$already_exists = FALSE;
		$this->getWebmentionInfo($item, $source, $source_title, $url, $already_exists);

		$comment_content = $DB->quote(sprintf('<strong>%s</strong><br />%s', $title, $text));
		$date = $DB->quote(date2mysql($servertimenow));

		// I wish there were some way to do this with the comment class without messing with the database
		if ($already_exists)
		{
			$comment_id = $DB->get_var(sprintf('SELECT comment_ID FROM T_comments WHERE comment_item_ID=%d AND comment_author_url=%s ORDER BY comment_ID DESC LIMIT 1', $item->ID, $DB->quote($source)));
			$DB->query(sprintf('UPDATE T_comments SET comment_author=%s, comment_author_url=%s, comment_last_touched_ts=%s, comment_content=%s WHERE comment_ID=%d', $DB->quote($title), $DB->quote($source), $date, $comment_content, $comment_id));
		}
		else
		{
			global $Collection;
			$comment_status = is_object($Collection) ? $DB->get_var(sprintf('SELECT cset_value FROM T_coll_settings WHERE cset_name=\'new_feedback_status\' AND cset_coll_ID=%d', $Collection->ID)) : 'published';
			$nextid = $DB->get_var('SELECT MAX(comment_ID) + 1 FROM T_comments');

			$DB->query(sprintf('INSERT INTO T_comments (comment_ID, comment_item_ID, comment_type, comment_status, comment_author, comment_author_URL, comment_author_IP, comment_date, comment_last_touched_ts, comment_content, comment_renderers, comment_secret) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $nextid, $item->ID, $DB->quote($this->comment_type), $DB->quote($comment_status), $DB->quote($title), $DB->quote($source), $DB->quote($_SERVER['REMOTE_ADDR']), $date, $date, $comment_content, $DB->quote('default'), $DB->quote(generate_random_key())));
		}

		return TRUE;
	}

	function updateWebmention($item, $source, $title, $url, $text)
	{
		return $this->saveWebmention($item, $source, $title, $url, $text);
	}
}
?>