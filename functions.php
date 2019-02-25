<?php

// Get a total list of people in Chamber Dashboard
function Get_ChamberDBPeople( ) {
    $people = array();
    $query = new WP_Query(array(
        'post_type' => 'person',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ));

    while( $query->have_posts() ) {
        $query->the_post();
        $post_id = get_the_id();
        array_push( $people, new Person( $post_id ));
    }
    wp_reset_query();
    return $people;
}

function Get_All_Tags_For_MailChimp() {
    // Get all membership levels, membership statuses,
    // business categories, private business categories,
    // people categories, and roles. Using name (not slug)
    $levels = array_column( get_terms([
                'taxonomy' => 'membership_level',
                'hide_empty' => false,
                ]), 'name');
    $statuses = array_column( get_terms([
                'taxonomy' => 'membership_status',
                'hide_empty' => false,
                ]), 'name');
    $biz_categories = array_column( get_terms([
                'taxonomy' => 'business_category',
                'hide_empty' => false,
                ]), 'name');
    $private_biz_categories = array_column( get_terms([
                'taxonomy' => 'private_category',
                'hide_empty' => false,
                ]), 'name');
    $people_categories = array_column( get_terms([
                'taxonomy' => 'people_category',
                'hide_empty' => false,
                ]), 'name');
    $roles = array_map('trim', explode(',', (get_option( 'cdcrm_options' )['person_business_roles'])));
    return array_merge( $levels, $statuses, $biz_categories, $private_biz_categories, $people_categories, $roles );
}

function Get_Tags_For_MailChimp( $person ) {
    $tags = array();
    $tags = $person->Get_Roles();
    foreach( $person->Get_People_Categories() as $category ) {
            array_push( $tags, $category );
    }
    foreach( $person->Get_Connected_Businesses() as $business) {
        foreach( $business->Get_Membership_Levels() as $level ) {
            array_push( $tags, $level );
        }
        foreach( $business->Get_Business_Categories() as $category ) {
            array_push( $tags, $category );
        }
        foreach( $business->Get_Private_Categories() as $category ) {
            array_push( $tags, $category );
        }
        $roles = $person->Get_Roles();
        array_push($tags, $business->membership_status);
    }
    return $tags;
}

function Sync_Members_to_MailChimp() {
    global $abgmp_mailchimp_list_id;
    global $abgmp_mailchimp_api_key;
    $log_message = '';
    $mailchimp_api = new MailChimpApiClient($abgmp_mailchimp_api_key);

    $offset=0;
    $count = 100;
    $mailchimp_members = array();

    do {
    	$result = $mailchimp_api->get("lists/{$abgmp_mailchimp_list_id}/members?offset={$offset}&count={$count}&fields=members,total_items");
    	$mailchimp_members = array_merge( $mailchimp_members, $result['members'] );
    	$offset = $offset+$count;
    	$total_count = $result['total_items'];
    }
    while( $offset < $total_count );
    
    $all_tags = Get_All_Tags_For_MailChimp();

    foreach( Get_ChamberDBPeople() as $chamber_person ) {
    	// For each person in Chamber
        if(strlen($chamber_person->email) == 0) {
            continue;
        }
        
        $in_mailchimp = in_array( 
            strtolower( $chamber_person->email ), 
            array_map( 'strtolower', array_column( $mailchimp_members, 'email_address' ) ) 
        );

        if( $in_mailchimp ) {
        	// They are already in MailChimp. Let's check their tags
	        $mailchimp_person_index = array_search( 
                strtolower( $chamber_person->email ), 
                array_map( 'strtolower', array_column( $mailchimp_members, 'email_address' ) ) 
            );

            $mailchimp_person = $mailchimp_members[$mailchimp_person_index];
            $chamber_tags = Get_Tags_For_MailChimp( $chamber_person );
            $mailchimp_tags = array_column( $mailchimp_person['tags'], 'name');

            $tags_to_edit = array();
            foreach( $all_tags as $tag ) {
                $is_in_chamber = in_array($tag, $chamber_tags);
                $is_in_mailchimp = in_array($tag, $mailchimp_tags);

                // if the tag is in chamber_tags but not in $mailchimp_tags, add it to mailchimp
                if( $is_in_chamber && !$is_in_mailchimp ) {
                    $log_message .= "{$chamber_person->name} given new tag: {$tag}<br />";
                    array_push($tags_to_edit, array(
                        'name' => $tag,
                        'status' => 'active'));
                }
                // if the tag is in mailchimp_tags but not in chamber_tags, remove it from mailchimp
                else if ( $is_in_mailchimp && !$is_in_chamber) {
                    $log_message .= "{$chamber_person->name} had tag removed: {$tag}<br />";
                    array_push($tags_to_edit, array(
                        'name' => $tag,
                        'status' => 'inactive'));
                }
            }
            if( count($tags_to_edit) > 0 ) {
                // Send edit request
                $hash = $mailchimp_api->subscriberHash($mailchimp_person['email_address']);
                $request_body = [
                    'tags' => $tags_to_edit
                ];
                $result = $mailchimp_api->post( "/lists/{$abgmp_mailchimp_list_id}/members/{$hash}/tags", $request_body, 60);
            }
        }
        else {
            // They need to be added to MalChimp
            $tags_to_add = Get_Tags_For_MailChimp( $chamber_person );
            $tags_to_add_s = implode(', ',$tags_to_add);
            $log_message .= "Adding {$chamber_person->name} to list with the following tags: {$tags_to_add_s} <br />";

            $request_body = [
	                'email_address' => $chamber_person->email,
	                'email_type' => 'html',
	                'status' => 'subscribed'
	            ];
            if(count($tags_to_add) > 0) {
	            $request_body['tags'] = $tags_to_add;
       		}
       		else {
       			
       		}
            $result = $mailchimp_api->post( "lists/{$abgmp_mailchimp_list_id}/members", $request_body, 60 );
        }
    }

    return $log_message;
}

function guildmp_mailchimp_sync() {

}

function Sync_All_Users_To_Roles_And_People() {
    $log_message = '';
    foreach(get_users() as $user) {
        Sync_User_To_Role( $user->user_login, $user->user_email );
        Connect_User_To_Person( $user->user_login, $user->user_email );
    }
    return $log_message;
}

function Sync_User_To_Role( $user_login, $user_email ) {
    $user = new WP_User( null, $user_login );
    $chamber_person = GetChamberDBPersonByEmail( $user_email );

    if( $chamber_person == null ) {
        return;
    }

    foreach( $chamber_person->Get_Connected_Businesses() as $business ) {
        $levels = $business->Get_Membership_Levels();
        $roles_to_remove = array();
        if( in_array( 'Regular Member', $levels ) && $business->membership_status == 'Current' ) {
            $user->add_role('brewing_member');
        }
        else if( in_array( 'Associate Member', $levels ) && $business->membership_status == 'Current' ) {
            $user->add_role('brewing_member');
        }
        else {
            array_push( $roles_to_remove, 'brewing_member');
        }
        if( in_array( 'Distillery Member', $levels ) && $business->membership_status == 'Current' ) {
            $user->add_role('distillery_member');
        }
        else {
            array_push( $roles_to_remove, 'distillery_member');
        }
        if( in_array( 'Allied Member', $levels ) && $business->membership_status == 'Current' ) {
            $user->add_role('allied_member');
        }
        else {
            array_push( $roles_to_remove, 'allied_member');
        }
        foreach($roles_to_remove as $role) {
            $user->remove_role($role);
        }
    }
}

function Connect_User_To_Person( $user_login, $user_email ) {
    $user = new WP_User( null, $user_login );
    $chamber_person = GetChamberDBPersonByEmail( $user_email );
    if( $chamber_person != null ) {
        p2p_type( 'people_to_user' )->connect(
            $user->ID,
            $chamber_person->ID,
            array( 'date' => current_time('mysql') ) 
        );
    }
}

function GetChamberDBPersonByEmail( $user_email ) {
    $people = Get_ChamberDBPeople();

    foreach( $people as $key => $val ) {
        if( trim(strtolower($val->email)) == trim(strtolower($user_email) )) {
            return $val;
        }
    }
    return null;
}

