<?php

/**
 * @package           sync
 *
 * @wordpress-plugin
 * Plugin Name:       Sync Facebook Page Events to Events Manager
 * Version:           1.0.0
 * Description:       Takes the events of a Facebook page and puts it into the Wordpress Events Manager plugin.
 * License:           MIT
 * Author:            konkrad
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'PLUGIN_NAME_VERSION', '1.0.0' );

function eg_settings_api_init() {
	add_settings_section(
	   'eg_setting_section',
	   'Settings for FB Event Sync',
	   'eg_setting_section_callback_function',
	   'general'
   );
	add_settings_field(
	   'sync_setting_fb_api_id',
	   'Facebook API Key',
	   'sync_setting_fb_api_id_callback_function',
	   'general',
	   'eg_setting_section'
   );

   add_settings_field(
		'sync_setting_fb_page_id',
		'Facebook Page ID',
		'sync_setting_fb_page_id_callback_function',
		'general',
		'eg_setting_section'
	);

	register_setting( 'general', 'sync_setting_fb_api_id' );
	register_setting( 'general', 'sync_setting_fb_page_id' );
}

function eg_setting_section_callback_function() {
	echo '<p>Set your facebook page and API Key to sync your FB Events with the Events Manger for wordpress</p>';
}

function sync_setting_fb_api_id_callback_function() {
	$option = get_option( 'sync_setting_fb_api_id' );

	echo "<input id='sync_setting_fb_api_id' name='sync_setting_fb_api_id'  type='text' value='{$option}' />";
}

function sync_setting_fb_page_id_callback_function() {
	$option = get_option( 'sync_setting_fb_page_id' );

	echo "<input id='sync_setting_fb_page_id' name='sync_setting_fb_page_id'  type='text' value='{$option}' />";
}

function activate() {

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name = $wpdb->prefix . 'em_fb_mapping';

	$sql = "CREATE TABLE $table_name (
        cat VARCHAR(1) NOT NULL ,
        fb VARCHAR(210) NOT NULL ,
        ref bigint(20) unsigned,
		UNIQUE KEY id (cat, fb)
	) $charset_collate;";

	$wpdb->query($sql);

	//schedule task
	if ( ! wp_next_scheduled( 'getfbevents_hook' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'getfbevents_hook' );
	}
}

function deactivate() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'em_fb_mapping';

	$sql = "DROP TABLE IF EXISTS $table_name;";
	$wpdb->query($sql);

	delete_option( 'sync_setting_fb_api_id' );
	delete_option( 'sync_setting_fb_page_id' );

	//stop task
	$timestamp = wp_next_scheduled( 'getfbevents_hook' );
	wp_unschedule_event( $timestamp, 'getfbevents_hook' );
}

function getfbevents() {
    require_once __DIR__ .'/vendor/autoload.php';

	$api = get_option( 'sync_setting_fb_api_id' );
	$page = get_option( 'sync_setting_fb_page_id' );

	if($api == false || $page == false) {
		return;
	}

    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', 'https://graph.facebook.com/v2.11/'.$page.'/events?since=now', [
        'query' => ['access_token' => $api]
    ]);
    $text = json_decode($response->getBody());    

    foreach($text->data as $entry) {
        insert_event($entry);
    }

}

function insert_event($entry) {
    if(empty($entry)) {
        return;
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'em_fb_mapping';
    
    $fb_l_id = $entry->place->id;
    $fb_e_id = $entry->id;
    
    # Checks if event already exists
    $sql_e = "SELECT ref from ".$table_name." WHERE cat='E' AND fb=".$fb_e_id;
    $ref_e = $wpdb->get_var($sql_e);

    # if it is not a new event quit
    if(!is_null($ref_e)) {
        return;
    }

    $ref_l = null;
    # looks up event location
    if(!empty($fb_l_id)) {
        $sql_l = "SELECT ref from ".$table_name." WHERE cat='L' AND fb=".$fb_l_id;
        $ref_l = $wpdb->get_var($sql_l);
    }

    # if new location create a new one
    if(is_null($ref_l) && !empty($fb_l_id)) {
        $location = new EM_Location();
        $location->location_name = $entry->place->name;
        $location->location_postcode = $entry->place->location->zip;
        $location->location_latitude = $entry->place->location->latitude;
        $location->location_longitude = $entry->place->location->longitude;
        if($entry->place->location->country == "Netherlands") {
            $location->location_country = "NL";
        }
        $location->location_town = $entry->place->location->city;
        $location->location_address = $entry->place->location->street;
        $location->save();
        $ref_l = $location->location_id;
        $wpdb->insert($table_name, [
            'cat' => 'L',
            'fb' => $fb_l_id,
            'ref' => $ref_l
        ]);
    }

    $event = new EM_Event();
    $event->event_start_time = substr($entry->start_time,11, 8);
    $event->event_start_date = substr($entry->start_time,0, 10);
    $event->event_end_time = substr($entry->end_time,11, 8);
    $event->event_end_date = substr($entry->end_time,0, 10);
    $event->event_name = $entry->name;
    $event->post_content = $entry->description;
    $event->event_rsvp = 0;
    $event->location_id = $ref_l;
    $status = $event->save();
    $ref_e = $event->event_id;
    $wpdb->insert($table_name, [
        'cat' => 'E',
        'fb' => $fb_e_id,
        'ref' => $ref_e
    ]);
}

add_action( 'admin_init', 'eg_settings_api_init' );
add_action( 'getfbevents_hook', 'getfbevents' );

register_activation_hook( __FILE__, 'activate' );
register_deactivation_hook( __FILE__, 'deactivate' );