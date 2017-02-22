# Webmention plugin
This is a plugin to add [Webmention](http://www.w3.org/TR/webmention) support to @b2evolution. Right now this serves as an implementation guide for me but should shape up into a real readme.

## How it functions
It inspects links in posts.  It will then send webmentions.  My understanding of the exact mechanism involved is still a mystery.

When it receives one, it will check for its validity and that the webmention doesn't already exist for the post and then add it to the T_comments table.

The plugin must send out the right HTTP header for

There should be a black list for sites that you don't want to accept.

## Issues to resolve

* ~~How do we ensure not to send a webmention that's already been sent when editing a post?~~ (addressed in section 3.1.4 of the spec; it's up to the target, not the source to do that)
