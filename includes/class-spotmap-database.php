<?php

class Spotmap_Database {

	public function get_all_feednames(){
		global $wpdb;
		return $wpdb->get_col("SELECT DISTINCT feed_name FROM " . $wpdb->prefix . "spotmap_points");
	}
	public function get_all_types(){
		global $wpdb;
		return $wpdb->get_col("SELECT DISTINCT type FROM " . $wpdb->prefix . "spotmap_points");
	}
	public function get_last_point($feed_id = null){
		global $wpdb;
		$where = ' ';
		if(isset($feed)){
			$where .= "AND feed_id = '".$feed_id."' ";
		}
		return $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "spotmap_points WHERE 1 ".$where." ORDER BY id DESC LIMIT 1");
	}


	public function get_points($filter){
		// error_log(print_r($filter,true));

		$select = empty($filter['select']) ? "*": $filter['select'];
		$group_by = empty($filter['groupBy']) ? NULL: $filter['groupBy'];
		$order = empty($filter['orderBy']) ? NULL: "ORDER BY " . $filter['orderBy'];
		$limit = empty($filter['limit']) ? NULL: "LIMIT " . $filter['limit'];
		global $wpdb;
		$where = '';
		if(!empty($filter['feeds'])){
			$feeds_on_db = $this->get_all_feednames();
			foreach ($filter['feeds'] as $value) {
				if(!in_array($value,$feeds_on_db)){
					return ['error'=> true,'title'=>$value.' not found in DB','message'=> "Change the 'devices' attribute of your Shortcode"];
				}
			}
			$where .= "AND feed_name IN ('".implode("','", $filter['feeds']). "') ";
		}
		if(!empty($filter['type'])){ 
			$types_on_db = $this->get_all_types();
			$allowed_types = array_merge($types_on_db,['HELP-CANCEL','CANCEL','OK','CUSTOM','STATUS','STOP','NEWMOVEMENT','UNLIMITED-TRACK','TRACK','HELP']);
			foreach ($filter['type'] as $value) {
				if(!in_array($value,$allowed_types)){
					return ['error'=> true,'title'=>$value.' not found in DB','message'=> "Change the 'devices' attribute of your Shortcode"];
				}
			}
			$where .= "AND type IN ('".implode("','", $filter['type']). "') ";
		}

		// either have a day or a range
		$date;
		if(!empty($filter['date'])){
			$date = date_create($filter['date']);
			if($date != null){
				$date = date_format( $date,"Y-m-d" );
				$where .= "AND FROM_UNIXTIME(time) between '" . $date . " 00:00:00' and  '" . $date . " 23:59:59' ";
			}
		} else if(!empty($filter['date-range'])){
			if(!empty($filter['date-range']['to'])){
				
				$date = date_create($filter['date-range']['to']);
				if(substr($filter['date-range']['to'],0,5) == 'last-'){
					$rel_string = substr($filter['date-range']['to'],5);
					$rel_string = str_replace("-"," ",$rel_string);
					$date = date_create("@".strtotime('-'.$rel_string));
				}

				if($date != null){
					$where .= "AND FROM_UNIXTIME(time) <= '" . date_format( $date,"Y-m-d H:i:s" ) . "' ";
				}
			}
			if (!empty($filter['date-range']['from'])){
				$date = date_create($filter['date-range']['from']);
				if(substr($filter['date-range']['from'],0,5) == 'last-'){
					$rel_string = substr($filter['date-range']['from'],5);
					$rel_string = str_replace("-"," ",$rel_string);
					$date = date_create("@".strtotime('-'.$rel_string));
				}
				if($date != null){
					$where .= "AND FROM_UNIXTIME(time) >= '" . date_format( $date,"Y-m-d H:i:s" ) . "' ";
				}
			}
		}
		if(!empty($group_by)){
			$where.= " and id in (SELECT max(id) FROM " . $wpdb->prefix . "spotmap_points GROUP BY ".$group_by." )";
		}
		$query = "SELECT ".$select.", custom_message FROM " . $wpdb->prefix . "spotmap_points WHERE 1 ".$where." ".$order. " " .$limit;
		// error_log("Query: " .$query);
		$points = $wpdb->get_results($query);
		foreach ($points as $index => &$point){
			$point->unixtime = $point->time;
			// $point->date = date_i18n( get_option('date_format'), $date );
			$point->date = wp_date(get_option('date_format'),$point->unixtime);
			$point->time = wp_date(get_option('time_format'),$point->unixtime);
			if(!empty($point->local_timezone)){
				$timezone = new DateTimeZone($point->local_timezone);
				$point->localdate = wp_date(get_option('date_format'),$point->unixtime,$timezone);
				$point->localtime = wp_date(get_option('time_format'),$point->unixtime,$timezone);
			}

			// TODO: make complexity linear instead of quadratic
			$point->speed_instant = $this->calculate_instant_speed($points, $index); // speed between the last 2 points
			$point->speed_1hr = $this->calculate_speed($points, $index, 1 * 60 * 60); // 1 hour in seconds
			$point->speed_24hr = $this->calculate_speed($points, $index, 24 * 60 * 60); // 24 hours in seconds

			if(!empty($point->custom_message)){
				$point->message = $point->custom_message;
			}
			if(!empty(get_option('spotmap_custom_messages')[$point->type])){
				$point->message = get_option('spotmap_custom_messages')[$point->type];
			}
		}
		return $points;
	}

	// Function to calculate distance between two points using Haversine formula (spherical trigonometry)
	function calculate_distance($lat1, $lon1, $lat2, $lon2) {
		// Radius of the Earth in meters
		$earth_radius = 6371000;

		$d_lat = deg2rad($lat2 - $lat1);
		$d_lon = deg2rad($lon2 - $lon1);

		$a = sin($d_lat / 2) * sin($d_lat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lon / 2) * sin($d_lon / 2);
		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));

		return $earth_radius * $c;
	}

	// Function to calculate average speed over a specified time window
	function calculate_speed($points, $index, $time_window_seconds) {
		$current_point = $points[$index];
		$start_time = $current_point->unixtime - $time_window_seconds;
		$distance_sum = 0;
		$time_diff_sum = 0;

		for ($i = $index - 1; $i > 0; $i--) {
			$previous_point = $points[$i];
			$previous_time = $previous_point->unixtime;

			$distance = $this->calculate_distance($previous_point->latitude, $previous_point->longitude, $current_point->latitude, $current_point->longitude);
			$time_diff = $current_point->unixtime - $previous_time;
			if ($time_diff <= 0) return -1;	//should never happen

			$distance_sum += $distance;
			$time_diff_sum += $time_diff;

			if ($previous_time < $start_time) break;
		}

		return ($time_diff_sum <= 0) ? 0 : $distance_sum / $time_diff_sum;
	}

	// Function to calculate instant speed
	function calculate_instant_speed($points, $index = 0) {
		if ($index == 0) return 0;

		$latest_point = $points[$index];
		$second_latest_point = $points[$index - 1];

		$time_diff = $latest_point->unixtime - $second_latest_point->unixtime;
		$dist = $this->calculate_distance($latest_point->latitude, $latest_point->longitude, $second_latest_point->latitude, $second_latest_point->longitude);

		return ($time_diff > 0) ? $dist / $time_diff : 0;
	}

	public function insert_point($point,$multiple = false){
		// error_log(print_r($point,true));
		if($point['unixTime'] == 1){
			return 0;
		}
		$last_point = $this->get_last_point($point['feedId']);
		
		if($point['latitude'] > 90 || $point['latitude']< -90){
			$point['latitude'] = $last_point->latitude;
		}
		if ($point['longitude'] > 180 || $point['longitude']< -180){
			$point['longitude'] = $last_point->longitude;
		}
		$data = [
			'feed_name' => $point['feedName'],
			'type' => $point['messageType'],
			'time' => $point['unixTime'],
			'latitude' => $point['latitude'],
			'longitude' => $point['longitude'],
			'model' => $point['modelId'],
			'device_name' => $point['messengerName'],
			'message' => !empty($point['messageContent']) ? $point['messageContent'] : NULL,
			'custom_message' => !empty( get_option('spotmap_custom_messages')[$point['messageType']] ) ? get_option('spotmap_custom_messages')[$point['messageType']] : NULL,
			'feed_id' => $point['feedId']
		];
		if (array_key_exists('id', $point)){
			$data['id']= $point['id'];
		}
		if (array_key_exists('battery_status', $point)){
			$data['battery_status']= $point['batteryState'];
		}
		if (array_key_exists('altitude', $point)){
			$data['altitude']= $point['altitude'];
		}
		if (array_key_exists('local_timezone', $point)){
			$data['local_timezone']= $point['local_timezone'];
		}
		global $wpdb;
		$result = $wpdb->insert($wpdb->prefix."spotmap_points",	$data);
		
		// schedule event to calc local timezone 
		wp_schedule_single_event( time(), 'spotmap_get_timezone_hook' );
		return $result;
	}
	/**
	 * This function checks if a point is preseent in the db
     * @param $id int The id of the point to check
	 *
	 * @return bool true if point with same id is in db else false
	 */
	function does_point_exist($id){
		global $wpdb;
		$result = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}spotmap_points WHERE id = {$id}");
		return $result ? true : false;
	}
	
	function does_media_exist($attachment_id){
		global $wpdb;
		$result = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}spotmap_points WHERE model = {$attachment_id}");
		return $result ? true : false;
	}
	function delete_media_point($attachment_id){
		global $wpdb;
		$result = $wpdb->delete($wpdb->prefix . 'spotmap_points', array('model' => $attachment_id));

		return $result ? true : false;
	}
	function rename_feed_name ($old_name,$new_name){
		global $wpdb;
		// error_log('reanem feed');
			$wpdb->query( $wpdb->prepare( "
			UPDATE `{$wpdb->prefix}spotmap_points`
			SET `feed_name` = %s
			WHERE feed_name = %s",
			[$new_name,$old_name]
		) );
		// error_log(print_r($wpdb->queries,true));

	}

}
