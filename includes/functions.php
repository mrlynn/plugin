<?php

//function: enqueue assets for public or admin page
//used: in templates and on admin_edit.php
function tsml_assets() {
	global $tsml_types, $tsml_program, $tsml_google_api_key, $tsml_google_overrides;
		
	//google maps api needed for maps and address verification, can't be onboarded
	wp_enqueue_script('google_maps_api', '//maps.googleapis.com/maps/api/js?key=' . $tsml_google_api_key);
	
	if (is_admin()) {
		//dashboard page assets
		wp_enqueue_style('tsml_admin_css', plugins_url('../assets/css/admin.min.css', __FILE__));
		wp_enqueue_script('tsml_admin_js', plugins_url('../assets/js/admin.min.js', __FILE__), array('jquery'), '', true);
		wp_localize_script('tsml_admin_js', 'myAjax', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'google_api_key' => $tsml_google_api_key,
			'google_overrides' => json_encode($tsml_google_overrides),
		));
		wp_enqueue_script('typeahead_js', plugins_url('../assets/js/typeahead.bundle.js', __FILE__), array('jquery'), '', true);
	} else {
		//public page assets
		wp_enqueue_style('bootstrap_css', plugins_url('../assets/css/bootstrap.min.css', __FILE__));
		wp_enqueue_script('bootstrap_js', plugins_url('../assets/js/bootstrap.min.js', __FILE__), array('jquery'), '', true);
		wp_enqueue_script('tsml_public_js', plugins_url('../assets/js/public.min.js', __FILE__), array('jquery'), '', true);
		wp_localize_script('tsml_public_js', 'myAjax', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'types' => $tsml_types[$tsml_program],
		));
		wp_enqueue_style('tsml_public_css', plugins_url('../assets/css/public.min.css', __FILE__));
		wp_enqueue_script('validate_js', plugins_url('../assets/js/jquery.validate.min.js', __FILE__), array('jquery'), '', true);
	}
}

//called by register_activation_hook in 12-step-meeting-list.php
//hands off to tsml_custom_post_types
function tsml_change_activation_state() {
	tsml_custom_post_types();
	flush_rewrite_rules();
}

//function: register custom post types
//used: 	init.php on every request, also meeting.php in plugin activation hook
function tsml_custom_post_types() {
	global $tsml_regions;
	
	register_taxonomy('region', TSML_TYPE_MEETINGS, array(
		'label' => 'Region', 
		'labels' => array('menu_name'=>'Regions'),
		'hierarchical' => true,
	));

	//build quick access array of regions
	$tsml_regions = tsml_get_regions();

	register_post_type(TSML_TYPE_MEETINGS,
		array(
			'labels'		=> array(
				'name'			=>	__('Meetings', '12-step-meeting-list'),
				'singular_name'	=>	__('Meeting', '12-step-meeting-list'),
				'not_found'		=>	__('No meetings added yet.', '12-step-meeting-list'),
				'add_new_item'	=>	__('Add New Meeting', '12-step-meeting-list'),
				'search_items'	=>	__('Search Meetings', '12-step-meeting-list'),
				'edit_item'		=>	__('Edit Meeting', '12-step-meeting-list'),
				'view_item'		=>	__('View Meeting', '12-step-meeting-list'),
			),
			'supports'		=> array('title'),
			'public'		=> true,
			'has_archive'	=> true,
			'menu_icon'		=> 'dashicons-groups',
			'rewrite'		=> array('slug'=>'meetings'),
		)
	);

	register_post_type(TSML_TYPE_LOCATIONS,
		array(
			'taxonomies'	=> array('region'),
			'supports'		=> array('title'),
			'public'		=> true,
			'show_ui'		=> false,
			'has_archive'	=> true,
			'capabilities'	=> array('create_posts'=>false),
			'rewrite'		=> array('slug'=>'locations'),
		)
	);	

	register_post_type(TSML_TYPE_GROUPS,
		array(
			'supports'		=> array('title'),
			'public'		=> true,
			'show_ui'		=> false,
			'has_archive'	=> true,
			'capabilities'	=> array('create_posts'=>false),
			'rewrite'		=> array('slug'=>'groups'),
		)
	);	
}

//fuction:	define custom meeting types for your area
//used:		theme's functions.php
function tsml_custom_types($types) {
	global $tsml_types, $tsml_program;
	foreach ($types as $key=>$value) {
		$tsml_types[$tsml_program][$key] = $value;
	}
	asort($tsml_types[$tsml_program]);
}

//function: deletes all orphaned locations (has no meetings associated)
//used:		save_post filter
function tsml_delete_orphaned_locations() {

	//get all active location_ids
	$active_location_ids = array();
	$meetings = tsml_get_all_meetings();
	foreach ($meetings as $meeting) {
		$active_location_ids[] = $meeting->post_parent;
	}

	//get all location ids
	$all_location_ids = array();
	$locations = tsml_get_all_locations();
	foreach ($locations as $location) {
		$all_location_ids[] = $location->ID;
	}

	//foreach location id not active, delete it
	$inactive_location_ids = array_diff($all_location_ids, $active_location_ids);
	foreach($inactive_location_ids as $location_id) {
		wp_delete_post($location_id, true);
	}
}

//set content type for emails to html, remember to remove after use
//used by tsml_feedback()
function tsml_email_content_type_html() {
	return 'text/html';
}

//clear google address cache (only need to do this if the parsing logic changes)
add_action('wp_ajax_tsml_cache', 'tsml_clear_address_cache');
function tsml_clear_address_cache() {
	delete_option('tsml_addresses');
	die('address cache cleared!');	
}

//function: receives AJAX from single-meetings.php, sends email
add_action('wp_ajax_tsml_feedback', 'tsml_feedback');
add_action('wp_ajax_nopriv_tsml_feedback', 'tsml_feedback');
function tsml_feedback() {
	global $tsml_feedback_addresses, $tsml_nonce;
	
    $address = sanitize_text_field($_POST['tsml_address']);
    $city    = sanitize_text_field($_POST['tsml_city']);
    $state   = sanitize_text_field($_POST['tsml_state']);
    $postal   = sanitize_text_field($_POST['tsml_postal_code']);
    $name    = sanitize_text_field($_POST['tsml_name']);
    $email  = sanitize_email($_POST['tsml_email']);
    $message  = stripslashes(implode('<br>', array_map('sanitize_text_field', explode("\n", $_POST['tsml_message']))));

    //append footer to message
    $message .= '<br><br>Address: '.$address.'<br>City: '.$city.'<br>State: '.$state.'<br>Postal Code: '.$postal.'<br><br><hr>Edit meeting: <a href="' . $_POST['tsml_url'] . '">' . $_POST['tsml_url'] . '</a>';

	//sanitize input
	
	//email vars
	$subject  = '[12 Step Meeting List] Meeting Feedback Form';
	$headers  = 'From: ' . $name . ' <' . $email . '>' . "\r\n";

	if (!isset($_POST['tsml_nonce']) || !wp_verify_nonce($_POST['tsml_nonce'], $tsml_nonce)) {
		echo 'Error: nonce value not set correctly. Email was not sent.';
	} elseif (empty($tsml_feedback_addresses) || empty($name) || !is_email($email) || empty($message)) {
		echo 'Error: required form value missing. Email was not sent.';
	} else {
		//send HTML email
		add_filter('wp_mail_content_type', 'tsml_email_content_type_html');
		if (wp_mail($tsml_feedback_addresses, $subject, $message, $headers)) {
			echo 'Thank you for your feedback.';
		} else {
			global $phpmailer;
			if (!empty($phpmailer->ErrorInfo)) {
				echo 'Error: ' . $phpmailer->ErrorInfo;
			} else {
				echo 'An error occurred while sending email!';
			}
		}
		remove_filter('wp_mail_content_type', 'tsml_email_content_type_html');
	}
	
	exit;
}

//function: takes 0, 18:30 and returns Sunday, 6:30 pm (depending on your settings)
//used:		admin_edit.php, archive-meetings.php, single-meetings.php
function tsml_format_day_and_time($day, $time, $separator=', ', $short=false) {
	global $tsml_days;
	if (empty($tsml_days[$day]) || empty($time)) return $short ? __('Appt', '12-step-meeting-list') : __('Appointment', '12-step-meeting-list');
	return ($short ? substr($tsml_days[$day], 0, 3) : $tsml_days[$day]) . $separator . '<time>' . tsml_format_time($time) . '</time>';
}

//function:	appends men or women if type present
//used:		archive-meetings.php
function tsml_format_name($name, $types=array()) {
	if (in_array('Men', $types) || in_array('M', $types)) {
		$name .= ' <small>' . __('Men', '12-step-meeting-list') . '</small>';
	} elseif (in_array('Women', $types) || in_array('W', $types)) {
		$name .= ' <small>' . __('Women', '12-step-meeting-list') . '</small>';
	}
	return $name;
}

//function: takes 18:30 and returns 6:30 pm (depending on your settings)
//used:		tsml_get_meetings(), single-meetings.php, admin_lists.php
function tsml_format_time($string) {
	if (empty($string)) return __('Appointment', '12-step-meeting-list');
	if ($string == '12:00') return __('Noon', '12-step-meeting-list');
	if ($string == '23:59' || $string == '00:00') return __('Midnight', '12-step-meeting-list');
	$date = strtotime($string);
	return date(get_option('time_format'), $date);
}

//function: takes a time string, eg 6:30 pm, and returns 18:30
//used:		tsml_import(), tsml_time_duration()
function tsml_format_time_reverse($string) {
	$time_parts = date_parse($string);
	return sprintf('%02d', $time_parts['hour']) . ':' . sprintf('%02d', $time_parts['minute']);
}

//function: get all locations in the system
//used:		tsml_group_count()
function tsml_get_all_groups($status='any') {
	return get_posts('post_type=' . TSML_TYPE_GROUPS . '&post_status=' . $status . '&numberposts=-1');
}

//function: get all locations in the system
//used:		tsml_location_count(), tsml_import(), tsml_delete_orphaned_locations(), and admin_import.php
function tsml_get_all_locations($status='any') {
	return get_posts('post_type=' . TSML_TYPE_LOCATIONS . '&post_status=' . $status . '&numberposts=-1');
}

//function: get all meetings in the system
//used:		tsml_meeting_count(), tsml_import(), tsml_delete_orphaned_locations(), and admin_import.php
function tsml_get_all_meetings($status='any') {
	return get_posts('post_type=' . TSML_TYPE_MEETINGS . '&post_status=' . $status . '&numberposts=-1');
}

//function: get all regions in the system
//used:		tsml_region_count(), tsml_import() and admin_import.php
function tsml_get_all_regions($status='any') {
	return get_terms('region', array('fields'=>'ids', 'hide_empty'=>false));
}

//function: get all locations with full location information
//used: tsml_import()
function tsml_get_locations() {
	global $tsml_regions;

	$locations = array();
	
	# Get all locations
	$posts = tsml_get_all_locations('publish');

	# Make an array of all locations
	foreach ($posts as $post) {
		$tsml_custom = get_post_meta($post->ID);
		$locations[] = array(
			'id'				=> $post->ID,
			'location'			=> $post->post_title,
			'formatted_address' => $tsml_custom['formatted_address'][0],
			'address'			=> $tsml_custom['address'][0],
			'city'				=> $tsml_custom['city'][0],
			'state'				=> $tsml_custom['state'][0],
			'postal_code'		=> $tsml_custom['postal_code'][0],
			'country'			=> $tsml_custom['country'][0],
			'latitude'			=> $tsml_custom['latitude'][0],
			'longitude'			=> $tsml_custom['longitude'][0],
			'region_id'			=> $tsml_custom['region'][0],
			'region'			=> $tsml_regions[$tsml_custom['region'][0]],
			'location_url'		=> get_permalink($post->ID),
			'location_slug'		=> $post->post_name,
			'location_notes'	=> $post->post_content,
			'location_updated'	=> $post->post_modified_gmt,
		);
	}
	
	return $locations;
}

//function: get meetings based on unsanitized $arguments
//used:		tsml_meetings_api(), single-locations.php, archive-meetings.php 
function tsml_get_meetings($arguments=array()) {
	global $tsml_regions;

	$meta_query = array('relation' => 'AND');

	//location_id can be an array
	if (empty($arguments['location_id'])) {
		$arguments['location_id'] = null;
	} elseif (is_array($arguments['location_id'])) {
		$arguments['location_id'] = array_map('intval', $arguments['location_id']);
	} else {
		$arguments['location_id'] = array(intval($arguments['location_id']));
	}

	//day should be in integer 0-6 
	if (isset($arguments['day']) && ($arguments['day'] !== false)) {
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'	=> 'day',
				'value'	=> intval($arguments['day']),
			),
			array(
				'key'	=> 'day',
				'value'	=> '',
			),
		);
	}

	//time should be a string 'morning', 'midday', 'evening' or 'night'
	if (!empty($arguments['time'])) {
		if ($arguments['time'] == 'morning') {
			$meta_query[] = array(
				//Morning >=4am, < 12pm
				array('key' => 'time', 'value' => array('04:00', '11:59'), 'compare' => 'BETWEEN'),
			);
		} elseif ($arguments['time'] == 'midday') {
			$meta_query[] = array(
				//Midday >=11am, < 5pm
				array('key' => 'time', 'value' => array('11:00', '16:59'), 'compare' => 'BETWEEN'),
			);
		} elseif ($arguments['time'] == 'evening') {
			$meta_query[] = array(
				//Evening >=4pm, < 9pm
				array('key' => 'time', 'value' => array('16:00', '20:59'), 'compare' => 'BETWEEN'),
			);
		} elseif ($arguments['time'] == 'night') {
			$meta_query[] = array(
				//Night >=8pm, < 5am
				'relation' => 'OR',
				array('key' => 'time', 'value' => '04:59', 'compare' => '<='),
				array('key' => 'time', 'value' => '20:00', 'compare' => '>='),
			);
		}
	}

	//region should be an integer region id
	if (!empty($arguments['region'])) {
		$region = intval($arguments['region']);
		$regions = get_term_children($region, 'region');
		if (empty($regions)) {
			$meta_query[] = array(
				'key'	=> 'region',
				'value'	=> $region,
			);
		} else {
			$regions[] = $region;
			$meta_query[] = array(
				'key'	=> 'region',
				'compare' => 'IN',
				'value'	=> $regions,
			);
		}
	}

	//todo convert this into a custom taxonomy
	if (!empty($arguments['type'])) {
		$meta_query[] = array(
			'key'	=> 'types',
			'compare'=>'LIKE',
			'value'	=> '"' . sanitize_text_field($arguments['type']) . '"',
		);
	}
	
	//group id must be an integer
	if (!empty($arguments['group_id'])) {
		$meta_query[] = array(
			'key'	=> 'group_id',
			'value'	=> intval($arguments['group_id']),
		);
	}
	
	# Get all regions with parents, need for 'sub_region' parameter below
	$regions_with_parents = array();
	$regions = get_categories(array('taxonomy' => 'region'));
	foreach ($regions as $region) {
		if ($region->parent) {
			$regions_with_parents[$region->term_id] = $region->parent;
		}
	}
	
	$meetings = $locations = $groups = array();

	# Get all groups
	$posts = get_posts(array(
		'post_type' => TSML_TYPE_GROUPS,
		'numberposts' => -1,
	));
	
	foreach ($posts as $post) {
		$groups[$post->ID] = array(
			'group_id' => $post->ID,
			'group' => $post->post_title,
			'group_notes' => $post->post_content,
		);

		//append contact info if user has permission
		if (current_user_can('edit_posts')) {
			$tsml_custom = get_post_meta($post->ID);
			$groups[$post->ID] = array_merge($groups[$post->ID], array(
				'contact_1_name'	=> array_key_exists('contact_1_name', $tsml_custom) ? $tsml_custom['contact_1_name'][0] : null,
				'contact_1_email'	=> array_key_exists('contact_1_email', $tsml_custom) ? $tsml_custom['contact_1_email'][0] : null,
				'contact_1_phone'	=> array_key_exists('contact_1_phone', $tsml_custom) ? $tsml_custom['contact_1_phone'][0] : null,
				'contact_2_name'	=> array_key_exists('contact_2_name', $tsml_custom) ? $tsml_custom['contact_2_name'][0] : null,
				'contact_2_email'	=> array_key_exists('contact_2_email', $tsml_custom) ? $tsml_custom['contact_2_email'][0] : null,
				'contact_2_phone'	=> array_key_exists('contact_2_phone', $tsml_custom) ? $tsml_custom['contact_2_phone'][0] : null,
				'contact_3_name'	=> array_key_exists('contact_3_name', $tsml_custom) ? $tsml_custom['contact_3_name'][0] : null,
				'contact_3_email'	=> array_key_exists('contact_3_email', $tsml_custom) ? $tsml_custom['contact_3_email'][0] : null,
				'contact_3_phone'	=> array_key_exists('contact_3_phone', $tsml_custom) ? $tsml_custom['contact_3_phone'][0] : null,
				'last_contact'		=> array_key_exists('last_contact', $tsml_custom) ? $tsml_custom['last_contact'][0] : null,
			));
		}
	}
	
	# Get all locations
	$posts = get_posts(array(
		'post_type'		=> TSML_TYPE_LOCATIONS,
		'numberposts'	=> -1,
	));
	
	# Make an array of all locations
	foreach ($posts as $post) {
		$tsml_custom = get_post_meta($post->ID);

		//to be implemented later
		if (empty($tsml_custom['timezone'][0])) $tsml_custom['timezone'][0] = get_option('timezone_string');
		
		//get region/subregion
		if (array_key_exists($tsml_custom['region'][0], $regions_with_parents)) {
			$region = $tsml_regions[$regions_with_parents[$tsml_custom['region'][0]]];
			$sub_region = $tsml_regions[$tsml_custom['region'][0]];
		} else {
			$region = !empty($tsml_regions[$tsml_custom['region'][0]]) ? $tsml_regions[$tsml_custom['region'][0]] : '';
			$sub_region = '';
		}
		
		$locations[$post->ID] = array(
			'address'			=> $tsml_custom['address'][0],
			'city'				=> $tsml_custom['city'][0],
			'state'				=> $tsml_custom['state'][0],
			'postal_code'		=> isset($tsml_custom['postal_code'][0]) ? $tsml_custom['postal_code'][0] : null,
			'country'			=> isset($tsml_custom['country'][0]) ? $tsml_custom['country'][0] : null,
			'latitude'			=> $tsml_custom['latitude'][0],
			'longitude'			=> $tsml_custom['longitude'][0],
			'region_id'			=> $tsml_custom['region'][0],
			'region'			=> $region,
			'sub_region'		=> $sub_region,
			'timezone'			=> $tsml_custom['timezone'][0],
			'location'			=> $post->post_title,
			'location_url'		=> get_permalink($post->ID),
			'location_slug'		=> $post->post_name,
			'location_notes'	=> $post->post_content,
			'location_updated'	=> $post->post_modified_gmt,
		);
	}
	
	# If searching, three extra queries
	$post_ids = array();
	$arguments['search'] = empty($arguments['search']) ? null : sanitize_text_field($arguments['search']);
	if (!empty($arguments['search'])) {
		$post_ids = get_posts(array(
			'post_type'			=> TSML_TYPE_MEETINGS,
			'numberposts'		=> -1,
			'fields'			=> 'ids',
			's'					=> $arguments['search'],
		));
		
		//search regions
		if ($regions = get_terms('region', array(
				'search' => $arguments['search'], 
				'fields' => 'ids', 
				'hide_empty' => false
			))) {
			$post_ids = array_merge($post_ids, get_posts(array(
				'post_type'			=> TSML_TYPE_MEETINGS,
				'numberposts'		=> -1,
				'fields'			=> 'ids',
				'meta_query'		=> array(
					array(
						'key'	=> 'region',
						'compare' => 'IN',
						'value'	=> $regions,
					),
				),
			)));
		}
		
		//search groups
		if ($groups = get_posts(array(
				'post_type'			=> TSML_TYPE_GROUPS,
				'numberposts'		=> -1,
				'fields'			=> 'ids',
				's'					=> $arguments['search'],
			))) {
			$post_ids = array_merge($post_ids, get_posts(array(
				'post_type'			=> TSML_TYPE_MEETINGS,
				'numberposts'		=> -1,
				'meta_query'		=> array(
					array(
						'key'		=> 'group_id',
						'compare'	=> 'IN',
						'value'		=> $groups,
					),
				),
				'fields'			=> 'ids',
			)));
		}
		
		//location matches
		$parents = array_merge(
			//searching title and content
			get_posts(array(
				'post_type'			=> TSML_TYPE_LOCATIONS,
				'numberposts'		=> -1,
				'fields'			=> 'ids',
				's'					=> $arguments['search'],
			)),
			//searching address
			get_posts(array(
				'post_type'			=> TSML_TYPE_LOCATIONS,
				'numberposts'		=> -1,
				'fields'			=> 'ids',
				'meta_query'		=> array(
					array(
						'key'		=> 'formatted_address',
						'value'		=> $arguments['search'],
						'compare'	=> 'LIKE',
					),
				),
			))
		);
		
		if (count($parents)) {
			$post_ids = array_unique(array_merge($post_ids, get_posts(array(
				'post_type'			=> TSML_TYPE_MEETINGS,
				'numberposts'		=> -1,
				'fields'			=> 'ids',
				'post_parent__in'	=> $parents,
			))));
		}
		
		if (empty($post_ids)) return array();
	}
	
	# Search meetings
	$posts = get_posts(array(
		'post_type'			=> TSML_TYPE_MEETINGS,
		'numberposts'		=> -1,
		'meta_query'		=> $meta_query,
		'post__in'			=> $post_ids,
		'post_parent__in'	=> $arguments['location_id'],
	));

	//dd($meta_query);
	//die('count was ' . count($posts));
	//dd($post_ids);

	//need this later, need to supply default values to groupless meetings
	$null_group_info = (current_user_can('edit_posts')) ? array('group' => null, 'group_notes' => null, 'contact_1_name' => null, 'contact_1_email' => null, 'contact_1_phone' => null, 'contact_2_name' => null, 'contact_2_email' => null, 'contact_2_phone' => null, 'contact_3_name' => null, 'contact_3_email' => null, 'contact_3_phone' => null, ) : array('group' => null, 'group_notes' => null);

	# Make an array of the meetings
	foreach ($posts as $post) {
		//shouldn't ever happen, but just in case
		if (empty($locations[$post->post_parent])) continue;

		$tsml_custom = get_post_meta($post->ID);

		$array = array_merge(array(
			'id'			=>$post->ID,
			'name'			=>$post->post_title,
			'slug'			=>$post->post_name,
			'notes'			=>$post->post_content,
			'updated'		=>$post->post_modified_gmt,
			'location_id'	=>$post->post_parent,
			'url'			=>get_permalink($post->ID),
			'time'			=>@$tsml_custom['time'][0],
			'end_time'		=>@$tsml_custom['end_time'][0],
			'time_formatted'=>tsml_format_time($tsml_custom['time'][0]),
			'day'			=>@$tsml_custom['day'][0],
			'types'			=>empty($tsml_custom['types'][0]) ? array() : unserialize($tsml_custom['types'][0]),
		), $locations[$post->post_parent]);
		
		# Append group info to meeting
		if (!empty($tsml_custom['group_id'][0]) && array_key_exists($tsml_custom['group_id'][0], $groups)) {
			$array = array_merge($array, $groups[$tsml_custom['group_id'][0]]);
		} else {
			$array = array_merge($array, $null_group_info);
		}
		
		$meetings[] = $array;
	}

	//dd($meetings);

	usort($meetings, 'tsml_sort_meetings');

	//tsml_report_memory();
	
	return $meetings;
}

//return spelled-out meeting types
function tsml_meeting_types($types) {
	global $tsml_types, $tsml_program;
	$return = array();
	foreach ($types as $type) {
		if (array_key_exists($type, $tsml_types[$tsml_program])) {
			$return[] = $tsml_types[$tsml_program][$type];
		}
	}
	sort($return);
	return implode(', ', $return);
}

//function: sort an array of meetings
//used: as a callback in tsml_get_meetings()
//method: sort by 
//	1) day, following "week starts on" user preference, with appointment meetings last, 
//	2) followed by time, where the day starts at 5am, 
//	3) followed by location name, 
//	4) followed by meeting name
function tsml_sort_meetings($a, $b) {
	global $tsml_days_order;
	$a_day_index = strlen($a['day']) ? array_search($a['day'], $tsml_days_order) : false;
	$b_day_index = strlen($b['day']) ? array_search($b['day'], $tsml_days_order) : false;
	if ($a_day_index === false && $b_day_index !== false) {
		return 1;
	} elseif ($a_day_index !== false && $b_day_index === false) {
		return -1;
	} elseif ($a_day_index != $b_day_index) {
		return $a_day_index - $b_day_index;
	} else {
		//days are the same or both null
		if ($a['time'] != $b['time']) {
			/*
			if (substr_count($a['time'], ':')) { //move meetings earlier than 5am to the end of the list
				$a_time = explode(':', $a['time'], 2);
				if (intval($a_time[0]) < 5) $a_time[0] = sprintf("%02d",  $a_time[0] + 24);
				$a_time = implode(':', $a_time);
			}
			if (substr_count($b['time'], ':')) { //move meetings earlier than 5am to the end of the list
				$b_time = explode(':', $b['time'], 2);
				if (intval($b_time[0]) < 5) $b_time[0] = sprintf("%02d",  $b_time[0] + 24);
				$b_time = implode(':', $b_time);
			}*/
			$a_time = ($a['time'] == '00:00') ? '23:59' : $a['time'];
			$b_time = ($b['time'] == '00:00') ? '23:59' : $b['time'];
			return strcmp($a_time, $b_time);
		} else {
			if ($a['location'] != $b['location']) {
				return strcmp($a['location'], $b['location']);
			} else {
				return strcmp($a['name'], $b['name']);
			}
		}
	}
}

//function: template tag to get location, attach custom fields to it
//used: single-locations.php
function tsml_get_location() {
	$location = get_post();
	$custom = get_post_meta($location->ID);
	foreach ($custom as $key=>$value) {
		$location->{$key} = htmlentities($value[0], ENT_QUOTES);
	}
	$location->post_title	= htmlentities($location->post_title, ENT_QUOTES);
	$location->notes 		= nl2br(esc_html($location->post_content));
	return $location;
}

//function: template tag to get meeting and location, attach custom fields to it
//used: single-meetings.php
function tsml_get_meeting() {
	global $tsml_types, $tsml_program;
	
	$meeting				= get_post();
	$location				= get_post($meeting->post_parent);
	$custom					= array_merge(get_post_meta($meeting->ID), get_post_meta($location->ID));
	foreach ($custom as $key=>$value) {
		$meeting->{$key} = ($key == 'types') ? $value[0] : htmlentities($value[0], ENT_QUOTES);
	}
	$meeting->types				= empty($meeting->types) ? array() : unserialize($meeting->types);
	$meeting->post_title		= htmlentities($meeting->post_title, ENT_QUOTES);
	$meeting->location			= htmlentities($location->post_title, ENT_QUOTES);
	$meeting->notes 			= nl2br(esc_html($meeting->post_content));
	$meeting->location_notes	= nl2br(esc_html($location->post_content));
	
	$meeting->location_meetings = tsml_get_meetings(array('location_id' => $location->ID));

	//if meeting is part of a group, include group info
	if ($meeting->group_id) {
		$group = get_post($meeting->group_id);
		$meeting->group = htmlentities($group->post_title, ENT_QUOTES);
		$meeting->group_notes = nl2br(esc_html($group->post_content));
		$group_custom = get_post_meta($meeting->group_id);
		foreach ($group_custom as $key=>$value) {
			$meeting->{$key} = $value[0];
		}
	} else {
		$meeting->group_id = null;
		$meeting->group = null;
	}
	
	//sort types alphabetically
	foreach ($meeting->types as &$type) $type = $tsml_types[$tsml_program][trim($type)];
	sort($meeting->types);
	
	return $meeting;
}

//function: load all regions into a flat array
//used:		tsml_custom_post_types(), tsml_regions_api() (deprecated)
function tsml_get_regions() {
	$tsml_regions = array();
	$region_terms = get_terms('region', array('hide_empty' => false));
	foreach ($region_terms as $region) $tsml_regions[$region->term_id] = $region->name;
	return $tsml_regions;
}

//api ajax function
//used by theme, web app, mobile app
add_action('wp_ajax_meetings', 'tsml_meetings_api');
add_action('wp_ajax_nopriv_meetings', 'tsml_meetings_api');

function tsml_meetings_api() {
	if (!headers_sent()) header('Access-Control-Allow-Origin: *');
	if (empty($_POST)) wp_send_json(tsml_get_meetings($_GET));
	wp_send_json(tsml_get_meetings($_POST));
};

//csv function
//useful for exporting data
add_action('wp_ajax_csv', 'tsml_meetings_csv');
add_action('wp_ajax_nopriv_csv', 'tsml_meetings_csv');

function tsml_meetings_csv() {

	//going to need this later
	global $tsml_days, $tsml_types, $tsml_program;

	//get data source
	$meetings = tsml_get_meetings();

	//define columns to output
	$columns = array(
		'time' =>				__('Time', '12-step-meeting-list'),
		'end_time' =>			__('End Time', '12-step-meeting-list'),
		'day' =>				__('Day', '12-step-meeting-list'),
		'name' =>				__('Name', '12-step-meeting-list'),
		'location' =>			__('Location', '12-step-meeting-list'),
		'address' =>			__('Address', '12-step-meeting-list'),
		'city' =>				__('City', '12-step-meeting-list'),
		'state' =>				__('State', '12-step-meeting-list'),
		'postal_code' =>		__('Postal Code', '12-step-meeting-list'),
		'country' =>			__('Country', '12-step-meeting-list'),
		'region' =>				__('Region', '12-step-meeting-list'),
		'sub_region' =>			__('Sub Region', '12-step-meeting-list'),
		'types' =>				__('Types', '12-step-meeting-list'),
		'notes' =>				__('Notes', '12-step-meeting-list'),
		'location_notes' =>		__('Location Notes', '12-step-meeting-list'),
		'group' => 				__('Group', '12-step-meeting-list'),
		'group_notes' => 		__('Group Notes', '12-step-meeting-list'),
		'updated' =>			__('Updated', '12-step-meeting-list'),
	);
	
	//append contact info if user has permission
	if (current_user_can('edit_posts')) {
		$columns = array_merge($columns, array(
			'contact_1_name' =>		__('Contact 1 Name', '12-step-meeting-list'),
			'contact_1_email' =>	__('Contact 1 Email', '12-step-meeting-list'),
			'contact_1_phone' =>	__('Contact 1 Phone', '12-step-meeting-list'),
			'contact_2_name' =>		__('Contact 2 Name', '12-step-meeting-list'),
			'contact_2_email' =>	__('Contact 2 Email', '12-step-meeting-list'),
			'contact_2_phone' =>	__('Contact 2 Phone', '12-step-meeting-list'),
			'contact_3_name' =>		__('Contact 3 Name', '12-step-meeting-list'),
			'contact_3_email' =>	__('Contact 3 Email', '12-step-meeting-list'),
			'contact_3_phone' =>	__('Contact 3 Phone', '12-step-meeting-list'),
			'last_contact' => 		__('Last Contact', '12-step-meeting-list'),
		));
	}

	//helper vars
	$delimiter = ',';
	$escape = '"';
	
	//do header
	$return = implode($delimiter, array_values($columns)) . PHP_EOL;

	//append meetings
	foreach ($meetings as $meeting) {
		$line = array();
		foreach ($columns as $column=>$value) {
			if ($column == 'time') {
				$line[] = tsml_format_time($meeting[$column]);
			} elseif ($column == 'day') {
				$line[] = $tsml_days[$meeting[$column]];
			} elseif ($column == 'types') {
				$types = $meeting[$column];
				foreach ($types as &$type) $type = $tsml_types[$tsml_program][trim($type)];
				sort($types);
				$line[] = $escape . implode(', ', $types) . $escape;
			} elseif (strstr($column, 'notes')) {
				$line[] = $escape . strip_tags(str_replace($escape, str_repeat($escape, 2), $meeting[$column])) . $escape;
			} else {
				$line[] = $escape . str_replace($escape, '', $meeting[$column]) . $escape;
			}
		}
		$return .= implode($delimiter, $line) . PHP_EOL;
	}

	//headers to trigger file download
	header('Cache-Control: maxage=1');
	header('Pragma: public');
	header('Content-Description: File Transfer');
	header('Content-Type: text/plain');
	header('Content-Length: ' . strlen($return));
	header('Content-Disposition: attachment; filename="meetings.csv"');

	//output
	wp_die($return);
};

//get all email addresses for europe
//linked from admin_import.php
add_action('wp_ajax_contacts', 'tsml_regions_contacts');

function tsml_regions_contacts() {
	global $wpdb;
	$group_ids = $wpdb->get_col('SELECT id FROM ' . $wpdb->posts . ' WHERE post_type = "' . TSML_TYPE_GROUPS . '"');
	$emails = $wpdb->get_col('SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key IN ("contact_1_email", "contact_2_email", "contact_3_email") AND post_id IN (' . implode(',', $group_ids) . ')');
	$emails = array_unique(array_filter($emails));
	sort($emails);
	die(implode(',', $emails));
}

/*todo: consider whether we really need this
add_action('wp_ajax_regions', 'tsml_regions_api');
add_action('wp_ajax_nopriv_regions', 'tsml_regions_api');

function tsml_regions_api() {
	$output = array();
	$tsml_regions = tsml_get_regions();
	foreach ($tsml_regions as $id=>$value) {
		$output[] = array('id'=>$id, 'value'=>$value);
	}
	header('Access-Control-Allow-Origin: *');
	wp_send_json($output);
};*/

//sanitize and import meeting data
//used by admin_import.php
function tsml_import($meetings, $delete=false) {
	global $tsml_types, $tsml_program, $tsml_days, $wpdb, $tsml_google_api_key, $tsml_google_overrides;

	//allow theme-defined function to reformat CSV ahead of import (for New Hampshire)
	if (function_exists('tsml_import_reformat')) {
		$meetings = tsml_import_reformat($meetings);
	}
	
	//convert the array to UTF-8
	array_walk_recursive($meetings, function(&$item, $key) {
		if (!mb_detect_encoding($item, 'utf-8', true)) {
			$item = utf8_encode($item);
		}
	});
	
	//uppercasing for value matching later
	$upper_types = array_map('strtoupper', $tsml_types[$tsml_program]);
	$upper_days = array_map('strtoupper', $tsml_days);
		
	//counter of successful meetings imported
	$success = $geocoded = 0;
	
	//counter for errors
	$row_counter = 1;

	//arrays we will need
	$addresses = $existing_addresses = $locations = $groups = array();
	
	//crash if no data
	if (count($meetings) < 2) return tsml_alert(__('Nothing was imported because no data rows were found.', '12-step-meeting-list'), 'error');
	
	//get header
	$header = array_shift($meetings);
	$header = array_map('sanitize_title_with_dashes', $header);
	$header_count = count($header);
	
	//check header for required fields
	if (!in_array('address', $header) ||
		(!in_array('city', $header) && !in_array('state', $header) && !in_array('postal_code', $header))
	) {
		return tsml_alert(__('Either Address, or City, State and Postal Code are required.', '12-step-meeting-list'), 'error');
	}

	//all the data is set, now delete everything
	if ($delete) {
		//must be done with SQL statements becase there could be thousands of records to delete
		if ($post_ids = implode(',', $wpdb->get_col('SELECT id FROM ' . $wpdb->posts . ' WHERE post_type IN ("' . TSML_TYPE_MEETINGS . '", "' . TSML_TYPE_LOCATIONS . '", "' . TSML_TYPE_GROUPS . '")'))) {
			$wpdb->query('DELETE FROM ' . $wpdb->posts . ' WHERE id IN (' . $post_ids . ')');
			$wpdb->query('DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . $post_ids . ')');
			$wpdb->query('DELETE FROM ' . $wpdb->term_relationships . ' WHERE object_id IN (' . $post_ids . ')');
		}
		if ($term_ids = implode(',', $wpdb->get_col('SELECT term_id FROM ' . $wpdb->term_taxonomy . ' WHERE taxonomy = "region"'))) {
			$wpdb->query('DELETE FROM ' . $wpdb->terms . ' WHERE term_id IN (' . $term_ids . ')');
			$wpdb->query('DELETE FROM ' . $wpdb->term_taxonomy . ' WHERE term_id IN (' . $term_ids . ')');
		}
	} else {
		$all_locations = tsml_get_locations();
		foreach ($all_locations as $location) $existing_addresses[$location['formatted_address']] = $location['id'];

		//get all the existing groups
		$all_groups = tsml_get_all_groups();
		foreach ($all_groups as $group)	$groups[$group->post_title] = $group->ID;
	}
	
	//loop through data and group by address
	foreach ($meetings as $meeting) {
		$row_counter++;

		//sanitize fields
		$meeting = array_map('tsml_import_sanitize_field', $meeting);
		
		//skip empty rows
		if (!strlen(implode($meeting))) continue;

		//check length
		if ($header_count != count($meeting)) {
			return tsml_alert('Row #' . $row_counter . ' has ' . count($meeting) . ' columns while the header has ' . $header_count . '.', 'error');
		}
		
		//associate, sanitize
		$meeting = array_combine($header, $meeting);
		foreach ($meeting as $key => $value) {
			if (in_array($key, array('notes', 'location-notes', 'group-notes'))) {
				$meeting[$key] = trim(strip_tags($value));
			} else {
				$meeting[$key] = sanitize_text_field($value);
			}
		}
		
		//if location is missing, use address
		if (empty($meeting['location'])) $meeting['location'] = $meeting['address'];
	
		//sanitize time & day
		if (empty($meeting['time']) || empty($meeting['day'])) {
			$meeting['time'] = $meeting['end_time'] = $meeting['day'] = ''; //by appointment

			//if meeting name missing, use location
			if (empty($meeting['name'])) $meeting['name'] = $meeting['location'] . ' by Appointment';
		} else {
			$meeting['time'] = tsml_format_time_reverse($meeting['time']);
			$meeting['end_time'] = tsml_format_time_reverse($meeting['end_time']);
			
			if (!in_array(strtoupper($meeting['day']), $upper_days)) return tsml_alert('"' . $meeting['day'] . '" is an invalid value for day at row #' . $row_counter . '.', 'error');
			$meeting['day'] = array_search(strtoupper($meeting['day']), $upper_days);

			//if meeting name missing, use location, day, and time
			if (empty($meeting['name'])) $meeting['name'] = $meeting['location'] . ' ' . $tsml_days[$meeting['day']] . 's at ' . tsml_format_time($meeting['time']);
		}

		//sanitize address, remove everything starting with @ (consider other strings as well?)
		if (!empty($meeting['address']) && $pos = strpos($meeting['address'], '@')) $meeting['address'] = trim(substr($meeting['address'], 0, $pos));
		
		//google prefers USA for geocoding
		if (!empty($meeting['country']) && $meeting['country'] == 'US') $meeting['country'] = 'USA'; 
		
		//build address
		$address = array();
		if (!empty($meeting['address'])) $address[] = $meeting['address'];
		if (!empty($meeting['city'])) $address[] = $meeting['city'];
		if (!empty($meeting['state'])) $address[] = $meeting['state'];
		if (!empty($meeting['postal-code'])) {
			if ((strlen($meeting['postal-code']) < 5) && ($meeting['country'] == 'USA')) $meeting['postal-code'] = str_pad($meeting['postal-code'], 5, '0', STR_PAD_LEFT);
			$address[] = $meeting['postal-code'];	
		}
		if (!empty($meeting['country'])) $address[] = $meeting['country'];
		$address = implode(', ', $address);
		
		//check to make sure there's something to geocode
		if (empty($address)) return tsml_alert('Not enough location information at row #' . $row_counter . '.', 'error');

		//notes
		if (empty($meeting['notes'])) $meeting['notes'] = '';
		if (empty($meeting['location-notes'])) $meeting['location-notes'] = '';
		if (empty($meeting['group-notes'])) $meeting['group-notes'] = '';

		//updated
		$meeting['updated'] = empty($meeting['updated']) ? time() : strtotime($meeting['updated']);
		$meeting['post_modified'] = date('Y-m-d H:i:s', $meeting['updated']);
		$meeting['post_modified_gmt'] = date('Y-m-d H:i:s', $meeting['updated']);
		
		//default region to city if not specified
		if (empty($meeting['region']) && !empty($meeting['city'])) $meeting['region'] = $meeting['city'];
		
		//add region to taxonomy if it doesn't exist yet
		if (!empty($meeting['region'])) {
			if ($term = term_exists($meeting['region'], 'region')) {
				$meeting['region'] = $term['term_id'];
			} else {
				$term = wp_insert_term($meeting['region'], 'region');
				$meeting['region'] = $term['term_id'];
			}

			//can only have a subregion if you already have a region
			if (!empty($meeting['sub-region'])) {
				if ($term = term_exists($meeting['sub-region'], 'region', $meeting['region'])) {
					$meeting['region'] = $term['term_id'];
				} else {
					$term = wp_insert_term($meeting['sub-region'], 'region', array('parent'=>$meeting['region']));
					$meeting['region'] = $term['term_id'];
				}
			}
		}
		
		//handle groups (can't have a group if group name not specified)
		if (!empty($meeting['group'])) {
			if (!array_key_exists($meeting['group'], $groups)) {
				$group_id = wp_insert_post(array(
				  	'post_type'		=> TSML_TYPE_GROUPS,
				  	'post_status'	=> 'publish',
					'post_title'	=> $meeting['group'],
					'post_content'  => empty($meeting['group-notes']) ? '' : $meeting['group-notes'],
				));
				
				for ($i = 1; $i <= GROUP_CONTACT_COUNT; $i++) {
					foreach (array('name', 'phone', 'email') as $field) {
						if (!empty($meeting['contact-' . $i . '-' . $field])) {
							update_post_meta($group_id, 'contact_' . $i . '_' . $field, $meeting['contact-' . $i . '-' . $field]);
						}
					}					
				}

				if (!empty($meeting['last-contact']) && ($last_contact = strtotime($meeting['last-contact']))) {
					update_post_meta($group_id, 'last_contact', date('Y-m-d', $last_contact));
				}
				
				$groups[$meeting['group']] = $group_id;
			}
		}

		//sanitize types
		$types = explode(',', $meeting['types']);
		$meeting['types'] = $unused_types = array();
		foreach ($types as $type) {
			if (in_array(trim(strtoupper($type)), array_values($upper_types))) {
				$meeting['types'][] = array_search(trim(strtoupper($type)), $upper_types);
			} else {
				$unused_types[] = $type;
			}
		}
		
		//don't let a meeting be both open and closed
		if (in_array('C', $meeting['types']) && in_array('O', $meeting['types'])) {
			$meeting['types'] = array_diff($meeting['types'], array('C'));
		}
		
		//append unused types to notes
		if (count($unused_types)) {
			if (!empty($meeting['notes'])) $meeting['notes'] .= str_repeat(PHP_EOL, 2);
			$meeting['notes'] .= implode(', ', $unused_types);
		}
		
		//group by address
		if (!array_key_exists($address, $addresses)) {
			$addresses[$address] = array(
				'meetings' => array(),
				'lines' => array(),
				'region' => $meeting['region'],
				'location' => $meeting['location'],
				'notes' => $meeting['location-notes'],
			);
		}
		
		//attach meeting to address object
		$addresses[$address]['meetings'][] = array(
			'name' => $meeting['name'],
			'day' => $meeting['day'],
			'time' => $meeting['time'],
			'end_time' => $meeting['end_time'],
			'types' => $meeting['types'],
			'notes' => $meeting['notes'],
			'post_modified' => $meeting['post_modified'],
			'post_modified_gmt' => $meeting['post_modified_gmt'],
			'group' => empty($meeting['group']) ? null : $meeting['group'],
		);
		
		//attach line number for reference if geocoding fails
		$addresses[$address]['lines'][] = $row_counter;
	}
	
	//make sure script has enough time to run
	//usage limits: https://developers.google.com/maps/documentation/geocoding/
	$address_count = count($addresses);
	$seconds_needed = ceil($address_count / 5);
	$max_execution_time = ini_get('max_execution_time');
	$failed_addresses = array();
	if ($seconds_needed > $max_execution_time && !set_time_limit($seconds_needed)) {
		return tsml_alert('This script needs to geocode ' . number_format($address_count) . ' 
			addresses, which will take about ' . number_format($seconds_needed) . ' seconds. This  
			exceeds PHP\'s max_execution_time of ' . number_format($max_execution_time) . ' seconds.
			Please increase the limit in php.ini before retrying.', 'error');
	}

	//dd($addresses);
	//wp_die('exiting before geocoding ' . count($addresses) . ' addresses.');
		
	//prepare curl handle
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_HEADER => 0, 
		CURLOPT_RETURNTRANSFER => true, 
		CURLOPT_TIMEOUT => 10,
		CURLOPT_SSL_VERIFYPEER => false,
	));
	
	//address caching
	$cached_addresses = get_option('tsml_addresses', array());
	
	//loop through again and geocode the addresses, making a location
	foreach ($addresses as $original_address=>$info) {
		
		if (array_key_exists($original_address, $cached_addresses)) {
			
			//retrieve address and skip google
			extract($cached_addresses[$original_address]);
			
		} else {
			
			//request from google
			curl_setopt($ch, CURLOPT_URL, 'https://maps.googleapis.com/maps/api/geocode/json?key=' . $tsml_google_api_key . '&address=' . urlencode($original_address));
			$result = curl_exec($ch);

			//could not connect error
			if (empty($result)) {
				return tsml_alert('Could not connect to Google, error was <em>' . curl_error($ch) . '</em>', 'error');
			}
			
			//decode result
			$data = json_decode($result);
	
			if ($data->status == 'OVER_QUERY_LIMIT') {
				//if over query limit, wait two seconds and retry, or then exit		
				sleep(2);
				$data = json_decode(curl_exec($ch));
				if ($data->status == 'OVER_QUERY_LIMIT') {
					return tsml_alert('You are over your rate limit for the Google Geocoding API, you will need an API key to continue.', 'error');
				}
			} elseif ($data->status == 'OK') {
				//ok great
			} elseif ($data->status == 'ZERO_RESULTS') {
				$failed_addresses[$original_address] = $info['lines'];
				continue;
			} else {
				return tsml_alert('Google gave an unexpected response for address <em>' . $original_address . '</em>. Response was <pre>' . var_export($data, true) . '</pre>', 'error');
			}
			
			//dd($data->results[0]->address_components);
			
			//some google API results are bad, and we can override them manually
			if (array_key_exists($data->results[0]->formatted_address, $tsml_google_overrides)) {
				
				extract($tsml_google_overrides[$data->results[0]->formatted_address]);
				
			} else {
								
				//unpack response -- logic must match admin.js -> parseAddressComponents() 
				$address = $city = $state = $postal_code = $country = $point_of_interest = $neighborhood = false;
				foreach ($data->results[0]->address_components as $component) {
					if (empty($component->types) || in_array('point_of_interest', $component->types)) {
						$point_of_interest = $component->short_name;
					} elseif (in_array('neighborhood', $component->types)) {
						$neighborhood = $component->short_name;
					} elseif (in_array('street_number', $component->types)) {
						$address = $component->long_name;
					} elseif (in_array('route', $component->types)) {
						$address .= ' ' . $component->long_name;
					} elseif (in_array('locality', $component->types)) {
						$city = $component->long_name;
					} elseif (in_array('sublocality', $component->types)) {
						if (!$city) $city = $component->long_name;
					} elseif (in_array('administrative_area_level_3', $component->types)) {
						if (!$city) $city = $component->long_name;
					} elseif (in_array('administrative_area_level_1', $component->types)) {
						$state = $component->short_name;
					} elseif (in_array('postal_code', $component->types)) {
						$postal_code = $component->short_name;
					} elseif (in_array('country', $component->types)) {
						$country = $component->short_name;
					} 
				}
				
				/*
				some legitimate meeting locations have no address
				http://maps.googleapis.com/maps/api/geocode/json?address=bagram%20airfield,%20afghanistan
				http://maps.googleapis.com/maps/api/geocode/json?address=River%20Light%20Park,%20Cornwall,%20NY,%20USA
				*/
				$formatted_address = array();
	
				if (empty($address) && !empty($point_of_interest)) $address = $point_of_interest;
	
				if (empty($address) && !empty($neighborhood)) $address = $neighborhood;
				
				if (!empty($address)) $formatted_address[] = $address;
				
				if (!empty($city)) $formatted_address[] = $city;
				
				if (!empty($state)) {
					if (!empty($address) && !empty($postal_code)) {
						$formatted_address[] = $state . ' ' . $postal_code;
					} else {
						$formatted_address[] = $state;
					}
				} else {
					$formatted_address[] = $postal_code;
				}
	
				if (!empty($country)) $formatted_address[] = $country;
	
				$formatted_address = implode(', ', $formatted_address);
				
				//check for required values
				if (empty($formatted_address) || empty($data->results[0]->geometry->location->lat) || empty($data->results[0]->geometry->location->lng)) {
					$failed_addresses[$original_address] = $info['lines'];
					continue;
				}
				
				//lat and lon
				$latitude = $data->results[0]->geometry->location->lat;
				$longitude = $data->results[0]->geometry->location->lng;
			}
			
			//save in cache
			$cached_addresses[$original_address] = compact('address', 'city', 'state', 'postal_code', 'country', 'latitude', 'longitude', 'formatted_address');
			
			$geocoded++;
		}
		
		//intialize empty location if needed
		if (!array_key_exists($formatted_address, $locations)) {
			$locations[$formatted_address] = array(
				'meetings'		=>array(),
				'address'		=>$address,
				'city'			=>$city,
				'state'			=>$state,
				'postal_code'	=>$postal_code,
				'country'		=>$country,
				'region'		=>$info['region'],
				'location'		=>$info['location'],
				'notes'			=>$info['notes'],
				'latitude'		=>$latitude,
				'longitude'		=>$longitude,
			);
		}

		//attach meetings to existing location
		$locations[$formatted_address]['meetings'] = array_merge(
			$locations[$formatted_address]['meetings'],
			$info['meetings']
		);
	}
	
	update_option('tsml_addresses', $cached_addresses, 'no');
	
	//passing post_modified and post_modified_gmt to wp_insert_post() below does not seem to work
	//todo occasionally remove this to see if it is working
	add_filter('wp_insert_post_data', 'tsml_import_post_modified', 99, 2);
	
	//loop through and save everything to the database
	foreach ($locations as $formatted_address=>$location) {

		//save location if not already in the database
		if (array_key_exists($formatted_address, $existing_addresses)) {
			$location_id = $existing_addresses[$formatted_address];
		} else {
			$location_id = wp_insert_post(array(
				'post_title'	=> $location['location'],
				'post_type'		=> TSML_TYPE_LOCATIONS,
				'post_content'	=> $location['notes'],
				'post_status'	=> 'publish',
			));
		}
		
		//update location metadata
		update_post_meta($location_id, 'formatted_address',	$formatted_address);
		update_post_meta($location_id, 'address',			$location['address']);
		update_post_meta($location_id, 'city',				$location['city']);
		update_post_meta($location_id, 'state',				$location['state']);
		update_post_meta($location_id, 'postal_code',		$location['postal_code']);
		update_post_meta($location_id, 'country',			$location['country']);
		update_post_meta($location_id, 'latitude',			$location['latitude']);
		update_post_meta($location_id, 'longitude',			$location['longitude']);
		update_post_meta($location_id, 'region',			$location['region']);

		//save meetings to this location
		foreach ($location['meetings'] as $meeting) {
			$meeting_id = wp_insert_post(array(
				'post_title'		=> $meeting['name'],
				'post_type'			=> TSML_TYPE_MEETINGS,
				'post_status'		=> 'publish',
				'post_parent'		=> $location_id,
				'post_content'		=> $meeting['notes'],
				'post_modified'		=> $meeting['post_modified'],
				'post_modified_gmt'	=> $meeting['post_modified_gmt'],
			));
			update_post_meta($meeting_id, 'day',		$meeting['day']);
			update_post_meta($meeting_id, 'time',		$meeting['time']);
			update_post_meta($meeting_id, 'end_time',	$meeting['end_time']);
			update_post_meta($meeting_id, 'types',		$meeting['types']);
			update_post_meta($meeting_id, 'region',		$location['region']); //double-entry just for searching
			if (!empty($meeting['group'])) update_post_meta($meeting_id, 'group_id', $groups[$meeting['group']]);
			wp_set_object_terms($meeting_id, intval($location['region']), 'region');
			
			$success++;
		}
	}
	
	//update types in use
	tsml_update_types_in_use();

	//remove post_modified thing added earlier
	remove_filter('wp_insert_post_data', 'alter_post_modification_time', 99);
	
	//success
	if (count($failed_addresses)) {
		$message = $success ? number_format($success) . ' meetings were added successfully, however ' : '';
		$message .= 'Google rejected the following addresses:<ul style="padding-left:20px;list-style-type:square;">';
		foreach ($failed_addresses as $address=>$lines) {
			$message .= '<li><em>' . $address . '</em> on line ' . implode(', ', $lines) . '</li>';
		}
		$message .= '</ul>';
		if ($geocoded) $message .= ' (Geocoded ' . number_format($geocoded) . ' locations.)';
		return tsml_alert($message, 'error');		
	} else {
		$message = 'Successfully added ' . number_format($success) . ' meetings.';
		if ($geocoded) $message .= ' (Geocoded ' . number_format($geocoded) . ' locations.)';
		return tsml_alert($message);		
	}
}

//turn "string" into string, fix newlines
function tsml_import_sanitize_field($value) {
	//preserve <br>s as line breaks if present, otherwise clean up
	$value = preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, $value);
	$value = stripslashes($value);

	//turn "string" into string
	//$value = str_replace('""', '"', $value);
	$value = trim(trim($value, '"'));
	
	//fix newlines
	//$value = preg_split('/$\R?^/m', $value);
	//$value = array_map('trim', $value);
	//$value = trim(implode(PHP_EOL, $value));
	
	return $value;
}

//filter workaround for tsml_import()
function tsml_import_post_modified($data , $postarr) {
	if (!empty($postarr['post_modified'])) {
		$data['post_modified'] = $postarr['post_modified'];
	}
	if (!empty($postarr['post_modified_gmt'])) {
		$data['post_modified_gmt'] = $postarr['post_modified_gmt'];
	}
	return $data;
}

//remove empty rows from tsml_import()
function tsml_remove_empty_rows($a){
	$a = trim($a);
	return !empty($a);
}

//function: return an html link with query string appended
//used:		archive-meetings.php, single-locations.php, single-meetings.php
function tsml_link($url, $string, $exclude='') {
	$appends = $_GET;
	if (array_key_exists($exclude, $appends)) unset($appends[$exclude]);
	if (!empty($appends)) {
		$url .= strstr($url, '?') ? '&' : '?';
		$url .= http_build_query($appends, '', '&amp;');
	}
	return '<a href="' . $url . '">' . $string . '</a>';
}

//function: set an option with the currently-used types
//used: 	tsml_import() and save.php
function tsml_update_types_in_use() {
	global $tsml_types_in_use, $wpdb;
	
	//shortcut to getting all meta values without getting all posts first
	$types = $wpdb->get_col('SELECT
			m.meta_value 
		FROM ' . $wpdb->postmeta . ' m
		JOIN ' . $wpdb->posts . ' p ON m.post_id = p.id
		WHERE p.post_type = "' . TSML_TYPE_MEETINGS . '" AND m.meta_key = "types" AND p.post_status = "publish"');
		
	//master array
	$all_types = array();
	
	//loop through results and append to master array
	foreach ($types as $type) {
		$type = unserialize($type);
		if (is_array($type)) $all_types = array_merge($all_types, $type);
	}
	
	//update global variable
	$tsml_types_in_use = array_unique($all_types);
	
	//set option value
	update_option('tsml_types_in_use', $tsml_types_in_use);
}

//admin screen update message
//used by tsml_import() and admin_types.php
function tsml_alert($message, $type='updated') {
	global $tsml_alerts;
	$tsml_alerts[] = compact('message', 'type');
	add_action('admin_notices', 'tsml_alert_messages');
}

//called by tsml_alert() above
//run through alert stack and output them all
function tsml_alert_messages() {
	global $tsml_alerts;
	foreach ($tsml_alerts as $alert) {
		extract($alert);
		echo '<div class="' . $type . '"><p>' . $message . '</p></div>';
	}
}

//run any outstanding database upgrades, called in init.php
//depends on constant set in 12-step-meeting-list.php
function tsml_upgrades() {
	global $wpdb;

	$tsml_version = get_option('tsml_version');

	if ($tsml_version == TSML_VERSION) return;
	
	//fix any lingering addresses that end in ", USA" (two letter country codes only)
	if (version_compare($tsml_version, '1.6.2', '<')) {
		$wpdb->get_results('UPDATE ' . $wpdb->postmeta . ' SET meta_value = LEFT(meta_value, LENGTH(meta_value) - 1) WHERE meta_key = "formatted_address" AND meta_value LIKE "%, USA"');
	}

	//populate new groups object with any locations that have contact information
	if (version_compare($tsml_version, '1.8.6', '<')) {

		//clear out old ones in case it crashed earlier
		if ($post_ids = implode(',', $wpdb->get_col('SELECT id FROM ' . $wpdb->posts . ' WHERE post_type IN ("' . TSML_TYPE_GROUPS . '")'))) {
			$wpdb->query('DELETE FROM ' . $wpdb->posts . ' WHERE id IN (' . $post_ids . ')');
			$wpdb->query('DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . $post_ids . ')');
		}
		
		//build array of locations with meetings
		$locations = $group_names = array();
		$meetings = tsml_get_meetings();
		foreach ($meetings as $meeting) {
			if (!array_key_exists($meeting['location_id'], $locations)) {
				$locations[$meeting['location_id']] = array(
					'name' => $meeting['location'],
					'meetings' => array(),
				);
				$group_names[] = $meeting['location'];
			}
			$locations[$meeting['location_id']]['meetings'][] = $meeting['id'];
		}
		
		$group_names = array_unique($group_names);
		
		foreach ($locations as $location_id => $location) {
			$location_custom = get_post_meta($location_id);
			if (empty($location_custom['contact_1_name'][0]) &&
				empty($location_custom['contact_1_email'][0]) &&
				empty($location_custom['contact_1_phone'][0]) &&
				empty($location_custom['contact_2_name'][0]) &&
				empty($location_custom['contact_2_email'][0]) &&
				empty($location_custom['contact_2_phone'][0]) &&
				empty($location_custom['contact_3_name'][0]) &&
				empty($location_custom['contact_3_email'][0]) &&
				empty($location_custom['contact_3_phone'][0])) continue;

			//handle duplicate location names, hopefully won't come up too much 
			$group_name = $location['name'];
			if (in_array($group_name, $group_names)) $group_name .= ' #' . $location_id;
			
			//create group
			$group_id = wp_insert_post(array(
			  	'post_type'		=> TSML_TYPE_GROUPS,
			  	'post_status'	=> 'publish',
				'post_title'	=> $group_name,
			));
						
			//set contacts for group
			for ($i = 0; $i <= GROUP_CONTACT_COUNT; $i++) {
				foreach (array('name', 'email', 'phone') as $type) {
					$fieldname = 'contact_' . $i . '_' . $type;
					if (!empty($location_custom[$fieldname][0])) {
						update_post_meta($group_id, $fieldname, $location_custom[$fieldname][0]);
					}
				}
			}
			
			foreach ($location['meetings'] as $meeting_id) {
				update_post_meta($meeting_id, 'group_id', $group_id);
			}

		}
	}
	
	//clear old location contact details
	if (version_compare($tsml_version, '1.9', '<')) {
		$wpdb->query('DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key IN (
			"contact_1_name", "contact_1_email", "contact_1_phone", 
			"contact_2_name", "contact_2_email", "contact_2_phone",
			"contact_3_name", "contact_3_email", "contact_3_phone"
		) AND post_id IN (
			SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type = "locations"
		)');
	}

	update_option('tsml_version', TSML_VERSION);
}

//function for shortcode
function tsml_location_count() {
	return number_format(count(tsml_get_all_locations()));
}
add_shortcode('tsml_location_count', 'tsml_location_count');

//function for shortcode
function tsml_meeting_count() {
	return number_format(count(tsml_get_all_meetings()));
}
add_shortcode('tsml_meeting_count', 'tsml_meeting_count');

//function for shortcode
function tsml_region_count() {
	return number_format(count(tsml_get_all_regions()));
}
add_shortcode('tsml_region_count', 'tsml_region_count');

//function for shortcode
function tsml_group_count() {
	return number_format(count(tsml_get_all_groups()));
}
add_shortcode('tsml_group_count', 'tsml_group_count');

//function for shortcode: get a table of the next $count meetings
function tsml_next_meetings($arguments) {
	$arguments = shortcode_atts(array(
		'count' => 5,
	), $arguments, 'tsml_next_meetings');
	$meetings = tsml_get_meetings();
	usort($meetings, 'tsml_next_meetings_sort');
	$meetings = array_slice($meetings, 0, $arguments['count']);
	$rows = '';
	foreach ($meetings as $meeting) {
		if (in_array('M', $meeting['types'])) {
			$meeting['name'] .= '<small>Men</small>';
		} elseif (in_array('W', $meeting['types'])) {
			$meeting['name'] .= '<small>Women</small>';
		}
		$rows .= '<tr>
			<td class="time">' . tsml_format_time($meeting['time']) . '</td>
			<td class="name"><a href="' . $meeting['url'] . '">' . $meeting['name'] . '</a></td>
			<td class="location">' . $meeting['location'] . '</td>
			<td class="region">' . ($meeting['sub_region'] ? $meeting['sub_region'] : $meeting['region']) . '</td>
		</tr>';
	}
	return '<table class="tsml_next_meetings table table-striped">
		<thead>
			<tr>
				<th class="time">Time</td>
				<th class="name">Meeting</td>
				<th class="location">Location</td>
				<th class="region">Region</td>
			</tr>
		</thead>
		<tbody>' . $rows . '</tbody>
	</table>';
}
add_shortcode('tsml_next_meetings', 'tsml_next_meetings');

function tsml_next_meetings_sort($a, $b) {
	$today = current_time('w');
	$time = current_time('H:i');

	//increment day to be 'next week' if earlier than now
	if ($a['day'] < $today || ($a['day'] == $today && $a['time'] < $time)) $a['day'] += 7;
	if ($b['day'] < $today || ($b['day'] == $today && $b['time'] < $time)) $b['day'] += 7;
	
	//return standard compare	
	return tsml_sort_meetings($a, $b);
}

//helper for debugging
function dd($array) {
	echo '<pre>';
	print_r($array);
	exit;	
}

//helper to debug out of memory errors
function tsml_report_memory() {
	$size = memory_get_peak_usage(true);
	$units = array('B', 'KB', 'MB', 'GB');
	die(round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . $units[$i]);
}
