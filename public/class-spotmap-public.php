<?php
class Spotmap_Public{
	
	public $db;

	function __construct() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-spotmap-database.php';
		$this->db = new Spotmap_Database();
    }

	public function enqueue_styles() {
		wp_enqueue_style( 'leafletcss', plugin_dir_url( __FILE__ ) . 'leaflet/leaflet.css');
		wp_enqueue_style( 'custom', plugin_dir_url( __FILE__ ) . 'css/custom.css');
        wp_enqueue_style( 'leafletfullscreencss', plugin_dir_url( __FILE__ ) . 'leafletfullscreen/leaflet.fullscreen.css');
    }

	public function enqueue_block_editor_assets(){
		$this->enqueue_scripts();
		$this->enqueue_styles();
		wp_enqueue_script(
			'spotmap-block',
			plugins_url('js/block.js', __FILE__),
			['wp-blocks', 'wp-element']
		);
	}

	public function enqueue_scripts(){
        wp_enqueue_script('leaflet',  plugins_url( 'leaflet/leaflet.js', __FILE__ ));
        wp_enqueue_script('leaflet-fullscreen',plugin_dir_url( __FILE__ ) . 'leafletfullscreen/leaflet.fullscreen.js');
        wp_enqueue_script('leaflet-gpx',plugin_dir_url( __FILE__ ) . 'leaflet-gpx/gpx.js');
        wp_enqueue_script('spotmap-handler', plugins_url('js/maphandler.js', __FILE__), array('jquery'), false, true);
		
		wp_localize_script('spotmap-handler', 'spotmapjsobj', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'maps' => $this->get_maps(),
			'url' =>  plugin_dir_url( __FILE__ )

		));
	}

	public function get_maps(){
		$maps_file = plugin_dir_path( dirname( __FILE__ ) ) . 'config/maps.json';
		if(file_exists($maps_file)){
			$maps = json_decode(file_get_contents($maps_file),true);
			$token = get_option('spotmap_mapbox_token');
			foreach ($maps as $name => &$data) {
				// error_log(print_r($data,true));
				if(!empty($token)){
					$data['options']['mapboxToken'] = $token;
				} else if(isset($data['options']['mapboxToken'])){
					unset($maps[$name]);
				}
			}
			return $maps;
		}
	}

	public function register_shortcodes(){
		add_shortcode('spotmap', [$this,'show_spotmap'] );
		add_shortcode('Spotmap', [$this,'show_spotmap'] );
		add_shortcode('spotmessages', [$this,'show_point_overview'] );
		add_shortcode('Spotmessages', [$this,'show_point_overview'] );
	}
	function show_point_overview(){
		$points = $this->db->get_points(['type'=>['HELP','OK','HELP-CANCEL']],"type AS Type, FROM_UNIXTIME(time) as Time, custom_message AS Message","time DESC LIMIT 10");
		// start table
		$html = '<table>';
		// header row
		$html .= '<tr>';
		foreach($points[0] as $key=>$value){
				$html .= '<th>' . htmlspecialchars($key) . '</th>';
			}
		$html .= '</tr>';
	
		// data rows
		foreach( $points as $key=>$value){
			$html .= '<tr>';
			foreach($value as $key2=>$value2){
				$html .= '<td>' . htmlspecialchars($value2) . '</td>';
			}
			$html .= '</tr>';
		}
	
		// finish table and return it
	
		$html .= '</table>';
		return $html;
	}
	function show_spotmap($atts,$content){
		// error_log("Shortcode init vals: ".$atts);
		$a = shortcode_atts( [
			'height' => '500',
			'mapcenter' => 'all',
			'devices' => $this->db->get_all_feednames(),
			'width' => 'normal',
			'colors' => ['blue', 'gold', 'red', 'green', 'orange', 'yellow', 'violet'],
			'splitlines' => '[12,12,12,12]',
			'date-range-from' => '',
			'date' => '',
			'date-range-to' => '',
			'gpx-name' => [],
			'gpx-url' => [],
			'gpx-color' => ['blue', 'gold', 'red', 'green', 'orange', 'yellow', 'violet'],
			'maps' => ['OpenStreetMap', 'OpenTopoMap']
		], $atts );
		error_log(wp_json_encode($a));

		foreach (['devices','splitlines','colors','gpx-name','gpx-url','gpx-color','maps'] as $value) {
			if(!empty($a[$value]) && !is_array($a[$value])){
				// error_log($a[$value]);
				$a[$value] = explode(',',$a[$value]);
			}
		}
		foreach ($atts as $key => &$values) {
			if(is_array($values)){
				foreach($values as &$entry){
					$entry =_sanitize_text_fields($entry);
				}
			} else {
				$values = _sanitize_text_fields($values);
			}
		}

		// TODO test what happens if array lengths are different
	
		$styles = [];
		if(!empty($a['devices'])){
			foreach ($a['devices'] as $key => $value) {
				$styles[$value] = [
					'color'=>$a['colors'][$key],
					'splitLines' => $a['splitlines'][$key]
					];
			}
		}
		$gpx = [];
		if(!empty($a['gpx-url'])){
			$number_of_tracks = count($a['gpx-url']);
			if(count($a['gpx-color']) < $number_of_tracks){
				$count_present_numbers = count($a['gpx-color']);
				$fillup_array = array_fill($count_present_numbers, $number_of_tracks - $count_present_numbers, $a['gpx-color'][0]);
				$a['gpx-color'] = array_merge($a['gpx-color'],$fillup_array);

				error_log(print_r($a['gpx-color'],true));
			}
			if(count($a['gpx-name']) < $number_of_tracks){
				$a['gpx-name'] = array_fill(0,$number_of_tracks, $a['gpx-name'][0]);
			}
			foreach ($a['gpx-url'] as $key => $url) {
				$name = $a['gpx-name'][$key];
				$gpx[] = [
					'name' => $name,
					'url' => $url,
					"color" => $a['gpx-color'][$key]
				];
			}
		}
		// generate the option object for init the map
		$options = wp_json_encode([
			'feeds' => $a['devices'],
			'styles' => $styles,
			'gpx' => $gpx,
			'date' => $a['date'],
			'dateRange' => [
				'from' => $a['date-range-from'],
				'to' => $a['date-range-to']
			],
			'mapcenter' => $a['mapcenter'],
			'maps' => $a['maps']
		]);
		// error_log($options);
		
		$css ='height: '.$a['height'].'px;z-index: 0;';
		if($a['width'] == 'full'){
			$css .= "max-width: 100%;";
		}

		return '<div id="spotmap-container" style="'.$css.'"></div><script type=text/javascript>jQuery( document ).ready(function() {var spotmap = initMap('.$options.');});</script>';
	}


	public function the_action_function(){
		// error_log(print_r($_POST,true));
		$points = $this->db->get_points($_POST);
		if(isset($points['error'])){
			wp_send_json($points);
		}
		
		foreach ($points as &$point){
			$point->unixtime = $point->time;
			$point->date = date_i18n( get_option('date_format'), $point->time );
			$point->time = date_i18n( get_option('time_format'), $point->time );
		}
		wp_send_json($points);
	}

}
