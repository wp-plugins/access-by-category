<?php
/* 
 * Plugin Name:   Access By Category
 * Version:       1.0
 * Plugin URI:    http://wordpress.org/extend/plugins/access-by-category/
 * Description:   Allows wordpress administrator to control access to blog categories according to user roles
 * Author:        MaxBlogPress Revived
 * Author URI:    http://www.maxblogpress.com
 *
 * License:       GNU General Public License
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * 
 * Copyright (C) 2007 www.maxblogpress.com
 *
 * The plugin was previously developed by Joel Rothschild
 *
 */

define('ABC_NAME', 'Access By Category');
define('ABC_VERSION', '1.0');

class accessbycategory {

	function accessbycategory() {	// construction of 'abc' plugin object
		// abc table names
		global $wpdb;
		$this->abc_path = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
		$this->abc_path = str_replace('\\','/',$this->abc_path);
		$this->abc_siteurl = get_bloginfo('wpurl');
		$this->abc_siteurl = (strpos($this->abc_siteurl,'http://') === false) ? get_bloginfo('siteurl') : $this->abc_siteurl;
		$this->categories_access_table = $wpdb->prefix . 'categories_access';
		$this->posts_access_table = $wpdb->prefix . 'posts_access';
		$this->links_access_table = $wpdb->prefix . 'links_access';
		// hook functions
		add_action('activate_'.$this->abc_path, array(&$this, 'install_abc'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('admin_head', array(&$this, 'admin_head'));
		$this->abc_activate = get_option('abc_activate');
		if ( $this->abc_activate == 2 ) {
			add_filter('category_save_pre', array(&$this, 'category_save_pre'));		// this filter is used when publishing a post
			add_action('save_post', array(&$this, 'save_post'));
			add_action('add_category', array(&$this, 'add_category'));
			add_action('delete_category', array(&$this, 'delete_category'));
			add_action('restrict_manage_posts', array(&$this, 'restrict_manage_posts'));
			add_filter('user_has_cap', array(&$this, 'user_has_cap'), 10, 3);
			add_filter('posts_join', array(&$this, 'posts_join'));
			add_filter('posts_where', array(&$this, 'posts_where'));
			add_filter('get_bookmarks', array(&$this, 'get_bookmarks'), 10, 2);
			add_filter('get_categories', array(&$this, 'get_categories'), 10, 2);
		}
	}

	function current_user_role() {
		// returns the current user's role
		// NOTE: accessbycategory currently assumes that each user has exactly one role
		global $current_user;
		$current_user_roles_keys = array_keys($current_user->roles);	// because roles array may not be indexed from 0
		if ( empty($current_user_roles_keys) )	{	// user has no roles (i.e. not logged in)
			return 'not-logged-in';
		} else {  // return first role we find
			return $current_user->roles[ $current_user_roles_keys[0] ];
		}
	}

	/* methods to maintain abc database tables */
	function install_abc() {
		global $wpdb;
		// create categories_access table if it doesn't exist
		if ( $wpdb->get_var("SHOW TABLES LIKE '$this->categories_access_table';") != $this->categories_access_table ) {
			$sql =
				"CREATE TABLE $this->categories_access_table ("
				.	'category_ID BIGINT UNSIGNED NOT NULL, '
				.	'role VARCHAR(127) NOT NULL, '
				.	'inheritence ENUM("Off", "On") NOT NULL, '
				.	'postto_default ENUM("Yes", "No") NOT NULL, '
				.	'postto ENUM("Yes", "No") NOT NULL, '
				.	'read_home ENUM("Yes", "No", "Block") NOT NULL, '
				.	'read_list ENUM("Yes", "No", "Block") NOT NULL, '
				.	'read_feed ENUM("Yes", "No", "Block") NOT NULL, '
				.	'read_single ENUM("Yes", "No", "Block") NOT NULL, '
				. 	'INDEX  (category_ID, role)'
				. ');'
			;
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
			dbDelta($sql);
		}
		// create posts_access table if it doesn't exist
		if ( $wpdb->get_var("SHOW TABLES LIKE '$this->posts_access_table';") != $this->posts_access_table ) {
			$sql =
				"CREATE TABLE $this->posts_access_table ("
				.	'post_ID BIGINT UNSIGNED NOT NULL, '
				.	'role VARCHAR(127) NOT NULL, '
				.	'edit ENUM("Yes", "No") NOT NULL, '
				.	'read_home ENUM("Yes", "No") NOT NULL, '
				.	'read_list ENUM("Yes", "No") NOT NULL, '
				.	'read_feed ENUM("Yes", "No") NOT NULL, '
				.	'read_single ENUM("Yes", "No") NOT NULL, '
				. 	'INDEX  (post_ID, role)'
				. ');'
			;
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
			dbDelta($sql);
		}
		// create links_access table if it doesn't exist
		if ( $wpdb->get_var("SHOW TABLES LIKE '$this->links_access_table';") != $this->links_access_table ) {
			$sql =
				"CREATE TABLE $this->links_access_table ("
				.	'link_ID BIGINT NOT NULL, '			// why exactly is link_id not UNSIGNED?
				.	'role VARCHAR(127) NOT NULL, '
				.	'edit ENUM("Yes", "No") NOT NULL, '
				.	'read_list ENUM("Yes", "No") NOT NULL, '
				. 	'INDEX  (link_ID, role)'
				. ');'
			;
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
			dbDelta($sql);
		}
		// rebuild posts_access table (in case there are old categories_access rules)
		$this->build_posts_access();
		// rebuild links_access table (for the same reason)
		$this->build_links_access();
	}

	function build_posts_access($specific_post_id = NULL) {
		global $wpdb, $wp_version;

		// nix rows to be replaced
		if ( isset($specific_post_id) ) {
			$wpdb->query("DELETE FROM $this->posts_access_table WHERE post_ID=$specific_post_id;");
		} else {	// nix *every* row if we're rebuilding access rules for all posts
			$wpdb->query("DELETE FROM $this->posts_access_table;");
		}

		// look up which roles have categories_access rules
		$sql = "SELECT role FROM $this->categories_access_table GROUP BY role;";
		$ruled_roles = (array) $wpdb->get_col($sql);

		// for each of those roles, derive posts_access rules
		// note: posts_access.edit is derived from categories_access.postto, treating "No" like "Block"
		// because it would generally create a mess to allow editing access to a user who only has
		// posting access in some of the categories where a post has already been placed
		foreach ($ruled_roles as $this_role) {
			if ( $wp_version < 2.3 ) {
				$sql =
					"INSERT INTO $this->posts_access_table SELECT
						$wpdb->post2cat.post_id AS post_ID,
						'$this_role' AS role,
						IF(
							BIT_AND( IF(postto IS NULL OR postto='Yes',1,0) ) = 1,
							'Yes',
							'No'
						) AS edit,
						IF(
							BIT_AND( IF(read_home IS NULL OR read_home='Yes',1,IF(read_home = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_home,
						IF(
							BIT_AND( IF(read_list IS NULL OR read_list='Yes',1,IF(read_list = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_list,
						IF(
							BIT_AND( IF(read_feed IS NULL OR read_feed='Yes',1,IF(read_feed = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_feed,
						IF(
							BIT_AND( IF(read_single IS NULL OR read_single='Yes',1,IF(read_single = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_single
					FROM $wpdb->post2cat
						LEFT JOIN $this->categories_access_table ON (
							$this->categories_access_table.category_ID = $wpdb->post2cat.category_id
							AND role = '$this_role'
						)
					WHERE " . ( isset($specific_post_id) ? "$wpdb->post2cat.post_id = '$specific_post_id'" : '1=1' ) . "
					GROUP BY post_ID;";
			} else {
				$sql =
					"INSERT INTO $this->posts_access_table SELECT
						t1.object_id AS post_ID,
						'$this_role' AS role,
						IF(
							BIT_AND( IF(t2.postto IS NULL OR t2.postto='Yes',1,0) ) = 1,
							'Yes',
							'No'
						) AS edit,
						IF(
							BIT_AND( IF(t2.read_home IS NULL OR t2.read_home='Yes',1,IF(t2.read_home = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_home,
						IF(
							BIT_AND( IF(t2.read_list IS NULL OR t2.read_list='Yes',1,IF(t2.read_list = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_list,
						IF(
							BIT_AND( IF(t2.read_feed IS NULL OR t2.read_feed='Yes',1,IF(t2.read_feed = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_feed,
						IF(
							BIT_AND( IF(t2.read_single IS NULL OR t2.read_single='Yes',1,IF(t2.read_single = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_single
					FROM $wpdb->term_relationships t1 
						LEFT JOIN $this->categories_access_table t2 ON (
							t2.category_ID = t1.term_taxonomy_id
							AND t2.role = '$this_role'
						)
					WHERE " . ( isset($specific_post_id) ? "t1.object_id = '$specific_post_id'" : '1=1' ) . "
					GROUP BY post_ID;";
			}
			$wpdb->query($sql);
		}
		// note: It is necessary to generate posts_access rules for *every* post for each role that has any
		// categories_access rules at all, because it is impossible to predict which posts would be affected by
		// any given categories_access rule. However, for any role with *no* categories_access rules, there
		// is no need for any posts_access rules because all posts are assumed to have no restrictions on access
		// by default.
	}

	function build_links_access($specific_link_id = NULL) {
		global $wpdb, $wp_version;

		// nix rows to be replaced
		if ( isset($specific_link_id) ) {
			$wpdb->query("DELETE FROM $this->links_access_table WHERE link_ID=$specific_link_id;");
		} else {	// nix *every* row if we're rebuilding access rules for all links
			$wpdb->query("DELETE FROM $this->links_access_table;");
		}

		// look up which roles have categories_access rules
		$sql = "SELECT role FROM $this->categories_access_table GROUP BY role;";
		$ruled_roles = (array) $wpdb->get_col($sql);

		// for each of those roles, derive links_access rules (see notes under function build_posts_access)
		foreach ($ruled_roles as $this_role) {
			if ( $wp_version < 2.3 ) {
				$sql =
					"INSERT INTO $this->links_access_table SELECT
						$wpdb->link2cat.link_id AS link_ID,
						'$this_role' AS role,
						IF(
							BIT_AND( IF(postto IS NULL OR postto='Yes',1,0) ) = 1,
							'Yes',
							'No'
						) AS edit,
						IF(
							BIT_AND( IF(read_list IS NULL OR read_list='Yes',1,IF(read_list = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_list
					 FROM $wpdb->link2cat
						LEFT JOIN $this->categories_access_table ON (
							$this->categories_access_table.category_ID = $wpdb->link2cat.category_id
							AND role = '$this_role'
						)
					 WHERE " . ( isset($specific_link_id) ? "$wpdb->link2cat.post_id = '$specific_link_id'" : '1=1' ) . "
					 GROUP BY link_ID;";
			} else {
				$sql =
					"INSERT INTO $this->links_access_table SELECT
						t1.object_id AS link_ID,
						'$this_role' AS role,
						IF(
							BIT_AND( IF(t2.postto IS NULL OR t2.postto='Yes',1,0) ) = 1,
							'Yes',
							'No'
						) AS edit,
						IF(
							BIT_AND( IF(t2.read_list IS NULL OR t2.read_list='Yes',1,IF(t2.read_list = 'Block',0,NULL)) ) = 1,
							'Yes',
							'No'
						) AS read_list
					 FROM $wpdb->term_relationships t1 
						LEFT JOIN $this->categories_access_table t2 ON (
							t2.category_ID = t1.term_taxonomy_id 
							AND t2.role = '$this_role'
						)
					 WHERE " . ( isset($specific_link_id) ? "t1.post_id = '$specific_link_id'" : '1=1' ) . "
					 GROUP BY link_ID;";
			}
			$wpdb->query($sql);
		}
	}

	function endow_category_access($category_id) {
		// adds categories_access rules for a (new) category, if it has an ancestor with inheritable rules

		global $wpdb;
		$ancestor_ids = $this->category_ancestors($category_id);
		$roles_ruled = array();	// A category may inherit different rules for different roles from different ancestors!
							// BUT, it may only have one set of rules for any given role
		$inheritable_rules = array();
		$categories_access_inheritable = $wpdb->get_results(
			"SELECT * FROM $this->categories_access_table WHERE inheritence='On' ORDER BY category_ID, role"
		);
		foreach ($categories_access_inheritable as $inheritable_rule) {
			if ( !isset($inheritable_rules[$inheritable_rule->category_ID]) )
				$inheritable_rules[$inheritable_rule->category_ID] = array()	// does PHP actually require me to do this? perhaps not, but I miss Perl anyway
			;
			$inheritable_rules[$inheritable_rule->category_ID][] = $inheritable_rule;
		}
		foreach ($ancestor_ids as $ancestor_id) {
			if ( is_array($inheritable_rules[$ancestor_id]) ) {	// this ancestor can be inherited from
				foreach ($inheritable_rules[$ancestor_id] as $ancestor_category_access) {	// foreach inheritable rule of this ancestor category
					if ( !isset($roles_ruled[$ancestor_category_access->role]) ) {	// this role doesn't already have inherited rules
						$sql = 
							"INSERT INTO $this->categories_access_table SET 
								category_ID = '$category_id',
								role = '$ancestor_category_access->role',
								inheritence = 'Off',
								postto_default = 'No',
								postto = '$ancestor_category_access->postto',
								read_home = '$ancestor_category_access->read_home',
								read_list = '$ancestor_category_access->read_list',
								read_feed = '$ancestor_category_access->read_feed',
								read_single = '$ancestor_category_access->read_single'
							;"
						;
						$wpdb->query($sql);
						$roles_ruled[$ancestor_category_access->role] = 1;	// just one inherited rule per role, thank you
					}
				}
			}
		}
	}
	function category_ancestors($category_id) {
		// recursive function to gather all ancestor category_ids in reverse hierarchical order
		$category_object = get_category($category_id);
		$category_parent_id = $category_object->category_parent;
		if ( $category_parent_id > 0 ) {
			return array_merge( array($category_parent_id), $this->category_ancestors($category_parent_id) );
		} else {
			return array();
		}
	}

	function destroy_category_access($category_id) {
		// remove all categories_access rules for given category_id, and rebuild posts_access
		// note: we can't be selective about rebuilding posts_access, because the only hook 
		// for category deletion comes after post2cat has been updated
		global $wpdb;
		$sql = "DELETE FROM $this->categories_access_table WHERE category_ID='$category_id';";
		$wpdb->query($sql);
		// even if there were no access rules for this category_id, it is possible for posts_access
		// rules to have changed, so we have to rebuild them in any case (same for links_access)
		$this->build_posts_access();
		$this->build_links_access();
	}
	/* end methods to maintain abc database tables */

	/* methods to retrieve access rules */
	/*function load_auto_tree_access() {
		// load options for automating access granting to new categories according to their ancestry
		$abc_auto_tree_access = get_option('abc_auto_tree_access');
		$this->auto_tree_access = ( isset($abc_auto_tree_access) ? $abc_auto_tree_access : array() );
	}*/

	function load_categories_access() {
		// load categories_access rules for the current user's role
		if ( isset($this->categories_access) )	// this only needs to be done once
			return
		;
		global $wpdb;
		$role = $this->current_user_role();
		$categories_access = array();
		$sql = "SELECT * FROM $this->categories_access_table WHERE role='$role';";
		$r = $wpdb->get_results($sql);
		foreach ($r as $record) {
			$categories_access[$record->category_ID] = $record;
		}
		$this->categories_access = $categories_access;
	}
	
	function load_posts_access() {
		// load posts_access rules for the current user's role
		if ( isset($this->posts_access) )	// this only needs to be done once
			return
		;
		global $wpdb;
		$role = $this->current_user_role();
		$posts_access = array();
		$sql = "SELECT * FROM $this->posts_access_table WHERE role='$role';";
		$r = $wpdb->get_results($sql);
		foreach ($r as $record) {
			$posts_access[$record->post_ID] = $record;
		}
		$this->posts_access = $posts_access;
	}
	
	function load_links_access() {
		// load links_access rules for the current user's role
		if ( isset($this->links_access) )	// this only needs to be done once
			return
		;
		global $wpdb;
		$role = $this->current_user_role();
		$links_access = array();
		$sql = "SELECT * FROM $this->links_access_table WHERE role='$role';";
		$r = $wpdb->get_results($sql);
		foreach ($r as $record) {
			$links_access[$record->link_ID] = $record;
		}
		$this->links_access = $links_access;
	}
	
	function load_comments_access() {
		// load comments_access rules for the current user's role
		if ( isset($this->comments_access) )	// this only needs to be done once
			return
		;
		global $wpdb;
		$role = $this->current_user_role();
		$comments_access = array();
		$sql = 
			"SELECT * FROM $wpdb->comments 
				LEFT JOIN $this->posts_access_table ON $this->posts_access_table.post_ID=$wpdb->comments.comment_post_ID
			 WHERE $this->posts_access_table.role='$role';"
		;
		$r = $wpdb->get_results($sql);
		foreach ($r as $record) {
			$comments_access[$record->comment_ID] = $record;
		}
		$this->comments_access = $comments_access;
	}
	
	function get_postto_disallowed() {
		$this->load_categories_access();	// first, make sure categories access rules have been loaded
		$postto_disallowed = array();
		foreach ($this->categories_access as $category_access) {
			if ( 'No' == $category_access->postto )
				$postto_disallowed[] = $category_access->category_ID
			;
		}
		return $postto_disallowed;
	}
	
	function load_postto_allowed() {
		global $wpdb;
		//$all_category_IDs = (array) $wpdb->get_col("SELECT cat_ID FROM $wpdb->categories GROUP BY cat_ID;");
		//$this->postto_allowed = array_diff( $all_category_IDs, $this->get_postto_disallowed() );
		$this->postto_allowed = array_diff( get_all_category_ids(), $this->get_postto_disallowed() );
	}
	
	function get_postto_defaults() {
		// return an array of any categories that should be checked by default
		// when this user starts a new post

		$this->load_categories_access();	// first, make sure categories access rules have been loaded
		$postto_defaults = array();
		foreach ($this->categories_access as $category_access) {
			if ( 'Yes' == $category_access->postto_default )
				$postto_defaults[] = $category_access->category_ID
			;
		}
		return $postto_defaults;
	}
	/* end methods to retrieve access rules */

	/* methods for hooks (actions and filters) */
	function admin_head($unused) {
		// because WP lacks hooks for filtering category lists in administrative pages,
		// we have to buffer the entire page with a callback to search for the categories
		// list and replace it with a filtered list
		// buffer this admin page and invoke the appropriate callback
		if(preg_match('#/wp-admin/(post(-new)?)|(link(-add)?).php#i', $_SERVER['REQUEST_URI'])) {
			// this page is a post or link editor
			ob_start(array(&$this, 'ob_callback_editor'));
		} elseif (preg_match('|/wp-admin/link-manager.php|i', $_SERVER['REQUEST_URI'])) {
			// this page is Blogroll->Manage Blogroll
			ob_start(array(&$this, 'ob_callback_link_manager'));
		} elseif (
			preg_match('|/wp-admin/index.php|i', $_SERVER['REQUEST_URI'])
			|| preg_match('|/wp-admin/$|i', $_SERVER['REQUEST_URI'])
		) {
			// this page is Dashboard
			ob_start(array(&$this, 'ob_callback_dashboard'));
		} elseif (preg_match('|/wp-admin/edit-comments.php\?mode=edit|i', $_SERVER['REQUEST_URI'])) {
			// this page is comment mass edit
			ob_start(array(&$this, 'ob_callback_comment_mass_edit'));
		} elseif (preg_match('|/wp-admin/edit-comments.php(\?mode=view)?|i', $_SERVER['REQUEST_URI'])) {
			// this page is comment view 
			ob_start(array(&$this, 'ob_callback_comment_view'));
		}
		// output special style rules for admin panel
		if(preg_match('#/wp-admin/users.php\?page='.$this->abc_path.'#i', $_SERVER['REQUEST_URI'])) {
			$this->admin_page_style();
		}
	}

	function admin_menu() {
		add_submenu_page('users.php', __('Access By Categories'), __('Access By Category'), 'edit_users', $this->plugin_basename(), array(&$this, 'admin_page') );
	}

	function category_save_pre($categories_checked) {
		// filter checked categories when a post is saved
		if( !is_array($categories_checked) )
			$categories_checked = array();
		;
		$categories_checked = array_diff( $categories_checked, $this->get_postto_disallowed() );
		if ( count($categories_checked) > 0 ) {
			return($categories_checked);
		} else {
			return( $this->get_postto_defaults() );
		}
	}

	function save_post($post_id) {
		// post has been created or updated, so we need to (re-)build posts_access rules for its post_id
		$this->build_posts_access($post_id);
		return($post_id);
	}

	function add_category($category_id) {
		// new category has been created, so we need to check for categories_access
		// rules it may automatically inherit from an ancestor
		$this->endow_category_access($category_id);
		return($category_id);
	}

	function delete_category($category_id) {
		// category has been removed, so we need to update access rules accordingly
		$this->destroy_category_access($category_id);
		return($category_id);
	}

	function restrict_manage_posts($unused = NULL) {
		// screen out posts from Manage->Posts
		global $posts;
		$this->load_posts_access();
		$posts = is_array($posts) ? array_values($posts) : array();
		for ($i = 0; $i < count($posts); $i++) {
			if (
				isset( $this->posts_access[ $posts[$i]->ID ] )
				&&  $this->posts_access[ $posts[$i]->ID ]->read_list != 'Yes'
			) {						// this post is not supposed to be listed for this user
				array_splice($posts, $i, 1);	// so hide it
				$i--;	// set index back, now that array has been spliced
			}
		}
		return($unused);
	}

	function user_has_cap() {
		// give specific answers to user-capability questions with respect to post/category access
		$role = $this->current_user_role();
		list($caps_user_has, $caps_user_needs, $args) = func_get_args();

		if ( 'edit_post' == $args[0]  ||  'delete_post' == $args[0] ) {	// Q: can current user edit or delete a particular post?
			$this->load_posts_access();	// make sure posts_access rules are loaded
			$post_id_in_question = $args[2];
			if (
				isset($this->posts_access[$post_id_in_question])
				&&  'Yes' != $this->posts_access[$post_id_in_question]->edit
			) {	  // this user is not permitted to edit the post in question
				return array();	 // so disempower the user (strip all capabilities) in this case
			}

		} elseif ( 'read_post' == $args[0] ) {	// Q: can current user read a particular post?
			$this->load_posts_access();			// make sure posts_access rules are loaded
			$post_id_in_question = $args[2];
			if (
				isset($this->posts_access[$post_id_in_question])
				&&  'Yes' != $this->posts_access[$post_id_in_question]->read_single
			) {	  // this user is not permitted to read the post in question
				return array();	 // so disempower the user (strip all capabilities) in this case
			}

		} elseif ( 'read_category' == $args[0] ) {	// Q: can current user read from a particular category?
			$this->load_categories_access();		// make sure categories_access rules are loaded
			$category_id_in_question = $args[2];
			if (
				!isset($this->categories_access[$category_id_in_question])
				||  'Yes' == $this->categories_access[$category_id_in_question]->read_list
			) {		// this user *is* permitted to list the category in question
				$caps_user_has['read_category'] = 1;	// so add 'read_category' to user's capabilities
			}

		} elseif ( 'post_to_category' == $args[0] ) {	// Q: can current user post to a particular category?
			$this->load_categories_access();			// make sure categories_access rules are loaded
			$category_id_in_question = $args[2];
			if (
				!isset($this->categories_access[$category_id_in_question])
				||  'Yes' == $this->categories_access[$category_id_in_question]->postto
			) {		// this user *is* permitted to post to the category in question
				$caps_user_has['post_to_category'] = 1;		// so add 'post_to_category' to user's capabilities
			}
		}

		return $caps_user_has;
	}

	function posts_join($sql_join) {
		// when querying posts, we need to join in posts_access rules
		global $wpdb, $pagenow;
		$role = $this->current_user_role();
		if ( 'edit.php' == $pagenow  ||  'edit-pages.php' == $pagenow  ||  'upload.php' == $pagenow )
			return $sql_join	// we don't mess with the query for admin pages
		;
		$sql_join .= " LEFT JOIN $this->posts_access_table ON ($this->posts_access_table.post_ID=$wpdb->posts.ID AND $this->posts_access_table.role='$role')";
		return $sql_join;
	}

	function posts_where($sql_where) {
		// filter out access-denied posts from query
		global $pagenow;
		if ( 'edit.php' == $pagenow  ||  'edit-pages.php' == $pagenow  ||  'upload.php' == $pagenow )
			return $sql_where	// we don't mess with the query for admin pages
		;
		$sql_where_common .= "$this->posts_access_table.post_ID IS NULL";
		$sql_where_staticpage .= "post_status='static'";
		if ( is_archive() || is_search() ) {
			$sql_where .= " AND ($this->posts_access_table.read_list='Yes' OR $sql_where_common OR $sql_where_staticpage)";
		} elseif ( is_single() ) {
			$sql_where .= " AND ($this->posts_access_table.read_single='Yes' OR $sql_where_common OR $sql_where_staticpage)";
		} elseif ( is_feed() ) {
			$sql_where .= " AND ($this->posts_access_table.read_feed='Yes' OR $sql_where_common)";
		} elseif ( is_home() ) {
			$sql_where .= " AND ($this->posts_access_table.read_home='Yes' OR $sql_where_common OR $sql_where_staticpage)";
		}
		return $sql_where;
	}

	function get_bookmarks($bookmarks, $display_options) {
		// filter bookmarks list by links_access rules for this user
		if ( !is_array($bookmarks) )
			return $bookmarks
		;
		$this->load_links_access();		// make sure links_access rules are loaded
		$bookmarks = array_values($bookmarks);	// reset numerical keys
		for ($i = 0; $i < count($bookmarks); $i++) {
			$link_id = $bookmarks[$i]->link_id;
			if (
				isset( $this->links_access[$link_id] )
				&&  $this->links_access[$link_id]->read_list != 'Yes'
			) {		// this user is not supposed to see this link/bookmark
				array_splice($bookmarks, $i, 1);	// so remove it
				$i--;	// set index back, now that array has been spliced
			}
		}
		return $bookmarks;
	}

	function get_categories($categories, $options) {
		// filter categories listby categories_access rules for this user
		if ( !is_array($categories) )
			return $categories
		;
		global $pagenow;
		$this->load_categories_access();	// make sure categories_access rules are loaded
		$categories = array_values($categories);	// reset numerical keys
		for ($i = 0; $i < count($categories); $i++) {
			$category_id = $categories[$i]->cat_ID;
			$category_access = $this->categories_access[$category_id];
			if (
				isset($category_access)  &&  (
					( 'link-import.php' == $pagenow  &&  'Yes' != $category_access->postto )	// for link-import page we're concerned with which categories can be posted to
					||  'Yes' != $category_access->read_list
				)
			) {		// this user is not supposed to see this category
				array_splice($categories, $i, 1);	// so remove it
				$i--;	// set index back, now that array has been spliced
			}
		}
		return $categories;
	}
	/* end methods for hooks (actions and filters) */

	/* methods for post/link editor */
	function ob_callback_editor($content) {
		$content = preg_replace_callback('/<ul id="categorychecklist"[^>]*>.*?<\/ul>/si', array(&$this, 'return_categorychecklist'), $content);
		return $content;
	}
	function return_categorychecklist($catlist_html_matches) {
		$original_catlist_html = $catlist_html_matches[0];
		$this->load_categories_access();
		$this->load_postto_allowed();

		// determine which category/ies should be checked
		$checked_category_IDs = null;
		preg_match_all(	// look for IDs of already-checked categories
			'/<input[^>]+value="(\d+)"[^>]+checked/si',
			$original_catlist_html,
			$checked_matches,
			PREG_PATTERN_ORDER
		);
		if ( count($checked_matches[0]) > 0 )	// at least one is already checked
			$this->checked_category_IDs = $checked_matches[1]	// so we preserve the current selections
		;
		if ( !isset($this->checked_category_IDs) ) {	// categories aren't already checked, so we look for defaults
			if ( isset($_GET['post_into_cat']) ) {
				$this->checked_category_IDs = array($_GET['post_into_cat']);	// this allows the category default to be overridden via the URL querystring
			} else {
				$this->checked_category_IDs = $this->get_postto_defaults();
			}
		}

		$output = '<ul id="categorychecklist">';
		/*$output .= $this->output_nested_categories(
			preg_match('#/wp-admin/link(-add)?.php#i', $_SERVER['REQUEST_URI']) ?	// post or link editor?
			get_nested_link_categories() :	// link editor
			$this->abc_get_nested_categories()		// post editor
		);*/
		$output .= $this->output_nested_categories($this->abc_get_nested_categories());
		$output .= '</ul>';
		
		return $output;
	}
	
	function abc_get_nested_categories( $default = 0, $parent = 0 ) {
		global $post_ID, $mode, $wpdb, $checked_categories;
		if ( empty($checked_categories) ) {
			if ( $post_ID ) {
				$checked_categories = wp_get_post_categories($post_ID);
	
				if ( count( $checked_categories ) == 0 ) {
					// No selected categories, strange
				$checked_categories[] = $default;
				}
			} else {
				$checked_categories[] = $default;
			}
		}
		$cats = $this->abc_get_categories("parent=$parent&hide_empty=0&fields=ids");
		
		$result = array ();
		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat) {
				$result[$cat]['children'] = $this->abc_get_nested_categories( $default, $cat);
				$result[$cat]['cat_ID'] = $cat;
				$result[$cat]['checked'] = in_array( $cat, $checked_categories );
				$result[$cat]['cat_name'] = get_the_category_by_ID( $cat);
			}
		}
	
		$result = apply_filters('abc_get_nested_categories', $result);
		usort( $result, 'abc_sort_cats' );
		return $result;
	}
	
	function &abc_get_categories($args = '') {
		$defaults = array('type' => 'category');
		$args = wp_parse_args($args, $defaults);
	
		$taxonomy = 'category';
		if ( 'link' == $args['type'] )
			$taxonomy = 'link_category';
		$categories = get_terms($taxonomy, $args);
	
		foreach ( array_keys($categories) as $k )
			_make_cat_compat($categories[$k]);
	
		return $categories;
	}
	
	function abc_sort_cats( $cat1, $cat2 ) {
		if ( $cat1['checked'] || $cat2['checked'] )
			return ( $cat1['checked'] && !$cat2['checked'] ) ? -1 : 1;
		else
			return strcasecmp( $cat1['cat_name'], $cat2['cat_name'] );
	}
	
	function output_nested_categories($categories) {
		// adapted from wp-admin/admin-functions.php::write_nested_categories 
		// and limitcats->return_catlist

		$output = '';

		foreach ( $categories as $category ) {
			$show_hierarchy = false;	// we won't reveal a parent-child(ren) relationship
							// if we show the child(ren) and not the parent
			if ( in_array($category['cat_ID'], $this->postto_allowed) ) {
				$show_hierarchy = true;
				$category['checked'] = in_array($category['cat_ID'], $this->checked_category_IDs); // override WP's selections (for which categories are checked) with ours
				$output .= '<li id="category-' . $category['cat_ID'] . '"><label for="in-category-' . $category['cat_ID'] . '" class="selectit"><input value="' . $category['cat_ID'] . '" type="checkbox" name="post_category[]" id="in-category-' . $category['cat_ID'] . '"' . ($category['checked'] ? ' checked="checked"' : "" ) . '/> ' . wp_specialchars( $category['cat_name'] ) . "</label></li>";
				// TODO: isn't it invalid XHTML to close the <li> right before opening a new <ul>?
			}

			// if this category has children then recurse this function to see if we should show them
			// (regardless of whether this, their parent, category was shown)
			if ( $category['children'] ) {
				$children_output = $this->output_nested_categories( $category['children'] );
				if ( strlen($children_output) > 0 ) {	// at least one child/ancestor was shown
					if ($show_hierarchy) {		// ...and so was this category (the parent)
						$output .= "<ul>\n$children_output</ul>\n";
					} else {				// ...but this category (the parent) was not
						$output .= $children_output;
					}
				}
			}
		}

		return $output;
	}
	/* end methods for post/link editor */

	/* methods for link manager */
	// note: filtering lists at the html level this way can make pagination funky, but
	// without any hooks to filter the lists or queries in wp-admin/link-manager.php
	// it's the only method I can think of
	function ob_callback_link_manager($content) {
		$this->load_links_access();
		// weed out edit/delete controls
		$content = preg_replace_callback('#<a href="[^"]*link.php\?link_id=(\d+).*?</a>#si', array(&$this, 'return_link_action'), $content);
		// weed out delete checkboxes
		$content = preg_replace_callback('#<input type="checkbox"[^>]+value="(\d+)"\s*/>#si', array(&$this, 'return_link_action'), $content);
		return $content;
	}
	function return_link_action($link_action_html_matches) {
		// preg_replace callback to return html for a link admin action ('Edit' or 'Delete')
		// or nothing for a link that this user can't read
		$link_action_html = $link_action_html_matches[0];
		$link_id = $link_action_html_matches[1];
		if ( !isset( $this->links_access[$link_id] )  ||  'Yes' == $this->links_access[$link_id]->edit ) {
			return $link_action_html;
		} else {
			return '';
		}
	}
	/* end methods for link manager */

	/* methods for comment view and mess edit */
	// note: filtering lists at the html level this way can make pagination funky, but
	// without any hooks to filter the lists or queries in wp-admin/edit-comments.php
	// it's the only method I can think of
	function ob_callback_comment_view($content) {
		$this->load_comments_access();
		// weed out no-access comments
		$content = preg_replace_callback("#<li id='comment-(\d+)'.*?</li>#si", array(&$this, 'return_comment_admin'), $content);
		// fix class alternation on those that remain
		$this->alternate = TRUE;
		$content = preg_replace_callback("/(<li id='comment-\d+' class=')([^\']*)/si", array(&$this, 'return_comment_alternation'), $content);
		return $content;
	}
	function ob_callback_comment_mass_edit($content) {
		$this->load_comments_access();
		// weed out no-access comments
		$content = preg_replace_callback('#<tr id="comment-(\d+)".*?</tr>#si', array(&$this, 'return_comment_admin'), $content);
		// fix class alternation on those that remain
		$this->alternate = TRUE;
		$content = preg_replace_callback("/(<tr id=\"comment-\d+\" class=')([^\']*)/si", array(&$this, 'return_comment_alternation'), $content);
		return $content;
	}
	function return_comment_admin($comment_html_matches) {
		// preg_replace callback to return html for a comment to a post that this user can read
		// or return nothing for a comment to a post that this user *can't* read
		$comment_html = $comment_html_matches[0];
		$comment_id = $comment_html_matches[1];
		if ( !isset( $this->comments_access[$comment_id] )  ||  'Yes' == $this->comments_access[$comment_id]->read_single ) {
			return $comment_html;
		} else {
			return '';
		}
	}
	function return_comment_alternation($comment_html_matches) {
		// preg_replace callback to return beginning of comment's <li..> opening tag
		// with class alternation set correctly
		$li_start = $comment_html_matches[1];
		$li_class = $comment_html_matches[2];
		$li_class = preg_replace('/\s*alternate/i', '', $li_class);
		if ($this->alternate) {
			$li_class .= ' alternate';
			$this->alternate = FALSE;
		} else {
			$this->alternate = TRUE;
		}
		return $li_start . $li_class;
	}
	/* end methods for comment view and mass edit */

	/* methods for Dashboard */
	// note: filtering lists at the html level this way can make pagination funky, but
	// without hooks to filter the lists or queries in wp-admin/index.php it's
	// the only method I can think of
	function ob_callback_dashboard($content) {
		$this->load_posts_access();
		$this->load_comments_access();
		// weed out no-access comments
		$content = preg_replace_callback('@<li>[^<]*<a href="[^"]+#comment-(\d+).+?</li>@si', array(&$this, 'return_comment_admin'), $content);
		// weed out no-access posts
		$content = preg_replace_callback("@<li><a href='[^']+post=(\d+).+?</li>@si", array(&$this, 'return_post_admin'), $content);
		return $content;
	}
	function return_post_admin($post_html_matches) {
		// preg_replace callback to return html for a post that this user can read
		// or return nothing for a post that this user *can't* read
		$post_html = $post_html_matches[0];
		$post_id = $post_html_matches[1];
		if ( !isset( $this->posts_access[$post_id] )  ||  'Yes' == $this->posts_access[$post_id]->read_list ) {
			return $post_html;
		} else {
			return '';
		}
	}
	/* end methods for Dashboard */

	/* methods for admin configuration page */
	function plugin_basename() {
		$name = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
		return str_replace('\\', '/', $name);
	}

	function admin_page() {
		global $wpdb, $wp_roles, $wp_version;

		$reg_msg = '';
		$abc_msg = '';
		
		$form_1 = 'abc_reg_form_1';
		$form_2 = 'abc_reg_form_2';
		// Activate the plugin if email already on list
		if ( trim($_GET['mbp_onlist']) == 1 ) {
			$this->abc_activate = 2;
			update_option('abc_activate', $this->abc_activate);
			$reg_msg = 'Thank you for registering the plugin. It has been activated'; 
		} 
		// If registration form is successfully submitted
		if ( ((trim($_GET['submit']) != '' && trim($_GET['from']) != '') || trim($_GET['submit_again']) != '') && $this->abc_activate != 2 ) { 
			update_option('abc_name', $_GET['name']);
			update_option('abc_email', $_GET['from']);
			$this->abc_activate = 1;
			update_option('abc_activate', $this->abc_activate);
		}
		if ( intval($this->abc_activate) == 0 ) { // First step of plugin registration
			global $userdata;
			$this->abcRegisterStep1($form_1,$userdata);
		} else if ( intval($this->abc_activate) == 1 ) { // Second step of plugin registration
			$name  = get_option('abc_name');
			$email = get_option('abc_email');
			$this->abcRegisterStep2($form_2,$name,$email);
		} else if ( intval($this->abc_activate) == 2 ) { // Options page

			$all_roles = $wp_roles->get_names();
			asort($all_roles);
			$all_roles['not-logged-in'] = 'Guest (Not Logged In)';	// add the virtual "role" for not-logged-in visitors
	
			if($_POST['config'] == 2) {
				echo "<div class=\"updated fade\" id=\"abc_update_notice\"><p>" . __("Configuration <strong>updated</strong>.") . "</p></div>";
				$this->update_configuration();
			}
			?>
			<div class="wrap">
				<h2><?php echo ABC_NAME.' '.ABC_VERSION; ?></h2>
				<form id="abc_config" method="post">
					<?php if ( function_exists('wp_nonce_field') )		// nonce security (see http://markjaquith.wordpress.com/2006/06/02/wordpress-203-nonces/)
						wp_nonce_field('accessbycategory-update-options');
					?>
					<select name="select" class="abc_dhtml" id="abc_selector" onchange="switch_role_view(this);">
                      <?php foreach( array_keys($all_roles) as $this_role ) { ?>
                      <option value="<?php echo $this_role; ?>"><?php echo $all_roles[$this_role]; ?></option>
                      <?php } // end foreach ?>
                    </select>
					<?php

			foreach( array_keys($all_roles) as $this_role ) {
				// load all categories_access rules for this role, including those implied by omission
				if ( $wp_version < 2.3 ) {
					$sql =
						"SELECT
							cat_ID,
							cat_name,
							category_parent,
							role,
							IF(inheritence IS NULL, 'Off', inheritence) AS inheritence,
							IF(postto_default IS NULL, 'No', postto_default) AS postto_default,
							IF(postto IS NULL, 'Yes', postto) AS postto,
							IF(read_home IS NULL, 'Yes', read_home) AS read_home,
							IF(read_list IS NULL, 'Yes', read_list) AS read_list,
							IF(read_feed IS NULL, 'Yes', read_feed) AS read_feed,
							IF(read_single IS NULL, 'Yes', read_single) AS read_single
						 FROM $wpdb->categories
							LEFT JOIN $this->categories_access_table ON (
								$this->categories_access_table.category_ID=$wpdb->categories.cat_ID
								AND $this->categories_access_table.role='$this_role'
							)
						 GROUP BY cat_ID
						 ORDER BY cat_ID;"
					;
				} else {
					$sql =
						"SELECT
							t1.term_id AS cat_ID,
							t1.name AS cat_name,
							t2.parent AS category_parent,
							t3.role AS role,
							IF(t3.inheritence IS NULL, 'Off', t3.inheritence) AS inheritence,
							IF(t3.postto_default IS NULL, 'No', t3.postto_default) AS postto_default,
							IF(t3.postto IS NULL, 'Yes', t3.postto) AS postto,
							IF(t3.read_home IS NULL, 'Yes', t3.read_home) AS read_home,
							IF(t3.read_list IS NULL, 'Yes', t3.read_list) AS read_list,
							IF(t3.read_feed IS NULL, 'Yes', t3.read_feed) AS read_feed,
							IF(t3.read_single IS NULL, 'Yes', t3.read_single) AS read_single
						 FROM $wpdb->terms t1
							INNER JOIN $wpdb->term_taxonomy t2 ON t1.term_id=t2.term_id 
							LEFT JOIN $this->categories_access_table t3 ON (
								t3.category_ID=t1.term_id 
								AND t3.role='$this_role'
							)
						 WHERE t2.taxonomy = 'category' 
						 GROUP BY t1.term_id
						 ORDER BY t1.term_id;"
					;
				}
				$records = $wpdb->get_results($sql);
				$this->alternation = TRUE;
				// determine role's category-general capabilities
				$this_role_object = $wp_roles->get_role($this_role);
				if ( isset($this_role_object) ) {
					$this->role_can_read = $this_role_object->has_cap('read');
					$this->role_can_publish = $this_role_object->has_cap('publish_posts');
				} else {	// user has no role (i.e. not logged in)
					// I am hardcoding these two settings because I can't think off any situation
					// where they'd be set differently; but am I correct in that assumption?
					$this->role_can_read = true;
					$this->role_can_publish = false;
				}
			    ?>
				<fieldset class="abc_dhtml_hide" id="fieldset-<?php echo $this_role; ?>" style="clear:both;border-bottom:1px solid #cccccc;margin-bottom:10px;">
				<legend><h3 class="abc_dhtml_hide"><?php echo $all_roles[$this_role]; ?></h3></legend>
				<table class="abc_settings" cellspacing="2" cellpadding="0">
					<tr>
						<th><abbr title="Category's databse ID">ID</abbr></th>
						<th>Category Name</th>
						<th colspan="3"><abbr title="Allowed to read single posts in category">Read</abbr></th>
						<th colspan="3"><abbr title="Allowed to see category and its posts in lists">List</abbr></th>
						<th colspan="3"><abbr title="Allowed to see category's posts on home page and most-recent listings">Home</abbr></th>
						<th colspan="3"><abbr title="Allowed to see category's posts in syndication">Feed</abbr></th>
						<th><abbr title="Allowed to post into">Post Into</abbr></th>
						<th><abbr title="Default category for new posts/links">Post Default</abbr></th>
						<th><abbr title="New child categories automatically inherit these settings (except for Post Default)">Inheritence</abbr></th>
					</tr>
					<tr>
						<th></th>
						<th></th>
						<th class="compact"><abbr title="Allowed">Y</abbr></th><th class="compact"><abbr title="Not Allowed">N</abbr></th><th class="compact"><abbr title="Disallowed (not allowed even if allowed by another category)">Block</abbr></th>
						<th class="compact"><abbr title="Allowed">Y</abbr></th><th class="compact"><abbr title="Not Allowed">N</abbr></th><th class="compact"><abbr title="Disallowed (not allowed even if allowed by another category)">Block</abbr></th>
						<th class="compact"><abbr title="Allowed">Y</abbr></th><th class="compact"><abbr title="Not Allowed">N</abbr></th><th class="compact"><abbr title="Disallowed (not allowed even if allowed by another category)">Block</abbr></th>
						<th class="compact"><abbr title="Allowed">Y</abbr></th><th class="compact"><abbr title="Not Allowed">N</abbr></th><th class="compact"><abbr title="Disallowed (not allowed even if allowed by another category)">Block</abbr></th>
						<th></th>
						<th></th>
						<th></th>
					</tr>
					<?php $this->admin_page_listcats($this_role, $records); ?>
				</table>
				</fieldset>
			<?php
			} // end foreach
	
			// set default display state for DHTML, then sew up the configuration panel
				?>
					<script type="text/javascript">
					<!--
						abc_role_shown = document.getElementById('abc_selector').value;
						setElementStyleById('fieldset-' + abc_role_shown, 'display', 'block');
	
						function switch_role_view(role_selector) {
							setElementStyleById('fieldset-' + abc_role_shown, 'display', 'none');
							abc_role_shown = role_selector.value;
							setElementStyleById('fieldset-' + abc_role_shown, 'display', 'block');
						}
					// -->
					</script>
	
					<div style="text-align:center">
						<input type="Submit" name="Submit" value="&nbsp;&nbsp;&nbsp; Update &nbsp;&nbsp;&nbsp;" class="button" />
					</div>
					<input type="hidden" name="config" value="2" />
				</form>
				<p style="text-align:center;margin-top:3em;"><strong><?php echo ABC_NAME.' '.ABC_VERSION; ?> by <a href="http://www.maxblogpress.com/" target="_blank" >MaxBlogPress</a></strong></p>
			</div>
			<?php
		}
	}

	function admin_page_listcats($this_role, $records, $parent_id = 0, $level = 0) {
		foreach ($records as $r) {
			if ($r->category_parent == $parent_id) {
				// display this record
				$row_classes = array();
				if ($this->alternation)
					$row_classes[] = 'alternate'
				;
				if ('On' == $r->inheritence)
					$row_classes[] = 'inheritence'
				;
				?>
				<tr class="<?php echo implode(' ', $row_classes); ?>">
					<td class="abc_cat_ID"><?php echo $r->cat_ID; ?><input type="hidden" name="index-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" value="1" /></th>
					<td class="abc_cat_name"><?php for ($i=0;$i<$level;$i++) { echo '- '; } echo $r->cat_name; ?></th>
				   <?php if ($this->role_can_read) : ?>
					<td class="abc_read_single compact"><input name="read_single-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Yes" <?php echo('Yes' == $r->read_single ? 'checked' : ''); ?> /></th>
					<td class="abc_read_single compact"><input name="read_single-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="No" <?php echo('No' == $r->read_single ? 'checked' : ''); ?> /></th>
					<td class="abc_read_single compact"><input name="read_single-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Block" <?php echo('Block' == $r->read_single ? 'checked' : ''); ?> /></th>
					<td class="abc_read_list compact"><input name="read_list-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Yes" <?php echo('Yes' == $r->read_list ? 'checked' : ''); ?> /></th>
					<td class="abc_read_list compact"><input name="read_list-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="No" <?php echo('No' == $r->read_list ? 'checked' : ''); ?> /></th>
					<td class="abc_read_list compact"><input name="read_list-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Block" <?php echo('Block' == $r->read_list ? 'checked' : ''); ?> /></th>
					<td class="abc_read_home compact"><input name="read_home-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Yes" <?php echo('Yes' == $r->read_home ? 'checked' : ''); ?> /></th>
					<td class="abc_read_home compact"><input name="read_home-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="No" <?php echo('No' == $r->read_home ? 'checked' : ''); ?> /></th>
					<td class="abc_read_home compact"><input name="read_home-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Block" <?php echo('Block' == $r->read_home ? 'checked' : ''); ?> /></th>
					<td class="abc_read_feed compact"><input name="read_feed-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Yes" <?php echo('Yes' == $r->read_feed ? 'checked' : ''); ?> /></th>
					<td class="abc_read_feed compact"><input name="read_feed-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="No" <?php echo('No' == $r->read_feed ? 'checked' : ''); ?> /></th>
					<td class="abc_read_feed compact"><input name="read_feed-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="radio" value="Block" <?php echo('Block' == $r->read_feed ? 'checked' : ''); ?> /></th>
				   <?php else : ?>
					<td class="abc_read_single" colspan="3">n/a<input type="hidden" name="read_single-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" value="Yes" /></th>
					<td class="abc_read_list" colspan="3">n/a<input type="hidden" name="read_list-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" value="Yes" /></th>
					<td class="abc_read_home" colspan="3">n/a<input type="hidden" name="read_home-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" value="Yes" /></th>
					<td class="abc_read_feed" colspan="3">n/a<input type="hidden" name="read_feed-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" value="Yes" /></th>
				   <?php endif; ?>
					<td class="abc_postto"><input name="postto-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="checkbox" value="Yes" <?php echo('Yes' == $r->postto ? 'checked' : ''); ?> /></th>
					<td class="abc_postto_default"><input name="postto_default-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="checkbox" value="Yes" <?php echo('Yes' == $r->postto_default ? 'checked' : ''); ?> /></th>
					<td class="abc_inheritence"><input name="inheritence-<?php echo $r->cat_ID; ?>-<?php echo $this_role; ?>" type="checkbox" value="On" <?php echo('On' == $r->inheritence ? 'checked' : ''); ?> onchange="toggle_inheritence(this)" /></th>
				</tr>
				<?php
				$this->alternation = ($this->alternation ? FALSE : TRUE);
				// display this record's descendants
				$this->admin_page_listcats($this_role, $records, $r->cat_ID, $level + 1);
			}
		}
	}

	function admin_page_style() {
	   ?>
		<style type="text/css">
		<!--
			.abc_dhtml {
				display: none;
			}
			.abc_settings .inheritence {
				background-color: #edb;
			}
			.abc_settings .abc_postto, .abc_settings .abc_postto_default {
				background-color: #dde;
			}
			.abc_settings .inheritence .abc_postto, .abc_settings .inheritence .abc_postto_default {
				background-color: #dcd;
			}
			.abc_settings tr:hover {
				background-color: #ff7;
			}

			.abc_settings th {
				padding: 3px 8px;
				text-align: left;
				background-color: #abb;
			}
			.abc_settings th.compact {
				padding: 3px;
				text-align: center;
			}
			.abc_settings td {
				padding: 2px 8px;
			}
			.abc_settings td.compact {
				padding: 2px 3px;
			}
			.abc_cat_name {
				font-size: 1.1em;
			}
			.abc_cat_ID {
				font-size: 0.9em;
			}
			#abc_selector {
				font-weight: bold;
			}
		// -->
		</style>
		<script type="text/javascript">
		<!--
			function toggle_inheritence(input_element) {
				var inheritence_on = input_element.checked;
				var tr_element = input_element.parentNode.parentNode;
				if (inheritence_on) {	// add 'inheritence' class to parent <tr>
					tr_element.className = tr_element.className + ' inheritence';
				} else {			// remove 'inheritence' class from parent <tr>
					tr_classNames = tr_element.className.split(' ');
					newClassName = '';
					for (i = 0; i < tr_classNames.length; i++) {
						if (tr_classNames[i] != 'inheritence') {
							newClassName = newClassName + tr_classNames[i] + ' ';
						}
					}
					tr_element.className = newClassName;
				}
			}

			/*	dynamicCSS.js v1.0 <http://www.bobbyvandersluis.com/articles/dynamicCSS.php>
				Copyright 2005 Bobby van der Sluis
				This software is licensed under the CC-GNU LGPL <http://creativecommons.org/licenses/LGPL/2.1/>
			*/
			function setElementStyleById(id, propertyName, propertyValue) {
				if (!document.getElementById) return;
				var el = document.getElementById(id);
				if (el) el.style[propertyName] = propertyValue;
			}
			function createStyleRule(selector, declaration) {
				if (!document.getElementsByTagName || !(document.createElement || document.createElementNS)) return;
				var agt = navigator.userAgent.toLowerCase();
				var is_ie = ((agt.indexOf("msie") != -1) && (agt.indexOf("opera") == -1));
				var is_iewin = (is_ie && (agt.indexOf("win") != -1));
				var is_iemac = (is_ie && (agt.indexOf("mac") != -1));
				if (is_iemac) return; // script doesn't work properly in IE/Mac
				var head = document.getElementsByTagName("head")[0]; 
				var style = (typeof document.createElementNS != "undefined") ?  document.createElementNS("http://www.w3.org/1999/xhtml", "style") : document.createElement("style");
				if (!is_iewin) {
					var styleRule = document.createTextNode(selector + " {" + declaration + "}");
					style.appendChild(styleRule); // bugs in IE/Win
				}
				style.setAttribute("type", "text/css");
				style.setAttribute("media", "screen"); 
				head.appendChild(style);
				if (is_iewin && document.styleSheets && document.styleSheets.length > 0) {
					var lastStyle = document.styleSheets[document.styleSheets.length - 1];
					if (typeof lastStyle.addRule == "object") { // bugs in IE/Mac and Safari
						lastStyle.addRule(selector, declaration);
					}
				}
			}
			/* end dynamicCSS.js v1.0 functions */

			// show DHTML and hide non-DHTML elements if javascript is working
			createStyleRule('.abc_dhtml', 'display: block;');
			createStyleRule('.abc_dhtml_hide', 'display: none;');
			var abc_role_shown;
		// -->
		</script>
	   <?php
	}

	function update_configuration() {
		// save new access rules to database
		global $wpdb;

		// what are all of the configured category/role indices?
		$indices = array();
		foreach (array_keys($_POST) as $key) {
			if ( preg_match('/^index-(.+)/', $key, $matches) ) {
				$indices[] = $matches[1];
			}
		}

		// for which indices are there non-default rules?
		$new_rules = array();
		foreach ($indices as $index) {
			if (
				$_POST["read_single-$index"] != 'Yes' ||
				$_POST["read_list-$index"] != 'Yes' ||
				$_POST["read_home-$index"] != 'Yes' ||
				$_POST["read_feed-$index"] != 'Yes' ||
				$_POST["postto-$index"] != 'Yes' ||
				$_POST["postto_default-$index"] == 'Yes' ||
				$_POST["inheritence-$index"] == 'On'
			) {
				$new_rules[$index] = array(
					'read_single' => $_POST["read_single-$index"],
					'read_list' => $_POST["read_list-$index"],
					'read_home' => $_POST["read_home-$index"],
					'read_feed' => $_POST["read_feed-$index"],
					'postto' => ($_POST["postto-$index"] == 'Yes' ? 'Yes' : 'No'),
					'postto_default' => ($_POST["postto_default-$index"] == 'Yes' ? 'Yes' : 'No'),
					'inheritence' => ($_POST["inheritence-$index"] == 'On' ? 'On' : 'Off')
				);
			}
		}

		// clear categories_access table
		$wpdb->query("DELETE FROM $this->categories_access_table WHERE 1=1;");

		// insert new rules into categories_access table
		foreach ( array_keys($new_rules) as $index ) {
			preg_match('/(\d+)-(.+)/', $index, $matches);
			$category_id = $matches[1];
			$role = $matches[2];
			$sql =
				"INSERT INTO $this->categories_access_table SET "
				.	"category_ID = '$category_id', "
				.	"role = '$role', "
				.	'read_single = "' . $new_rules[$index]['read_single'] . '", '
				.	'read_list = "' . $new_rules[$index]['read_list'] . '", '
				.	'read_home = "' . $new_rules[$index]['read_home'] . '", '
				.	'read_feed = "' . $new_rules[$index]['read_feed'] . '", '
				.	'postto = "' . $new_rules[$index]['postto'] . '", '
				.	'postto_default = "' . $new_rules[$index]['postto_default'] . '", '
				.	'inheritence = "' . $new_rules[$index]['inheritence'] . '"'
				. ';'
			;
			$wpdb->query($sql);
		}

		// rebuild posts_access and links_access tables
		$this->build_posts_access();
		$this->build_links_access();
	}
	/* end methods for admin configuration page */


	function debug($foo)
	{
		$args = func_get_args();
		echo "<pre style=\"background-color:#ffeeee;border:1px solid red;\">";
		foreach($args as $arg1)
		{
			echo htmlentities(print_r($arg1, 1)) . "<br/>";
		}
		echo "</pre>";
	}
	
	/**
	 * Plugin registration form
	 */
	function abcRegistrationForm($form_name, $submit_btn_txt='Register', $name, $email, $hide=0, $submit_again='') {
		$plugin_pg    = 'users.php';
		$thankyou_url = $this->abc_siteurl.'/wp-admin/'.$plugin_pg.'?page='.$_GET['page'];
		$onlist_url   = $this->abc_siteurl.'/wp-admin/'.$plugin_pg.'?page='.$_GET['page'].'&amp;mbp_onlist=1';
		if ( $hide == 1 ) $align_tbl = 'left';
		else $align_tbl = 'center';
		?>
		
		<?php if ( $submit_again != 1 ) { ?>
		<script><!--
		function trim(str){
			var n = str;
			while ( n.length>0 && n.charAt(0)==' ' ) 
				n = n.substring(1,n.length);
			while( n.length>0 && n.charAt(n.length-1)==' ' )	
				n = n.substring(0,n.length-1);
			return n;
		}
		function abcValidateForm_0() {
			var name = document.<?php echo $form_name;?>.name;
			var email = document.<?php echo $form_name;?>.from;
			var reg = /^([A-Za-z0-9_\-\.])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,4})$/;
			var err = ''
			if ( trim(name.value) == '' )
				err += '- Name Required\n';
			if ( reg.test(email.value) == false )
				err += '- Valid Email Required\n';
			if ( err != '' ) {
				alert(err);
				return false;
			}
			return true;
		}
		//-->
		</script>
		<?php } ?>
		<table align="<?php echo $align_tbl;?>">
		<form name="<?php echo $form_name;?>" method="post" action="http://www.aweber.com/scripts/addlead.pl" <?php if($submit_again!=1){;?>onsubmit="return abcValidateForm_0()"<?php }?>>
		 <input type="hidden" name="unit" value="maxbp-activate">
		 <input type="hidden" name="redirect" value="<?php echo $thankyou_url;?>">
		 <input type="hidden" name="meta_redirect_onlist" value="<?php echo $onlist_url;?>">
		 <input type="hidden" name="meta_adtracking" value="access-by-category">
		 <input type="hidden" name="meta_message" value="1">
		 <input type="hidden" name="meta_required" value="from,name">
	 	 <input type="hidden" name="meta_forward_vars" value="1">	
		 <?php if ( $submit_again == 1 ) { ?> 	
		 <input type="hidden" name="submit_again" value="1">
		 <?php } ?>		 
		 <?php if ( $hide == 1 ) { ?> 
		 <input type="hidden" name="name" value="<?php echo $name;?>">
		 <input type="hidden" name="from" value="<?php echo $email;?>">
		 <?php } else { ?>
		 <tr><td>Name: </td><td><input type="text" name="name" value="<?php echo $name;?>" size="25" maxlength="150" /></td></tr>
		 <tr><td>Email: </td><td><input type="text" name="from" value="<?php echo $email;?>" size="25" maxlength="150" /></td></tr>
		 <?php } ?>
		 <tr><td>&nbsp;</td><td><input type="submit" name="submit" value="<?php echo $submit_btn_txt;?>" class="button" /></td></tr>
		 </form>
		</table>
		<?php
	}
	
	/**
	 * Register Plugin - Step 2
	 */
	function abcRegisterStep2($form_name='frm2',$name,$email) {
		$msg = 'You have not clicked on the confirmation link yet. A confirmation email has been sent to you again. Please check your email and click on the confirmation link to activate the plugin.';
		if ( trim($_GET['submit_again']) != '' && $msg != '' ) {
			echo '<div id="message" class="updated fade"><p><strong>'.$msg.'</strong></p></div>';
		}
		?>
		<style type="text/css">
		table, tbody, tfoot, thead {
			padding: 8px;
		}
		tr, th, td {
			padding: 0 8px 0 8px;
		}
		</style>
		<div class="wrap"><h2> <?php echo ABC_NAME.' '.ABC_VERSION; ?></h2>
		 <center>
		 <table width="100%" cellpadding="3" cellspacing="1" style="border:1px solid #e3e3e3; padding: 8px; background-color:#f1f1f1;">
		 <tr><td align="center">
		 <table width="650" cellpadding="5" cellspacing="1" style="border:1px solid #e9e9e9; padding: 8px; background-color:#ffffff; text-align:left;">
		  <tr><td align="center"><h3>Almost Done....</h3></td></tr>
		  <tr><td><h3>Step 1:</h3></td></tr>
		  <tr><td>A confirmation email has been sent to your email "<?php echo $email;?>". You must click on the link inside the email to activate the plugin.</td></tr>
		  <tr><td><strong>The confirmation email will look like:</strong><br /><img src="http://www.maxblogpress.com/images/activate-plugin-email.jpg" vspace="4" border="0" /></td></tr>
		  <tr><td>&nbsp;</td></tr>
		  <tr><td><h3>Step 2:</h3></td></tr>
		  <tr><td>Click on the button below to Verify and Activate the plugin.</td></tr>
		  <tr><td><?php $this->abcRegistrationForm($form_name.'_0','Verify and Activate',$name,$email,$hide=1,$submit_again=1);?></td></tr>
		 </table>
		 </td></tr></table><br />
		 <table width="100%" cellpadding="3" cellspacing="1" style="border:1px solid #e3e3e3; padding:8px; background-color:#f1f1f1;">
		 <tr><td align="center">
		 <table width="650" cellpadding="5" cellspacing="1" style="border:1px solid #e9e9e9; padding:8px; background-color:#ffffff; text-align:left;">
           <tr><td><h3>Troubleshooting</h3></td></tr>
           <tr><td><strong>The confirmation email is not there in my inbox!</strong></td></tr>
           <tr><td>Dont panic! CHECK THE JUNK, spam or bulk folder of your email.</td></tr>
           <tr><td>&nbsp;</td></tr>
           <tr><td><strong>It's not there in the junk folder either.</strong></td></tr>
           <tr><td>Sometimes the confirmation email takes time to arrive. Please be patient. WAIT FOR 6 HOURS AT MOST. The confirmation email should be there by then.</td></tr>
           <tr><td>&nbsp;</td></tr>
           <tr><td><strong>6 hours and yet no sign of a confirmation email!</strong></td></tr>
           <tr><td>Please register again from below:</td></tr>
           <tr><td><?php $this->abcRegistrationForm($form_name,'Register Again',$name,$email,$hide=0,$submit_again=2);?></td></tr>
           <tr><td><strong>Help! Still no confirmation email and I have already registered twice</strong></td></tr>
           <tr><td>Okay, please register again from the form above using a DIFFERENT EMAIL ADDRESS this time.</td></tr>
           <tr><td>&nbsp;</td></tr>
           <tr>
             <td><strong>Why am I receiving an error similar to the one shown below?</strong><br />
                 <img src="http://www.maxblogpress.com/images/no-verification-error.jpg" border="0" vspace="8" /><br />
               You get that kind of error when you click on &quot;Verify and Activate&quot; button or try to register again.<br />
               <br />
               This error means that you have already subscribed but have not yet clicked on the link inside confirmation email. In order to  avoid any spam complain we don't send repeated confirmation emails. If you have not recieved the confirmation email then you need to wait for 12 hours at least before requesting another confirmation email. </td>
           </tr>
           <tr><td>&nbsp;</td></tr>
           <tr><td><strong>But I've still got problems.</strong></td></tr>
           <tr><td>Stay calm. <strong><a href="http://www.maxblogpress.com/contact-us/" target="_blank">Contact us</a></strong> about it and we will get to you ASAP.</td></tr>
         </table>
		 </td></tr></table>
		 </center>		
		<p style="text-align:center;margin-top:3em;"><strong><?php echo ABC_NAME.' '.ABC_VERSION; ?> by <a href="http://www.maxblogpress.com/" target="_blank" >MaxBlogPress</a></strong></p>
	    </div>
		<?php
	}

	/**
	 * Register Plugin - Step 1
	 */
	function abcRegisterStep1($form_name='frm1',$userdata) {
		$name  = trim($userdata->first_name.' '.$userdata->last_name);
		$email = trim($userdata->user_email);
		?>
		<style type="text/css">
		tabled , tbody, tfoot, thead {
			padding: 8px;
		}
		tr, th, td {
			padding: 0 8px 0 8px;
		}
		</style>
		<div class="wrap"><h2> <?php echo ABC_NAME.' '.ABC_VERSION; ?></h2>
		 <center>
		 <table width="100%" cellpadding="3" cellspacing="1" style="border:2px solid #e3e3e3; padding: 8px; background-color:#f1f1f1;">
		  <tr><td align="center">
		    <table width="548" align="center" cellpadding="3" cellspacing="1" style="border:1px solid #e9e9e9; padding: 8px; background-color:#ffffff;">
			  <tr><td align="center"><h3>Please register the plugin to activate it. (Registration is free)</h3></td></tr>
			  <tr><td align="left">In addition you'll receive complimentary subscription to MaxBlogPress Newsletter which will give you many tips and tricks to attract lots of visitors to your blog.</td></tr>
			  <tr><td align="center"><strong>Fill the form below to register the plugin:</strong></td></tr>
			  <tr><td align="center"><?php $this->abcRegistrationForm($form_name,'Register',$name,$email);?></td></tr>
			  <tr><td align="center"><font size="1">[ Your contact information will be handled with the strictest confidence <br />and will never be sold or shared with third parties ]</font></td></tr>
		    </table>
		  </td></tr></table>
		 </center>
		<p style="text-align:center;margin-top:3em;"><strong><?php echo ABC_NAME.' '.ABC_VERSION; ?> by <a href="http://www.maxblogpress.com/" target="_blank" >MaxBlogPress</a></strong></p>
	    </div>
		<?php
	}

}

// instantiate
global $accessbycategory;
$accessbycategory = new accessbycategory();
?>