=== Fresh From FriendFeed and Twitter ===
Contributors: bob.hitching
Donate link: http://www.nangqiantibetanfoundation.com/
Tags: FriendFeed, Twitter, Flickr, Facebook, Google, LinkedIn, YouTube, fresh, feed, lifestream, posts, plugin
Requires at least: 2.3
Tested up to: 2.8.2
Stable tag: 1.1.8

Keeps your blog always fresh by regularly adding your latest and greatest content from FriendFeed or Twitter. No external passwords required!

== Description ==

Keeps your blog always fresh by regularly adding your latest and greatest content from FriendFeed or Twitter. No external passwords required!

[Download](http://downloads.wordpress.org/plugin/fresh-from-friendfeed-and-twitter.1.1.8.zip) |
[Demo](http://hitching.net) |
[Support](http://friendfeed.com/rooms/fresh-from-friendfeed-and-twitter)

= New in 1.1.8 =

* Now works properly in WordPress 2.8.x

= New in 1.1.7 =

* Admin option to overwrite post title prefix (e.g. to "New From %s" where %s = service)

= New in 1.1.x =

* Mashup multiple feeds of fresh content from all your FriendFeed and Twitter accounts into your blog.
* Also import content from FriendFeed Rooms and FriendFeed Search and (*NEW*) Twitter Search.
* Choose to only import content containing your chosen hashtag e.g. #blog which becomes your mechanism to send your micro-blogging content to your blog.
* Digest summary; per service, per user or all together.
* 'Keep it Fresh' mode - simply show your latest and greatest content, regularly refreshed. Less is more.
* 'Keep it Coming' mode - import content every day and keep it archived in your blog.

= More features =

* Automatically detects your FriendFeed and Twitter username; simple out-of-the-box install!
* No external passwords are required because only public FriendFeed and Twitter APIs are used. 
* Content is imported as regular blog posts which can be easily edited, tagged and kept.
* Content is automatically enhanced in many ways, including Twitpic photos and embedded YouTube videos.

= And there's more... =

* Includes SEO links to your FriendFeed, Twitter, Flickr, Facebook, Google, LinkedIn, YouTube, etc. profile pages.
* A magic 'latest and greatest' formula is used to work out what to import. If you don't want to keep an imported post, simply delete it and Fresh From will automatically find some other content.
* Lots of control over how much content is imported; FriendFeed users can also choose how many posts are imported from each of their FriendFeed services (e.g. Flickr, Facebook, etc.)
* External API limits are well respected, and API responses are cached by Fresh From to protect your site against FriendFeed and Twitter downtime.

Note: Fresh From requires WordPress 2.3 or higher, running on PHP4 or PHP5, with CURL.

== Installation ==

1. Upload the files to wp-content/plugins/fresh-from-friendfeed-and-twitter
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Visit Settings / Fresh From to confirm your FriendFeed or Twitter username
1. That's it!

== Screenshots ==

1. Fresh From enhances imported content in many ways. Here's an embedded YouTube video that has been detected, and a thumbnail showing who I'm talking with on FriendFeed.
2. The Fresh From admin page showing Feeds, Services, Import and Content Enhancement options.

== Frequently Asked Questions ==

Here's the [Support Room](http://friendfeed.com/rooms/fresh-from-friendfeed-and-twitter) showing real-time, warts 'n' all, frequent and infrequent, questions and answers. If you cannot find the answer you need, please ask the question to the Room. Don't be shy.

== Changelog ==

= 1.1.6 =
* New: Added Italian, grazie Gianni Diurno (gidibao.net)
* New: Added Portuguese, thanks Rita
* Optimisation: disabled revisions to speed things up
* Optimisation: disabled kses to stop Wordpress corrupting content grrr

= 1.1.5 =
* Fixed: service limits now saving properly (broken in 1.1.4)

= 1.1.4 =
* Fixed: underscore in twitter username was showing wrong avatar
* Fixed: br/clear after photos

= 1.1.3 =
* Fixed: another CSS glitch; br/clear after photos
* Cleanup interim posts after update
* Allow direct FriendFeed comments to be un/limited

= 1.1.2 =
* Fixed: CSS display glitches when used with other plugins

= 1.1.1 =
* Import FriendFeed Users, Comments, Likes, Rooms, and Search
* Import Twitter Users and Search
* Mashup multiple feeds
* Filter import by keyword e.g. #blog
* Import content as a digest per service, per user or all together
* Keep it Fresh
* Keep it Coming
* Fixed: FriendFeed direct content now imported
* Fixed: busy sites do not suffer from repeat imports
* Fixed: images no longer inserted into titles breaking funky themes
* Works on PHP4 as well as PHP5
* Ready for i18n multi-language support

= 1.0.2 =
* Import stumbleupon images
* New Twitpic thumbnails

= 1.0.1 =
* New Admin setting 'Imported posts are Published / Draft' so you can edit them first
* New Admin setting 'Show service icon in title' which solves the disruption to funky themes 
* New Admin setting 'Show Fresh From posts in my RSS feeds' which solves the FeedBurner issue
* RSS titles now show a couple of words from the content to help distinguish between posts
* Can now show content up to 30 days old (previously 10)
* CURL detection is now more graceful, and lack of CURL is reported on the Admin page
* Import direct FriendFeed comments