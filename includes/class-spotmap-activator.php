<?php

class Spotmap_Activator {
	public static function activate() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-spotmap-database.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-spotmap-admin.php';
		$db = new Spotmap_Database();
		$admin = new Spotmap_Admin();
		global $wpdb;
		$table_name = $wpdb->prefix."spotmap_points";
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table_name} (
		    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `type` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
            `time` int(11) unsigned NOT NULL,
            `latitude` float(11,7) NOT NULL,
            `longitude` float(11,7) NOT NULL,
            `altitude` int(11) DEFAULT NULL,
            `battery_status` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `custom_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `feed_name` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `feed_id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `model` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `device_name` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `local_timezone` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `temp` float(6,2) DEFAULT NULL,
            `pressure` int(5) DEFAULT NULL,
            `humidity` int(3) DEFAULT NULL,
            `clouds` int(3) DEFAULT NULL,
            `visibility` int(6) DEFAULT NULL,
            `wind_speed` float(5,2) DEFAULT NULL,
            `wind_deg` float(6,2) DEFAULT NULL,
            `wind_gust` int(3) DEFAULT NULL,
            `weather_description` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `weather_icon` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id_UNIQUE` (`id`)
            )$charset_collate";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql , true);

		//activate cron for every 2.5min to get latest data from feed
		if ( ! wp_next_scheduled( 'spotmap_api_crawler_hook' ) ) {
			wp_schedule_event( time(), 'twohalf_min', 'spotmap_api_crawler_hook' );
		}
		if ( ! wp_next_scheduled( 'spotmap_get_timezone_hook' ) ) {
			wp_schedule_single_event( time(),'spotmap_get_timezone_hook' );
		}
		if ( ! wp_next_scheduled( 'spotmap_get_weather_hook' ) ) {
			wp_schedule_single_event( time(),'spotmap_get_weather_hook' );
		}
		// activate for first time
		if(!get_option('spotmap_api_providers')){
			$data_r = ['findmespot' => "Spot Feed"];
			add_option('spotmap_api_providers', $data_r);
		}
		if(!get_option('spotmap_custom_messages')){
			add_option('spotmap_custom_messages', []);
		}

		$defaults =[
			"spotmap_marker" => [
				'HELP' => [
						'iconShape' => "marker",
						'icon' => "life-ring",
						'customMessage' => "",
				],
				'HELP-CANCEL' => [
						'iconShape' => "marker",
						'icon' => "check-double",
						'customMessage' => "",
				],
				'CUSTOM' => [
						'iconShape' => "marker",
						'icon' => "comment-dots",
						'customMessage' => "",
				],
				'OK' => [
						'iconShape' => "marker",
						'icon' => "thumbs-up",
						'customMessage' => "",
				],
				'STATUS' => [
						'iconShape' => "circle",
						'icon' => "check-circle",
						'customMessage' => "",
				],
				'UNLIMITED-TRACK' => [
						'iconShape' => "circle-dot",
						'icon' => "user",
						'customMessage' => "",
				],
				'NEWMOVEMENT' => [
						'iconShape' => "circle",
						'icon' => "play-circle",
						'customMessage' => "",
				],
				'STOP' => [
						'iconShape' => "circle",
						'icon' => "stop-circle",
						'customMessage' => "",
				],
				'MEDIA' => [
						'iconShape' => "marker",
						'icon' => "camera-retro",
						'customMessage' => "",
				],
			],
			"spotmap_default_values" => [
				'maps' => "openstreetmap,opentopomap",
				'height' => 500,
				'mapcenter' => 'all',
				'width' => 'normal',
				'color' => 'blue,red',
				'splitlines' => '12',
			]
		];
		// error_log(print_r(array_diff(get_option("spotmap_marker"),$defaults["spotmap_marker"]),true));
		foreach ($defaults as $option_name => $value) {
			if(!get_option($option_name)){
				add_option($option_name, $defaults[$option_name]);
			} else {
				foreach (get_option($option_name) as $index => &$value) {
					if(empty($value)){
						$value = $defaults[$option_name][$index];
					}
				}
			}
		}
		
		// $args = array(
		// 	'post_type' => 'attachment',
		// 	'post_mime_type' => 'image',
		// 	'posts_per_page' => -1
		// );
		
		// $attachments = get_posts($args);

		// if ($attachments) {
		// 	foreach ($attachments as $attachment) {
		// 		// Get attachment details
		// 		$attachment_id = $attachment->ID;
		// 		// error_log($attachment_id);
		// 		if ($db->does_media_exist($attachment_id)) {
		// 			continue;
		// 		}
		// 		$admin->add_images_to_map($attachment_id);
		// 	}
		// } 
	}
}
