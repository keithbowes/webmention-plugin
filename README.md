# Webmention plugin
This is an implementation of Webmention in PHP.  It includes a plugin to add [Webmention](http://www.w3.org/TR/webmention) support to @b2evolution.

## How it functions
It inspects links in posts.  It will then send Webmentions.

When it receives one, it will check for its validity and that the Webmention doesn't already exist for the post and if it validates, then add it to the T_comments table.

If it does already exist, it will update the information in the database.

## Known issues

1. It doesn't support all the MAY and SHOULD conditions of the spec.  In particular:
    * No caching.  I'm unsure how this will be done.  Just through a Last-Modified header (from T\_comments.comment\_last\_touched\_ts), or saving the caching information to an unused column?
    * No synchronous validation.  I'm again not sure how this will be done, as plugins are separate from the main code (i.e. none of the main blog display can be done while the plugin is validating asynchronously).  A possible solution is to use scheduled jobs to allow the blog owner to decide how and when to validate Webmentions. Another is to limit the number of queued Webmentions.
    * No support for the Link HTTP header.
    * No restraints on sources greater than or equal to a megabyte.
1. May not be interoperable.  I can't get the webmention.rocks test suite to work (I just get the message "Not Found", but it doesn't tell me what exactly isn't found).
