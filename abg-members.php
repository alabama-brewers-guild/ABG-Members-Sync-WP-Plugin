<?php
/*
Plugin Name: ABG Members
Plugin URI: https://alabamabrewers.org
Description: Custom Plugin for dealing with Guild Membership synchronizing
Version: 1.0.0
Author: Dan Roberts
Author URI: https://alabamabrewers.org
*/

include('config.php');
include('person.class.php');
include('business.class.php');
include('functions.php');
include('mailchimp-api.php');

define('GUILDMP_VERSION',	'1.0.0');

/// For Testing
add_shortcode( 'guildmpdebug', 'guldmp_debug_func' );
function guldmp_debug_func( ) {
    Sync_Members_to_MailChimp();
}

register_activation_hook(__FILE__, 'guildmp_activation');

function guildmp_activation() {
    if (! wp_next_scheduled ( 'guildmp_daily_job' )) {
        wp_schedule_event(time(), 'daily', 'guildmp_daily_job');
    }
}
add_action('guildmp_daily_job', 'guildmp_mailchimp_sync');


register_deactivation_hook(__FILE__, 'guildmp_deactivation');

function guildmp_deactivation() {
    wp_clear_scheduled_hook('guildmp_daily_job');
}
