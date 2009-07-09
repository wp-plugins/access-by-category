=== Access By Category ===
Contributors: MaxBlogPress Revived
Tags: category, category access, block category, hide category, user role
Requires at least: 2.0
Tested up to: 2.8
Stable tag: trunk

Allows wordpress administrator to control access to blog categories according to user roles.

== Description ==

This plugin gives administrator a complete control over the categories in a blog. The administrator can:

* select which categories to be hidden for particular user (editor, author, contributor, etc)
* make the user to see only the posts of selected categories
* select category by deafult in the post editor

A user without access to a category needn't even know that it exists. It will not appear in the categories list in the post editor, and its posts will not appear in the blog or anywhere in the dashboard. You can configure it in such a way that,

* a user role can be allowed to read a category but not post to it
* a user role can be allowed to view a post directly from a permalink but not to see it in the blog home page, archive page, category page, etc 
* the posts belonging to a category or more than one category can be entirely hidden from a user role, or be shown if the posts are cross-posted to a category the role can see

Let us consider a blog where a post submitted by an author requires to be reviewed by an editor. In this scenario, the flow could be easily controlled with categories, namely - Uncategorized, Approved and Rejected. The author can see and post only in Uncategorized category, and the editor after reviewing it can categorize it as Rejected or Approved. In such case, Access By Category plugin can be used.

== Installation ==

* Download "Access By Category" plugin.
* Open /wp-content/plugins folder at your web server.
* Upload the folder "access-by-category" there.
* Goto Plugins page in your wordpress back office.
* Activate "Access By Category" plugin.
* Go to Users >> Access By Category for modifying the settings.

Note: This plugin will require one time free registration.

== How to use it ==

* Go to Users >> Access By Category 

Each user role can be selected from the drop-down list. There are four types of view access, with three possible settings each. The available settings are:

* Y - category and posts are visible
* N - category and posts are invisible, except for posts that are also posted to a visible category
* Block - category and posts are invisible, and anything cross-posted to a visible category will be invisible there as well

The four types of view access are:

* Read - Viewing individual complete posts
* List - Inclusion of posts in lists, including category and archive views and searches
* Home - Inclusion of posts in the blog home
* Feed - Inclusion of posts in blog feeds

"Post Into" controls a category's availability for posting and editing. If unchecked for a role, that role's users will not see the category in the post editor, and will also not be allowed to edit posts in that category (unless they are also posted to a category to which the user role has post access).

"Post Default" specifies which category or categories will be checked by default when a user starts a new post.

"Inheritence" causes new subcategories to automatically assume the parent (or ancestor) category's Access By Category settings.

== Change Log ==

= Version 1.0 (07-09-2009) =
* New: First Release

== Screenshots ==

1. "Access By Category" settings for Administrator
2. Post editor page for Administrator
3. "Access By Category" settings for Author
4. Post editor page for Author
