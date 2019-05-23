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
    $chamber_people = Get_ChamberDBPeople();

    do {
    	$result = $mailchimp_api->get("lists/{$abgmp_mailchimp_list_id}/members?offset={$offset}&count={$count}&fields=members,total_items");
    	$mailchimp_members = array_merge( $mailchimp_members, $result['members'] );
    	$offset = $offset+$count;
    	$total_count = $result['total_items'];
    }
    while( $offset < $total_count );
    
    $all_tags = Get_All_Tags_For_MailChimp();

    // Add new member and update tags for everyone in database
    foreach( $chamber_people as $chamber_person ) {
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

    // Now let's remove tags for people who are not in our database
    foreach($mailchimp_members as $mc_member) {
        
        $in_chamber = in_array( 
            strtolower( $mc_member['email_address'] ), 
            array_map( 'strtolower', array_map( function($e) {
                return $e->email;
            }, $chamber_people)  
        ));
        if(!$in_chamber) {
            $tags_to_remove = array();
            foreach( $mc_member['tags'] as $tag ) {
                if( in_array( $tag['name'], $all_tags ) ) {
                    $log_message .= "{$mc_member['email_address']} is not in Chamber Database and had tag removed: {$tag['name']}<br />";
                    array_push($tags_to_remove, array(
                        'name' => $tag['name'],
                        'status' => 'inactive'));
                }
            }
            if( count($tags_to_remove) > 0 ) {
                // Send edit request
                $hash = $mailchimp_api->subscriberHash($mc_member['email_address']);
                $request_body = [
                    'tags' => $tags_to_remove
                ];
                $result = $mailchimp_api->post( "/lists/{$abgmp_mailchimp_list_id}/members/{$hash}/tags", $request_body, 60);
            }

        }
    }

    return $log_message;
}

function Sync_All_Users_To_Roles_And_People() {
    $log_message = '';
    foreach(get_users() as $user) {
        $log_message .= Sync_User_To_Role( $user->user_login, $user->user_email );
        $log_message .= Connect_User_To_Person( $user->user_login, $user->user_email );
    }
    return $log_message;
}

function Sync_User_To_Role( $user_login, $user_email ) {
    $log_message = '';
    $user = new WP_User( null, $user_login );
    $chamber_person = GetChamberDBPersonByEmail( $user_email );

    if( $chamber_person == null ) {
        return;
    }

    $roles_needed = array();
    $roles_to_add = array();
    $roles_to_remove = array();

    // Get the roles needed for this $user
    foreach( $chamber_person->Get_Connected_Businesses() as $business ) {
        $levels = $business->Get_Membership_Levels();

        // Figure out what roles the user should have
        if( ( in_array('Associate Member', $levels) || in_array('Regular Member', $levels) ) && $business->membership_status == 'Current' ) {
            // If Current Regular or Associate Member,  pereson should have brewing_member role
            array_push($roles_needed, 'brewing_member');
        }
        if( in_array( 'Distillery Member', $levels ) && $business->membership_status == 'Current' ) {
            // If Current Distillery Member,  pereson should have brewing_member role
            array_push($roles_needed, 'distillery_member');
        }
        if( in_array( 'Allied Member', $levels ) && $business->membership_status == 'Current' ) {
            // If Current Allied Member,  pereson should have brewing_member role
            array_push($roles_needed, 'allied_member');
        }
    }

    // Remove duplicates
    array_unique($roles_needed);

    // Figure out which roles to add/remove
    foreach( array('brewing_member', 'distillery_member', 'allied_member') as $role ) {
        // Decide whether to add, remove, or do nothing with the role
        if( $user->has_cap($role) && !in_array($role, $roles_needed) ) {
            // Non-Member has role.  We need to remove the role
            array_push($roles_to_remove, $role);
        }
        else if( !$user->has_cap($role) && in_array($role, $roles_needed) ) {
            // Member does not have role. We need to add the role
            array_push($roles_to_add, $role);
        }
        // Else do nothing.
    }

    // Remove duplicates
    array_unique($roles_to_add);
    array_unique($roles_to_remove);

    // Add roles
    if( !empty($roles_to_add) ) {
        $log_message .= "{$user_login} had the following roles added: " . implode(',', $roles_to_add) . "<br />";
        // Add roles
        foreach( $roles_to_add as $role ) {
            $user->add_role($role);
        }
    }
    if( !empty($roles_to_remove) ) {
        $log_message .= "{$user_login} had the following roles removed: " . implode(',', $roles_to_remove) . "<br />";
        // Remove roles
        foreach( $roles_to_remove as $role ) {
            $user->remove_role($role);
        }
    }

    return $log_message;
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

function Untag_Non_Members_From_MailChimp() {

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

function cmp_directory($a, $b) {
    $last_word_a = array_pop( explode(' ', $a->name) );
    $last_word_b = array_pop( explode(' ', $b->name) );
    return strcasecmp($last_word_a, $last_word_b);
}

function BuildMembershipDirectory() {
    global $wpdb;
    global $directory_tablepress_table_id;
    $people = Get_ChamberDBPeople();
    usort($people, "cmp_directory");

    
    $postContent = Array();
    $postContent[0] = Array("Name","Organization","Type","Email","Title");

    $i = 0;
    foreach($people as $person) {
        $bizs = $person->Get_Connected_Businesses();
        if( !empty($bizs) ) {
            $biz = $person->Get_Connected_Businesses()[0];
            if($biz->membership_status == "Current") {
                $type_full = $biz->Get_Membership_Levels()[0];
                $type = '';
                if($biz->name == "Alabama Brewers Guild") {
                    $type = "Guild Staff";
                }
                else if($type_full == "Allied Member") {
                    $type = "Allied";
                }
                else if($type_full == "Associate Member" || $type_full == "Regular Member") {
                    $type = "Brewing";
                }
                else if($type_full == "Distillery Member") {
                    $type = "Distillery";
                }



                $i = $i+1;
                $name = $person->name;
                $organization = $biz->name;
                $type_full = $biz->Get_Membership_Levels()[0];
                $email = "<a href=\"mailto:{$person->email}\">$person->email</a>";
                $title = $person->title;

                $postContent[$i] = Array($name, $organization, $type, $email, $title);
            }
        }
    }

    $post_content_encoded = json_encode($postContent);
    $affected = $wpdb->update(
        $wpdb->posts,
        array( 'post_content' => $post_content_encoded ),
        array( 'ID' => $directory_tablepress_table_id ),
        array( '%s' ),
        array( '%d' ));
    echo $affected;
    if($affected == 0) {
        return "WARNING: Directory table not updated.";
    }
    else {
        return '';
    }
}

