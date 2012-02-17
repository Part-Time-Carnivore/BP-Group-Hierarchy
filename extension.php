<?php
/**
 * 
 * This file contains a reference user interface for hierarchical groups.
 * One part is the Groups extension that adds the Member Groups tab to groups 
 * and allows creators to place new groups within the hierarchy.
 * The other is an administrative and permissions interface for that feature
 * 
 */

if( ! class_exists( 'BP_Group_Extension') ) {
	// Groups component is not enabled; don't initialize the extension
	return;
}

class BP_Groups_Hierarchy_Extension extends BP_Group_Extension {
	
	var $visibility = 'public';
	
	function bp_groups_hierarchy_extension() {
		
		global $bp;
		
		$nav_item_name = get_site_option( 'bpgh_extension_nav_item_name', __('Member Groups %d','bp-group-hierarchy') );
		
		$this->name = __( 'Group Hierarchy', 'bp-group-hierarchy' );
		$this->nav_item_name = $nav_item_name;
		
		if(isset($bp->groups->current_group) && $bp->groups->current_group) {
			$this->nav_item_name = str_replace( '%d', '<span>%d</span>', $this->nav_item_name );
			$this->nav_item_name = sprintf($this->nav_item_name, BP_Groups_Hierarchy::get_total_subgroup_count( $bp->groups->current_group->id ) );
		}
		
		$this->slug = BP_GROUP_HIERARCHY_SLUG;
		
		if(isset($_COOKIE['bp_new_group_parent_id'])) {
			$bp->group_hierarchy->new_group_parent_id = $_COOKIE['bp_new_group_parent_id'];
			add_action( 'bp_after_group_details_creation_step', array( &$this, 'add_parent_selection' ) );
		}
		$this->create_step_position = 6;
		$this->nav_item_position = 61;

		/** workaround for buddypress bug #2701 */
		if(!$bp->is_item_admin && !is_super_admin()) {
			$this->enable_edit_item = false;
		}
				
		$this->subgroup_permission_options = array(
			'anyone'		=> __('Anybody','bp-group-hierarchy'),
			'noone'			=> __('Nobody','bp-group-hierarchy'),
			'group_members'	=> __('only Group Members','bp-group-hierarchy'),
			'group_admins'	=> __('only Group Admins','bp-group-hierarchy')
		);
		$bp->subgroup_permission_options = $this->subgroup_permission_options;
		
		if(isset($bp->groups->current_group) && $bp->groups->current_group) {
			$bp->groups->current_group->can_create_subitems = bp_group_hierarchy_can_create_subgroups();
		}
		
		$this->enable_nav_item = $this->enable_nav_item();
		
	}
	
	function get_default_permission_option() {
		return 'group_members';
	}
	
	function enable_nav_item() {
		global $bp;
		
		if(is_admin())	return false;
		
		if( ! is_object($bp->groups->current_group) )	return false;
		
		/** Only display the nav item for admins, those who can create subgroups, or everyone if the group has subgroups */
		if (
				$bp->is_item_admin || 
				$bp->groups->current_group->can_create_subitems || 
				BP_Groups_Hierarchy::has_children( $bp->groups->current_group->id )
			) {
			return true;
		}
		return false;
	}
	
	function add_parent_selection() {
		global $bp;
		if(!bp_is_group_creation_step( 'group-details' )) {
			return false;
		}
		
		$parent_group = new BP_Groups_Hierarchy( $bp->group_hierarchy->new_group_parent_id );
		
		?>
		<label for="group-parent_id"><?php _e( 'Parent Group', 'bp-group-hierarchy' ); ?></label>
		<input type="hidden" name="group-parent_id" id="group-parent_id" value="<?php echo $parent_group->id ?>" />
		<?php echo $parent_group->name ?>
		<?php
	}
	
	function create_screen() {
		
		global $bp;

		if(!bp_is_group_creation_step( $this->slug )) {
			return false;
		}
				
		$this_group = new BP_Groups_Hierarchy( $bp->groups->new_group_id );

		if(isset($_COOKIE['bp_new_group_parent_id'])) {
			$this_group->parent_id = $_COOKIE['bp_new_group_parent_id'];
			$this_group->save();
		}

		$groups = BP_Groups_Hierarchy::get_active();
		$exclude_groups = array($bp->groups->new_group_id);
		
		$site_root = new stdClass();
		$site_root->id = 0;
		$site_root->name = __( 'Site Root', 'bp-group-hierarchy' );
		$display_groups = array(
			$site_root
		);
		foreach($groups['groups'] as $group) {
			if(!in_array($group->id,$exclude_groups)) {
				$display_groups[] = $group;
			}
		}
		
		/* deprecated */
		$display_groups = apply_filters( 'bp_group_hierarchy_display_groups', $display_groups );
		
		$display_groups = apply_filters( 'bp_group_hierarchy_available_parent_groups', $display_groups, $this_group );

		?>
		<label for="parent_id"><?php _e( 'Parent Group', 'bp-group-hierarchy' ); ?></label>
		<select name="parent_id" id="parent_id">
			<!--<option value="0"><?php _e( 'Site Root', 'bp-group-hierarchy' ); ?></option>-->
			<?php foreach($display_groups as $group) { ?>
				<option value="<?php echo $group->id ?>"<?php if($group->id == $this_group->parent_id) echo ' selected'; ?>><?php echo $group->name; ?></option>
			<?php } ?>
		</select>
		<?php

		$subgroup_permission_options = apply_filters( 'bp_group_hierarchy_subgroup_permission_options', $this->subgroup_permission_options, $this_group );
		
		$current_subgroup_permission = groups_get_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators' );
		if($current_subgroup_permission == '')
			$current_subgroup_permission = $this->get_default_permission_option();
		
		$permission_select = '<select name="allow_children_by" id="allow_children_by">';
		foreach($subgroup_permission_options as $option => $text) {
			$permission_select .= '<option value="' . $option . '"' . (($option == $current_subgroup_permission) ? ' selected' : '') . '>' . $text . '</option>' . "\n";
		}
		$permission_select .= '</select>';
		?>
		<p>
			<label for="allow_children_by"><?php _e( 'Member Groups', 'bp-group-hierarchy' ); ?></label>
			<?php printf( __( 'Allow %1$s to create %2$s', 'bp-group-hierarchy' ), $permission_select, __( 'Member Groups', 'bp-group-hierarchy' ) ); ?>
		</p>
		<?php
		wp_nonce_field( 'groups_create_save_' . $this->slug );
	}
	
	function create_screen_save() {
		global $bp;
		
		check_admin_referer( 'groups_create_save_' . $this->slug );

		setcookie( 'bp_new_group_parent_id', false, time() - 1000, COOKIEPATH );
		
		/** save the selected parent_id */
		$parent_id = (int)$_POST['parent_id'];
		
		if(bp_group_hierarchy_can_create_subgroups( $bp->loggedin_user->id, $parent_id )) {
			$bp->groups->current_group = new BP_Groups_Hierarchy( $bp->groups->new_group_id );
	
			$bp->groups->current_group->parent_id = $parent_id;
			$bp->groups->current_group->save();
		}

		/** save the selected subgroup permission setting */
		$permission_options = apply_filters( 'bp_group_hierarchy_subgroup_permission_options', $this->subgroup_permission_options );
		if(array_key_exists( $_POST['allow_children_by'], $permission_options )) {
			$allow_children_by = $_POST['allow_children_by'];
		} else {
			$allow_children_by = $this->get_default_permission_option();
		}
		
		groups_update_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators', $allow_children_by );
		
	}
	
	function edit_screen() {

		global $bp;

		if(!bp_is_group_admin_screen( $this->slug )) {
			return false;
		}
		
		if(is_super_admin()) {
			$groups = BP_Groups_Hierarchy::get_active();
			$exclude_groups = BP_Groups_Hierarchy::get_by_parent( $bp->groups->current_group->id );
			
			if(count($exclude_groups['groups']) > 0) {
				foreach($exclude_groups['groups'] as $key => $exclude_group) {
					$exclude_groups['groups'][$key] = $exclude_group->id;
				}
				$exclude_groups = $exclude_groups['groups'];
			} else {
				$exclude_groups = array();
			}
			$exclude_groups[] = $bp->groups->current_group->id;
			
			$display_groups = array();
			foreach($groups['groups'] as $group) {
				if(!in_array($group->id,$exclude_groups)) {
					$display_groups[] = $group;
				}
			}
			
			/* deprecated */
			$display_groups = apply_filters( 'bp_group_hierarchy_display_groups', $display_groups );
			
			$display_groups = apply_filters( 'bp_group_hierarchy_available_parent_groups', $display_groups );
			
			?>
			<label for="parent_id"><?php _e( 'Parent Group', 'bp-group-hierarchy' ); ?></label>
			<select name="parent_id" id="parent_id">
				<option value="0"><?php _e( 'Site Root', 'bp-group-hierarchy' ); ?></option>
				<?php foreach($display_groups as $group) { ?>
					<option value="<?php echo $group->id ?>"<?php if($group->id == $bp->groups->current_group->parent_id) echo ' selected'; ?>><?php echo $group->name; ?></option>
				<?php } ?>
			</select>
			<?php
		} else {
			?>
			<div id="message">
				<p><?php _e('Only a site administrator can edit the group hierarchy.', 'bp-group-hierarchy' ); ?></p>
			</div>
			<?php
		}
		
		if(is_super_admin() || bp_group_is_admin()) {
				
			$subgroup_permission_options = apply_filters( 'bp_group_hierarchy_subgroup_permission_options', $this->subgroup_permission_options );
			
			$current_subgroup_permission = groups_get_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators' );
			if($current_subgroup_permission == '')
				$current_subgroup_permission = $this->get_default_permission_option();
			
			$permission_select = '<select name="allow_children_by" id="allow_children_by">';
			foreach($subgroup_permission_options as $option => $text) {
				$permission_select .= '<option value="' . $option . '"' . (($option == $current_subgroup_permission) ? ' selected' : '') . '>' . $text . '</option>' . "\n";
			}
			$permission_select .= '</select>';
			?>
			<p>
				<label for="allow_children_by"><?php _e( 'Member Groups', 'bp-group-hierarchy' ); ?></label>
				<?php printf( __( 'Allow %1$s to create %2$s', 'bp-group-hierarchy' ), $permission_select, __( 'Member Groups', 'bp-group-hierarchy' ) ); ?>
			</p>
			<p>
				<input type="submit" class="button primary" id="save" name="save" value="<?php _e( 'Save Changes', 'bp-group-hierarchy' ); ?>" />
			</p>
			<?php
			wp_nonce_field( 'groups_edit_save_' . $this->slug );
			
		}
	}
	
	function edit_screen_save() {
		global $bp;
		
		if( !isset($_POST['save']) ) {
			return false;
		}
		
		check_admin_referer( 'groups_edit_save_' . $this->slug );

		/** save the selected subgroup permission setting */
		$permission_options = apply_filters( 'bp_group_hierarchy_subgroup_permission_options', $this->subgroup_permission_options );
		if(array_key_exists( $_POST['allow_children_by'], $permission_options )) {
			$allow_children_by = $_POST['allow_children_by'];
		} else if(groups_get_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators' ) != '') {
			$allow_children_by = groups_get_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators' );
		} else {
			$allow_children_by = $this->get_default_permission_option();
		}
		
		groups_update_groupmeta( $bp->groups->current_group->id, 'bp_group_hierarchy_subgroup_creators', $allow_children_by );
		
		if(is_super_admin()) {
			/** save changed parent_id */
			$parent_id = (int)$_POST['parent_id'];
			
			if( bp_group_hierarchy_can_create_subgroups( $bp->loggedin_user->id, $bp->groups->current_group->id ) ) {
				$bp->groups->current_group->parent_id = $parent_id;
				$success = $bp->groups->current_group->save();
			}
			
			if( !$success ) {
				bp_core_add_message( __( 'There was an error saving; please try again.', 'bp-group-hierarchy' ), 'error' );
			} else {
				bp_core_add_message( __( 'Group hierarchy settings saved successfully.', 'bp-group-hierarchy' ) );
			}
		}
		
		bp_core_redirect( bp_get_group_admin_permalink( $bp->groups->current_group ) );
	}
	
	function display($page = 1) {
		global $bp, $groups_template;
		
		$parent_template = $groups_template;
		$hide_button = false;
		
		if(isset($_REQUEST['grpage'])) {
			$page = (int)$_REQUEST['grpage'];
		} else if(!is_numeric($page)) {
			$page = 1;
		} else {
			$page = (int)$page;
		}
		
		/** Respect BuddyPress group creation restriction */
		if(function_exists('bp_user_can_create_groups')) {
			$hide_button = !bp_user_can_create_groups();
		}
		
		bp_has_groups_hierarchy(array(
			'type'		=> 'alphabetical',
			'parent_id'	=> $bp->groups->current_group->id,
			'page'		=> $page
		));
		
		?>
		<div class="group">

			<?php if(($bp->is_item_admin || $bp->groups->current_group->can_create_subitems) && !$hide_button) { ?>
			<div class="generic-button group-button">
				<a title="<?php printf( __( 'Create a %s', 'bp-group-hierarchy' ),__( 'Member Group', 'bp-group-hierarchy' ) ) ?>" href="<?php echo $bp->root_domain . '/' . bp_get_groups_hierarchy_root_slug() . '/' . 'create' .'/?parent_id=' . $bp->groups->current_group->id ?>"><?php printf( __( 'Create a %s', 'bp-group-hierarchy' ),__( 'Member Group', 'bp-group-hierarchy' ) ) ?></a>
			</div><br /><br />
			<?php } ?>

		<?php if($groups_template && count($groups_template->groups) > 0) : ?>

			<div id="pag-top" class="pagination">
				<div class="pag-count" id="group-dir-count-top">
					<?php bp_groups_pagination_count() ?>
				</div>
		
				<div class="pagination-links" id="group-dir-pag-top">
					<?php bp_groups_pagination_links() ?>
				</div>
			</div>
	
			<ul id="groups-list" class="item-list">
				<?php while ( bp_groups() ) : bp_the_group(); ?>
				<?php $subgroup = $groups_template->group; ?>
				<?php if($subgroup->status == 'hidden' && !( groups_is_user_member( $bp->loggedin_user->id, $subgroup->id ) || groups_is_user_admin( $bp->loggedin_user->id, $bp->groups->current_group->id ) ) ) continue; ?>
				<li id="tree-childof_<?php bp_group_id() ?>">
					<div class="item-avatar">
						<a href="<?php bp_group_permalink() ?>"><?php bp_group_avatar( 'type=thumb&width=50&height=50' ) ?></a>
					</div>
		
					<div class="item">
						<div class="item-title"><a href="<?php bp_group_permalink() ?>"><?php bp_group_name() ?></a></div>
						<?php if(floatval(BP_VERSION) < 1.3 ) : ?>
						<div class="item-meta"><span class="activity"><?php printf( __( 'active %s ago', 'buddypress' ), bp_get_group_last_active() ) ?></span></div>
						<?php else: ?>
						<div class="item-meta"><span class="activity"><?php printf( __( 'active %s', 'buddypress' ), bp_get_group_last_active() ); ?></span></div>
						<?php endif; ?>
						<div class="item-desc"><?php bp_group_description_excerpt() ?></div>
		
						<?php do_action( 'bp_directory_groups_item' ) ?>
		
					</div>
		
					<div class="action">
						<?php do_action( 'bp_directory_groups_actions' ) ?>
						<div class="meta">
							<?php bp_group_type() ?> / <?php bp_group_member_count() ?>
						</div>
					</div>
					<div class="clear"></div>
				</li>
		
				<?php endwhile; ?>
			</ul>
			<div id="pag-bottom" class="pagination">
		
				<div class="pag-count" id="group-dir-count-bottom">
					<?php bp_groups_pagination_count() ?>
				</div>
		
				<div class="pagination-links" id="group-dir-pag-bottom">
					<?php bp_groups_pagination_links() ?>
				</div>
		
			</div>
			<script type="text/javascript">
			jQuery('#nav-hierarchy-personal-li').attr('id','group-hierarchy-personal-li');
			jQuery('#nav-hierarchy-groups-li').attr('id','group-hierarchy-group-li');
			</script>
			
		<?php else: ?>
		<p><?php _e('No member groups were found.','bp-group-hierarchy'); ?></p>
		<?php endif; ?>
		</div>
		<?php
		// reset the $groups_template global and continue with the page
		$groups_template = $parent_template;
	}
}

bp_register_group_extension( 'BP_Groups_Hierarchy_Extension' );

/**
 * 
 * Group creation permission / restriction functions
 * 
 */

/**
 * Store the ID of the group the user selected as the parent for group creation
 */
function bp_group_hierarchy_set_parent_id_cookie() {
	global $current_component, $current_action, $action_variables, $bp;
	
	/** BP 1.5 compatibility */
	if(!isset($current_component)) {
		bp_core_set_uri_globals();
		$current_component = $bp->current_component;
		$current_action = $bp->current_action;
	}

	if(bp_is_groups_component() && $current_action == 'create' && isset($_REQUEST['parent_id']) && $_REQUEST['parent_id'] != 0) {
		setcookie( 'bp_new_group_parent_id', (int)$_REQUEST['parent_id'], time() + 1000, COOKIEPATH );
	}
}
add_action( 'bp_group_hierarchy_route_requests', 'bp_group_hierarchy_set_parent_id_cookie' );

/**
 * Check whether the user is allowed to create subgroups of the selected group
 * @param int UserID ID of the user whose access is being checked (or current user if omitted)
 * @param int GroupID ID of the group being checked (or group beign displayed if omitted)
 * @return bool TRUE if permitted, FALSE otherwise
 */
function bp_group_hierarchy_can_create_subgroups( $user_id = null, $group_id = null ) {
	global $bp;

	if(is_null($user_id)) {
		$user_id = $bp->loggedin_user->id;
	}
	if(is_null($group_id)) {
		$group_id = $bp->groups->current_group->id;
	}

	if(is_super_admin()) {
		return true;
	}

	if($group_id == 0) {
		$subgroup_permission = get_site_option('bpgh_extension_toplevel_group_permission','anyone');
	} else {
		$subgroup_permission = groups_get_groupmeta( $group_id, 'bp_group_hierarchy_subgroup_creators');
	}
	if($subgroup_permission == '') {
		$subgroup_permission = BP_Groups_Hierarchy_Extension::get_default_permission_option();
	}
	switch($subgroup_permission) {
		case 'noone':
			return false;
			break;
		case 'anyone':
			return (is_user_logged_in() || get_site_option( 'bpgh_extension_allow_anon_private_access', true) );
			break;
		case 'group_members':
			if(groups_is_user_member( $user_id, $group_id )) {
				return true;
			}
			break;
		case 'group_admins':
			if(groups_is_user_admin( $user_id, $group_id )) {
				return true;
			}
			break;
		default:
			if(
				has_filter('bp_group_hierarchy_enforce_subgroup_permission_' . $subgroup_permission ) && 
				apply_filters( 'bp_group_hierarchy_enforce_subgroup_permission_' . $subgroup_permission, false, $user_id, $group_id )) 
			{
				return true;
			}
			break;
	}
	return false;
}

/**
 * Enforce subgroup creation restrictions in parent group selection boxes
 */
function bp_group_hierarchy_enforce_subgroup_permissions( $groups ) {
	
	global $bp;
	
	/** super admins can add subgroups to any group */
	if(is_super_admin()) {
		return $groups;
	}
	
	if($allowed_groups = wp_cache_get( 'subgroup_creation_permitted_' . $bp->loggedin_user->id, 'bp_group_hierarchy' )) {
		return $allowed_groups;
	}
	
	$allowed_groups = array();
	foreach($groups as $group) {
		if(bp_group_hierarchy_can_create_subgroups( $bp->loggedin_user->id, $group->id )) {
			$allowed_groups[] = $group;
		}
	}
	wp_cache_set( 'subgroup_creation_permitted_' . $bp->loggedin_user->id, $allowed_groups, 'bp_group_hierarchy' );
	return $allowed_groups;
}
add_filter( 'bp_group_hierarchy_available_parent_groups', 'bp_group_hierarchy_enforce_subgroup_permissions' );


/**
 * 
 * Hierarchical Group Display functions
 * These are controlled by admin settings - see admin section, below
 */

function bp_group_hierarchy_tab() {
	global $bp;
	?>
	<li id="tree-all"><a href="<?php echo bp_get_root_domain() . '/' . bp_get_groups_hierarchy_root_slug() . '/?tree' ?>"><?php echo $bp->group_hierarchy->extension_settings['group_tree_name'] ?></a></li>
	<?php
}

/**
 * Functions for new 'tree' object-based hierachy display, which supports a new template
 */
 
/** Filter group results when requesting as part of the tree */
function bp_group_hierarchy_display( $query_string, $object, $parent_id = 0 ) {
	if($object == 'tree') {
		if(isset($_POST['scope']) && $_POST['scope'] != 'all') {
			$parent_id = substr($_POST['scope'],8);
			$parent_id = (int)$parent_id;
		}
		$query_string .= '&parent_id=' . $parent_id;
		if($parent_id != 0) {
			$query_string .= '&per_page=100';
		}
		add_filter( 'groups_get_groups', 'bp_group_hierarchy_get_groups_tree', 10, 2 );
	}
	return $query_string;
}
add_filter( 'bp_ajax_querystring', 'bp_group_hierarchy_display', 20, 2 );

/** Load the tree loop instead of the group loop when requested as part of the tree */
function bp_group_hierarchy_object_template_loader() {
	$object = esc_attr( $_POST['object'] );
	if($object == 'tree') {
		if($template = apply_filters('bp_located_template',locate_template( array( "$object/$object-loop.php" ), false ), "$object/$object-loop.php" )) {
			load_template($template);
		} else {
			bp_group_hierarchy_debug('Failed to find loop template for object: ' . $object);
		}
	}
}
add_action( 'wp_ajax_tree_filter', 'bp_group_hierarchy_object_template_loader' );

function bp_group_hierarchy_display_member_group_pages() {
	die(BP_Groups_Hierarchy_Extension::display($_POST['page']));
}
add_action( 'wp_ajax_group_filter', 'bp_group_hierarchy_display_member_group_pages');

/** 
 * Enable loading template files from the plugin directory 
 */
function bp_group_hierarchy_load_template_filter( $found_template, $templates ) {
	
	if ( !bp_is_groups_component() )
		return $found_template;
	
	$filtered_templates = array();
	foreach ( (array) $templates as $template ) {
		if ( file_exists( STYLESHEETPATH . '/' . $template ) ) {
			$filtered_templates[] = STYLESHEETPATH . '/' . $template;
		} else if ( file_exists( TEMPLATEPATH . '/' . $template ) ) {
			$filtered_templates[] = TEMPLATEPATH . '/' . $template;
		} else if ( file_exists( dirname( __FILE__ ) . '/templates/' . $template ) ) {
			$filtered_templates[] = dirname( __FILE__ ) . '/templates/' . $template;
		} else {
			bp_group_hierarchy_debug( 'Could not locate the requested template file: ' . $template );
		}
	}
	
	if(count($filtered_templates) == 0 ) {
		return $found_template;
	}
	
	$found_template = $filtered_templates[0];
	return $found_template;
}
add_filter( 'bp_located_template', 'bp_group_hierarchy_load_template_filter', 10, 2 );

/**
 * Restrict group listing to top-level groups
 */
function bp_group_hierarchy_get_groups_tree($groups, $params, $parent_id = 0) {
	global $bp, $groups_template;
	
	if( isset($_POST['scope']) && $_POST['object'] == 'tree' && $_POST['scope'] != 'all' ) {
		$parent_id = substr( $_POST['scope'], 8 );
		$parent_id = (int)$parent_id;
	}
	
	/** group list processing */
	if(!isset($bp->groups->current_group->id)) {

		/** remove search placeholder text for BP 1.5 */
		if( function_exists( 'bp_get_search_default_text' ) && trim( $params['search_terms'] ) == bp_get_search_default_text( 'groups' ) )	$params['search_terms'] = '';
		
		if($params['search_terms'] == '') {
			$params = array_merge( $params, array('parent_id' => $parent_id) );
			
			$toplevel_groups = bp_group_hierarchy_get_by_hierarchy( $params );
			$groups = $toplevel_groups;
		}
		
	}
	return $groups;
}

function bp_group_hierarchy_group_tree_title( $full_title, $title, $sep_location = null) {
	global $bp;
	if($sep_location != null) {
		return $bp->group_hierarchy->extension_settings['group_tree_name'] . $full_title;
	}
	return $full_title . $bp->group_hierarchy->extension_settings['group_tree_name'];
}

/************************************************************
 * Enforce toplevel group creation restrictions on the UI
 */

/**
 * If the user doesn't have any place to create a new group, don't let him create a group
 */
function bp_group_hierarchy_assert_parent_available( $return = false ) {
	global $bp;
	
	if(is_super_admin())	return true;
	
	if( $cache_result = wp_cache_get( $bp->loggedin_user->id, 'bpgh_has_available_parent_group' ) ) {
		if($cache_result == 'true') {
			return true;
		}
		if($return) {
			return false;
		} else {
			wp_die( __( 'Sorry, you are not allowed to create groups.', 'buddypress' ), __( 'Sorry, you are not allowed to create groups.', 'buddypress' ) );
		}
	}
	
	$group_permission = get_site_option('bpgh_extension_toplevel_group_permission');
	if(
		$group_permission == 'anyone' || 
		(has_filter('bp_group_hierarchy_enforce_subgroup_permission_' . $group_permission ) && 
		apply_filters( 'bp_group_hierarchy_enforce_subgroup_permission_' . $group_permission, false, $bp->loggedin_user->id, 0 ))
	) {
		/** If the user can create top-level groups, we're done looking */
		wp_cache_set( $bp->loggedin_user->id, 'true', 'bpgh_has_available_parent_group' );
		return true;
	}
	
	$user_groups = groups_get_groups(array('user_id'=>$bp->loggedin_user->id));
	
	foreach($user_groups['groups'] as $group) {

		if( bp_group_hierarchy_can_create_subgroups( $bp->loggedin_user->id, $group->id ) ) {
			/** If the user can create subgroups here, we're done looking */
			wp_cache_set( $bp->loggedin_user->id, 'true', 'bpgh_has_available_parent_group' );
			return true;
		}
	}

	wp_cache_set( $bp->loggedin_user->id, 'false', 'bpgh_has_available_parent_group' );
	if($return) {
		return false;
	} else {
		wp_die( __( 'Sorry, you are not allowed to create groups.', 'buddypress' ), __( 'Sorry, you are not allowed to create groups.', 'buddypress' ) );
	}

}
add_action( 'bp_before_create_group', 'bp_group_hierarchy_assert_parent_available' );

/**
 * (BP 1.5.x) Hide the Create a New Group buton if the user doesn't have a place to create new groups
 */
function bp_group_hierarchy_can_create_any_group( $permitted, $global_setting ) {
	global $bp;
	return $permitted && bp_group_hierarchy_assert_parent_available(true);
}
add_filter( 'bp_user_can_create_groups', 'bp_group_hierarchy_can_create_any_group', 10, 2 );

/**
 * 
 * Admin options
 * 
 */
function bp_group_hierarchy_admin_page() {
	global $bp, $wpdb;
	
	$updated = false;
	
	if(isset($_POST['save-settings']) && check_admin_referer( 'bp_group_hierarchy_extension_options' )) {
		
		$options = $_POST['options'];
		
		update_site_option( 'bpgh_extension_show_group_tree', isset($options['show_group_tree']));
		update_site_option( 'bpgh_extension_hide_group_list', isset($options['hide_group_list']));
		update_site_option( 'bpgh_extension_toplevel_group_permission', $options['toplevel_group_permission']);
		update_site_option( 'bpgh_extension_group_tree_name', $options['group_tree_name']);
		update_site_option( 'bpgh_extension_nav_item_name',   $options['nav_item_name']);
		
		$updated = true;
	}
	
	$options = array(
		'show_group_tree'			=> get_site_option( 'bpgh_extension_show_group_tree', false ),
		'hide_group_list'			=> get_site_option( 'bpgh_extension_hide_group_list', false ),
		'toplevel_group_permission'	=> get_site_option( 'bpgh_extension_toplevel_group_permission', 'anyone'),
		'group_tree_name'			=> get_site_option( 'bpgh_extension_group_tree_name', __('Group Tree','bp-group-hierarchy') ),
		'nav_item_name'				=> get_site_option( 'bpgh_extension_nav_item_name', __('Member Groups','bp-group-hierarchy') )
	);
	?>
	<div class="wrap">
		<?php if($updated) { ?><div id="message" class="updated"><p><strong><?php _e('Settings saved.'); ?></strong></p></div><?php } ?>
		<h2><?php _e('Group Hierarchy Settings','bp-group-hierarchy'); ?></h2>
		<form method="post">
			<h3>Options</h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="show_group_tree"><?php _e('Show Group Tree','bp-group-hierarchy') ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="show_group_tree" name="options[show_group_tree]"<?php if($options['show_group_tree']) echo 'checked'; ?> />
							<?php _e('Show the Group Tree view on the Groups page along with the flat list of groups.','bp-group-hierarchy'); ?>
						</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="hide_group_list"><?php _e('Hide Group List','bp-group-hierarchy') ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="hide_group_list" name="options[hide_group_list]"<?php if($options['hide_group_list']) echo 'checked'; ?> />
							<?php _e('Hide the flat list of groups and show ONLY the Group Tree on the Groups page','bp-group-hierarchy'); ?> (EXPERIMENTAL)
						</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="hide_group_list"><?php _e('Toplevel Groups','bp-group-hierarchy') ?></label></th>
					<td>
						<label>
							<select id="toplevel_group_permission" name="options[toplevel_group_permission]">
								<?php
								$subgroup_permission_options = apply_filters( 'bp_group_hierarchy_toplevel_subgroup_permissions', $bp->subgroup_permission_options );
								foreach($subgroup_permission_options as $option => $text) { if(strpos($option,'one') === false)	continue;?>
									<option value="<?php echo $option ?>" <?php if($option == $options['toplevel_group_permission']) echo ' selected'; ?>><?php echo $text ?></option>
								<?php }	?>
							</select>
							<?php _e('Select who is allowed to create toplevel groups. Super admins can always create toplevel groups.','bp-group-hierarchy'); ?>
						</label>
					</td>
				</tr>
			</table>
			<h3>Labels</h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="nav_item_name"><?php _e('Nav Item','bp-group-hierarchy'); ?></label></th>
					<td>
						<input type="text" id="nav_item_name" name="options[nav_item_name]" value="<?php echo $options['nav_item_name'] ?>" /><br />
						<?php _e("Name of the nav item on an individual group's page.",'bp-group-hierarchy'); ?>
						<?php _e("Use <code>%d</code> to include the number of child groups.",'bp-group-hierarchy'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="group_tree_name"><?php _e('Group Tree','bp-group-hierarchy'); ?></label></th>
					<td>
						<input type="text" id="group_tree_name" name="options[group_tree_name]" value="<?php echo $options['group_tree_name'] ?>" /><br />
						<?php _e('Name of the Group Tree listing on the main Groups page.','bp-group-hierarchy'); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __('Save Changes'), 'primary', 'save-settings' ); ?>
			<?php wp_nonce_field( 'bp_group_hierarchy_extension_options' ); ?>
		</form>
	</div>
	<?php
}
 
function bp_group_hierarchy_extension_admin() {
	add_submenu_page( 'bp-general-settings', __('Group Hierarchy','bp-group-hierarchy'), __('Group Hierarchy','bp-group-hierarchy'), 'manage_options', 'bp_group_hierarchy_settings', 'bp_group_hierarchy_admin_page' );
}
add_action( 'network_admin_menu', 'bp_group_hierarchy_extension_admin' );
add_action( 'admin_menu', 'bp_group_hierarchy_extension_admin' );


function bp_group_hierarchy_extension_init() {
	global $bp;
	
	add_action( 'wp_ajax_groups_tree_filter', 'bp_dtheme_object_template_loader' );
	
	$bp->group_hierarchy->extension_settings = array(
		'show_group_tree'	=> get_site_option( 'bpgh_extension_show_group_tree', false ),
		'hide_group_list'	=> get_site_option( 'bpgh_extension_hide_group_list', false ),
		'nav_item_name'		=> get_site_option( 'bpgh_extension_nav_item_name', __('Member Groups (%d)','bp-group-hierarchy') ),
		'group_tree_name'	=> get_site_option( 'bpgh_extension_group_tree_name', __('Group Tree','bp-group-hierarchy') ),
	);

	wp_register_script('bp-group-hierarchy-tree-script', WP_PLUGIN_URL .'/bp-group-hierarchy/includes/hierarchy.js', array('jquery'));
	wp_register_style('bp-group-hierarchy-tree-style', WP_PLUGIN_URL . '/bp-group-hierarchy/includes/hierarchy.css');
	
	if(bp_is_groups_component() && $bp->current_action == '' && $bp->group_hierarchy->extension_settings['hide_group_list']) {
		add_filter( 'groups_get_groups', 'bp_group_hierarchy_get_groups_tree', 10, 2 );
		
		/** 'bp_page_title' disappears after 1.2 */
		if(has_filter( 'bp_page_title' )) {
			add_filter( 'bp_page_title', 'bp_group_hierarchy_group_tree_title', 10, 2 );
		} else {
			add_filter( 'wp_title', 'bp_group_hierarchy_group_tree_title', 10, 3 );
		}
		
		if($bp->current_action == '' && !isset($_POST['object'])) {
			wp_enqueue_script('bp-group-hierarchy-tree-script');
			wp_enqueue_style('bp-group-hierarchy-tree-style');
			if($template = apply_filters('bp_located_template',locate_template( array( "tree/index.php" ), false ), "tree/index.php" )) {
				load_template($template);
				die;
			}
			die(__('Failed to load the requested template.','bp-group-hierarchy'));
		}
		
	} else if(bp_is_groups_component() && $bp->current_action == '' && $bp->group_hierarchy->extension_settings['show_group_tree']) {
		wp_enqueue_script('bp-group-hierarchy-tree-script');
		wp_enqueue_style('bp-group-hierarchy-tree-style');

		if(floatval(BP_VERSION) > 1.3) {
			add_action( 'bp_groups_directory_group_filter', 'bp_group_hierarchy_tab' );
		} else {
			add_action( 'bp_groups_directory_group_types', 'bp_group_hierarchy_tab' );
		}
	}
	
}
add_action( 'init', 'bp_group_hierarchy_extension_init' );

?>