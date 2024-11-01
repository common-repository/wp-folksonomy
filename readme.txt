=== WP_Folksonomy ===
Contributors: scottsm
Donate link: http://scott.sherrillmix.com/blog/
Tags: folksonomy, tags, user, submitted, collaborative, tagging, semantic, social
Requires at least: 2.3
Tested up to: 2.5.1
Stable tag: 0.8

This plugin allows your readers to add tags to your posts (like Flickr or del.icio.us).

== Description ==

This plugin allows your readers (either registered users or all) to add tags to your posts. Tags can be held for approval or displayed immediately. An RSS feed for monitoring new tags is provided. Javascript autocompletion is available to help improve tagging consistency. 


== Installation ==

1. Unzip `wp_folksonomy.zip`. 
1. Upload `wp_folksonomy.php` to `wp-content/plugins`.
1. Activate in the Plugins menu. 
1. Unfortunately there is not an easy way for the plugin to add the tag submission form to your theme, so you'll need to open up the .php file for your theme's single posts (probably `single.php` or maybe `index.php`) and find the tag section (look for something like: `<?php the_tags('some','options');?>`). After that insert the following code: `<?php if(function_exists('wp_folksonomy_add_form')) wp_folksonomy_add_form();?>`

== Frequently Asked Questions ==

= Won't this get spammed? =

The truthful answer is I'm not completely sure. I guess there's not much benefit to spammers since tagging doesn't provide a link. My blog (only a small fry) has had it up about a year and hasn't had a problem. However if you do have a problem, you can easily delete all tags from a given IP address. If you have a serious problem, please let me know and I'll work in some sort of blacklist or (annoying) captcha or something. Also you can delete all tags from a given IP and limit users to X tags per Y seconds.


