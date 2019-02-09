<?php
/*
Plugin Name: ABG Members
Plugin URI: https://alabamabrewers.org
Description: Custom Plugin for dealing with Guild Membership synchronizing
Version: 1.0.0
Author: Dan Roberts
Author URI: https://alabamabrewers.org
*/
require_once('config.php');
require_once('person.class.php');
require_once('business.class.php');
require_once('functions.php');
require_once('mailchimp-api.php');

define('ABGP_VERSION',	'1.0.0');

/// For Testing
add_shortcode( 'abgpdebug', 'abgp_debug_func' );
function abgp_debug_func( ) {
	global $abgmp_mailchimp_list_id, $abgmp_mailchimp_api_key;
	abgp_daily_action();
}

register_activation_hook(__FILE__, 'abgp_activation');

function abgp_activation() {
    if (! wp_next_scheduled ( 'abgp_daily_job' )) {
        wp_schedule_event(time(), 'daily', 'abgp_daily_job');
    }
}
add_action('abgp_daily_job', 'abgp_daily_action');

// Daily Action
function abgp_daily_action() {
    global $abgmp_notification_email_to, $abgmp_mailchimp_api_key, $abgmp_mailchimp_list_id;
    $log_head = '<p>Plugin log for daily action run at ' . date('m/d/Y g:i A', time()) . '</p>';
    $log = '';
    
    try {
        $log .= Sync_Members_to_MailChimp();
    }
    catch(Exception $e) {
        $log .= "<p>Caught Exception: {$e->getMessage()}</p>";
    }

    if( strlen($log) == 0 ) {
        $log .= "<p>No activity. Nothing changed.</p>";
    }
    $body = $log_head . $log;
    $subject = "ABG Plugin Daily Log for " . date('m/d/Y', time());
    $email_from = "info@alabamabrewers.org";
	$headers = sprintf('From: %s <%s> \r\n', $email_from, $email_from);
    wp_mail($abgmp_notification_email_to, $subject, stripslashes($body), $headers );
}

register_deactivation_hook(__FILE__, 'abgp_deactivation');

function abgp_deactivation() {
    wp_clear_scheduled_hook('abgp_daily_job');
}
