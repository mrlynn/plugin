<?php

//catch meetings without locations and save them as a draft
add_filter('wp_insert_post_data', 'tsml_insert_post_check', '99', 2);
function tsml_insert_post_check($post) {
	if (($post['post_type'] == TSML_TYPE_MEETINGS) && empty($post['post_parent']) && ($post['post_status'] == 'publish')) {
		$post['post_status'] = 'draft';
	}
	return $post;
}

//handle all the metadata, location
add_action('save_post', 'tsml_save_post');
function tsml_save_post(){
	global $post, $tsml_nonce, $wpdb;

	//security
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!isset($post->ID) || !current_user_can('edit_post', $post->ID)) return;
	if (!isset($_POST['tsml_nonce']) || !wp_verify_nonce($_POST['tsml_nonce'], $tsml_nonce)) return;
	if (!isset($_POST['post_type']) || ($_POST['post_type'] != TSML_TYPE_MEETINGS)) return;
		
	//save ordinary meeting metadata
	if (!empty($_POST['region'])) update_post_meta($post->ID, 'region', intval($_POST['region'])); //cache region on meeting
	if (!empty($_POST['types']) && is_array($_POST['types'])) {
		//check to make sure not open and closed
		if (in_array('C', $_POST['types']) && in_array('O', $_POST['types'])) {
			$_POST['types'] = array_diff($_POST['types'], array('C'));
		}
		update_post_meta($post->ID, 'types', array_map('esc_attr', $_POST['types']));
	}

	//day could be null for appointment meeting
	if (strlen($_POST['day'])) {
		update_post_meta($post->ID, 'day', intval($_POST['day']));
		if (!empty($_POST['time'])) update_post_meta($post->ID, 'time', sanitize_text_field($_POST['time']));
		if (!empty($_POST['end_time'])) update_post_meta($post->ID, 'end_time', sanitize_text_field($_POST['end_time']));
	} else {
		update_post_meta($post->ID, 'day', '');
		update_post_meta($post->ID, 'time', '');
		update_post_meta($post->ID, 'end_time', '');
	}

	//exit here if the location is not ready
	if (empty($_POST['formatted_address']) || empty($_POST['latitude']) || empty($_POST['longitude'])) {
		return;
	}
	
	//save location information (set this value or get caught in a loop)
	$_POST['post_type'] = TSML_TYPE_LOCATIONS;
	
	//see if address is already in the database
	if ($locations = get_posts('post_type=' . TSML_TYPE_LOCATIONS . '&numberposts=1&orderby=id&order=ASC&meta_key=formatted_address&meta_value=' . sanitize_text_field($_POST['formatted_address']))) {
		$location_id = $locations[0]->ID;
		wp_update_post(array(
			'ID'			=> $location_id,
			'post_title'	=> sanitize_text_field($_POST['location']),
			'post_content'  => sanitize_text_field($_POST['location_notes']),
		));
	} else {
		$location_id = wp_insert_post(array(
			'post_title'	=> sanitize_text_field($_POST['location']),
		  	'post_type'		=> TSML_TYPE_LOCATIONS,
		  	'post_status'	=> 'publish',
			'post_content'  => sanitize_text_field($_POST['location_notes']),
		));
	}

	//update address & info on location
	update_post_meta($location_id, 'formatted_address',	sanitize_text_field($_POST['formatted_address']));
	update_post_meta($location_id, 'address',			sanitize_text_field($_POST['address']));
	update_post_meta($location_id, 'city',				sanitize_text_field($_POST['city']));
	update_post_meta($location_id, 'state',				sanitize_text_field($_POST['state']));
	update_post_meta($location_id, 'postal_code',		sanitize_text_field($_POST['postal_code']));
	update_post_meta($location_id, 'country',			sanitize_text_field($_POST['country']));
	update_post_meta($location_id, 'latitude',			floatval($_POST['latitude']));
	update_post_meta($location_id, 'longitude',			floatval($_POST['longitude']));
	update_post_meta($location_id, 'region',			intval($_POST['region']));

	//update region caches for other meetings at this location
	$meetings = tsml_get_meetings(array('location_id' => $location_id));
	foreach ($meetings as $meeting) update_post_meta($meeting['id'], 'region', intval($_POST['region']));

	//set parent on this post (and post status?) without re-triggering the save_posts hook
	$wpdb->get_var($wpdb->prepare('UPDATE ' . $wpdb->posts . ' SET post_parent = %d, post_status = %s WHERE ID = %d', $location_id, sanitize_text_field($_POST['post_status']), $post->ID));

	//deleted orphaned locations
	tsml_delete_orphaned_locations();
	
	//save group information (set this value or get caught in a loop)
	$_POST['post_type'] = TSML_TYPE_GROUPS;
	
	if (empty($_POST['group'])) {
		delete_post_meta($post->ID, 'group_id');
		if (!empty($_POST['apply_group_to_location'])) {
			foreach ($meetings as $meeting) delete_post_meta($meeting['id'], 'group_id'); 	
		}
	} else {
		if ($group_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->posts . ' WHERE post_type = "%s" AND post_title = "%s" ORDER BY id', TSML_TYPE_GROUPS, sanitize_text_field(stripslashes($_POST['group']))))) {
			wp_update_post(array(
				'ID'			=> $group_id,
				'post_title'	=> sanitize_text_field($_POST['group']),
				'post_content'  => sanitize_text_field($_POST['group_notes']),
			));
		} else {
			$group_id = wp_insert_post(array(
			  	'post_type'		=> TSML_TYPE_GROUPS,
			  	'post_status'	=> 'publish',
				'post_title'	=> sanitize_text_field($_POST['group']),
				'post_content'  => sanitize_text_field($_POST['group_notes']),
			));
		}
	
		//save to meetings(s)
		if (empty($_POST['apply_group_to_location'])) {
			update_post_meta($post->ID, 'group_id', $group_id);
		} else {
			foreach ($meetings as $meeting) update_post_meta($meeting['id'], 'group_id', $group_id); 	
		}

		//contact info
		for ($i = 1; $i <= GROUP_CONTACT_COUNT; $i++) {
			foreach (array('name', 'email', 'phone') as $field) {
				if (empty($_POST['contact_' . $i . '_' . $field])) {
					delete_post_meta($group_id, 'contact_' . $i . '_' . $field); 
				} else {
					update_post_meta($group_id, 'contact_' . $i . '_' . $field, sanitize_text_field($_POST['contact_' . $i . '_' . $field]));
				}
			}
		}
		
		//last contact
		if (!empty($_POST['last_contact']) && ($last_contact = strtotime(sanitize_text_field($_POST['last_contact'])))) {
			update_post_meta($group_id, 'last_contact', date('Y-m-d', $last_contact));
		} else {
			delete_post_meta($group_id, 'last_contact');
		}
		
	}

	//delete orphaned groups
	if ($groups_in_use = $wpdb->get_col('SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = "group_id"')) {
		$orphans = get_posts('post_type=' . TSML_TYPE_GROUPS . '&numberposts=-1&exclude=' . implode(',', $groups_in_use));
		foreach ($orphans as $orphan) wp_delete_post($orphan->ID);
	}
	
	//update types in use
	tsml_update_types_in_use();
}