# Webmention plugin
This is an implementation of Webmention in PHP.  It includes a plugin to add [Webmention](http://www.w3.org/TR/webmention) support to [b2evolution](https://gitlab.com/keithbowes/b2evolution).

## How it functions
It inspects links in posts.  It will then send Webmentions.

When it receives one, it will check for its validity and that the Webmention doesn't already exist for the post and if it validates, then add it by an implementation of `iWebmention::saveWebmention()`.

If it does already exist, it use an implementation of `iWebmention::updateWebmention()`.

## Vouch support

[Vouch](https://indieweb.org/Vouch) is supported but untested.  If you want to test it and find any bugs, report them.  Receiving Webmentions with Vouch is implemented according to the specification.  To send a Webmention with Vouch, use the HTML5+ <var>data-vouch</var> attribute.

## Known issues

1. It doesn't support all the MAY and SHOULD conditions of the spec.  In particular:
    * No caching.  This surely can't be done in the core code, and I have no idea how to go about doing it in b2evolution.
    * No asynchronous validation.  I'm again not sure how this will be done in b2evolution, as plugins are separate from the main code (i.e. none of the main blog display can be done while the plugin is validating asynchronously); a possible solution is to use scheduled jobs to allow the blog owner to decide how and when to validate Webmentions. Another (possibly more portable) solution is to limit the number of queued Webmentions.
    * No support for the Link HTTP header.
    * No constraints on sources greater than or equal to a megabyte.
1. May not be interoperable.  I can't get the webmention.rocks test suite to work (I just get the message "Not Found", but it doesn't tell me what exactly isn't found).

## b2evolution plugin

A b2evolution plugin (\_webmention.plugin.php) is included.  Just install in the admin section of b2evolution.

## Other systems

It should be possible to add support for another system by implementing the interface methods from \_webmention\_interface.php.  \_evo\_webmention.class.php can be used as an example.
