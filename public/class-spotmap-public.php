<?php
class Spotmap_Public{
	
	public $db;

	function __construct() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-spotmap-database.php';
		$this->db = new Spotmap_Database();
    }

	public function enqueue_styles() {
		wp_enqueue_style( 'leaflet', plugin_dir_url( __FILE__ ) . 'leaflet/leaflet.css');
		wp_enqueue_style( 'custom', plugin_dir_url( __FILE__ ) . 'css/custom.css');
        wp_enqueue_style( 'leaflet-fullscreen', plugin_dir_url( __FILE__ ) . 'leafletfullscreen/leaflet.fullscreen.css');
		wp_enqueue_style( 'leaflet-easybutton', plugin_dir_url( __FILE__ ) . 'leaflet-easy-button/easy-button.css');
		wp_enqueue_style( 'dashicon', '/wp-includes/css/dashicons.css');
    }

	public function enqueue_block_editor_assets(){
		$this->enqueue_scripts();
		$this->enqueue_styles();
		wp_enqueue_script(
			'spotmap-block',
			plugins_url('js/block.js', __FILE__),
			[
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
			]
		);
		
		wp_localize_script('spotmap-block', 'spotmapjsobj', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'maps' => $this->get_maps(),
			'url' =>  plugin_dir_url( __FILE__ ),
			'feeds' => $this->db->get_all_feednames(),
			'defaultValues' => get_option('spotmap_default_values'),
		]);

		register_block_type( 'spotmap/spotmap', array(
			'editor_script' => 'spotmap-block',
			'render_callback' => [$this, 'show_spotmap_block'],
		) );
	}

	public function enqueue_scripts(){
        wp_enqueue_script('spotmap-handler', plugins_url('js/maphandler.js', __FILE__), ['jquery','moment','lodash'], false, true);
		wp_localize_script('spotmap-handler', 'spotmapjsobj', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'maps' => $this->get_maps(),
			'url' =>  plugin_dir_url( __FILE__ ),
			'feeds' => $this->db->get_all_feednames(),
		]);
		wp_enqueue_script('leaflet',  plugins_url( 'leaflet/leaflet.js', __FILE__ ));
        wp_enqueue_script('leaflet-fullscreen',plugin_dir_url( __FILE__ ) . 'leafletfullscreen/leaflet.fullscreen.js');
        wp_enqueue_script('leaflet-gpx',plugin_dir_url( __FILE__ ) . 'leaflet-gpx/gpx.js');
        wp_enqueue_script('leaflet-easybutton',plugin_dir_url( __FILE__ ) . 'leaflet-easy-button/easy-button.js');
        wp_enqueue_script('leaflet-swisstopo',  'https://unpkg.com/leaflet-tilelayer-swiss@2.2.1/dist/Leaflet.TileLayer.Swiss.umd.js');

	}
// TODO: move to admin class
	public function get_maps(){
		$maps_file = plugin_dir_path( dirname( __FILE__ ) ) . 'config/maps.json';
		if(file_exists($maps_file)){
			$maps = json_decode(file_get_contents($maps_file),true);
			// error_log(print_r($maps,true));
			$api_tokens = get_option('spotmap_api_tokens');
			foreach ($maps as $name => &$data) {
				// error_log(print_r($data['options']['mapboxToken'],true));
				if(isset($data['options']['mapboxToken'])){
					if(!empty($api_tokens['mapbox'])){
						$data['options']['mapboxToken'] = $api_tokens['mapbox'];
						continue;
					}
					unset($maps[$name]);
				}
				else if(isset($data['options']['thunderforestToken'])){
					if(!empty($api_tokens['thunderforest'])){
						$data['options']['thunderforestToken'] = $api_tokens['thunderforest'];
						continue;
					}
					unset($maps[$name]);
				}
				else if(isset($data['options']['LINZToken'])){
					if(!empty($api_tokens['linz.govt.nz'])){
						$data['options']['LINZToken'] = $api_tokens['linz.govt.nz'];
						continue;
					}
					unset($maps[$name]);
				}
				else if(isset($data['options']['geoportailToken'])){
					if(!empty($api_tokens['geoservices.ign.fr'])){
						$data['options']['geoportailToken'] = $api_tokens['geoservices.ign.fr'];
						continue;
					}
					unset($maps[$name]);
				}
				else if(isset($data['options']['osdatahubToken'])){
					if(!empty($api_tokens['osdatahub.os.uk'])){
						$data['options']['osdatahubToken'] = $api_tokens['osdatahub.os.uk'];
						continue;
					}
					unset($maps[$name]);
				}
			}
			return ($maps);
		}
	}

	public function register_shortcodes(){
		add_shortcode('spotmap', [$this,'show_spotmap'] );
		add_shortcode('Spotmap', [$this,'show_spotmap'] );
		add_shortcode('spotmessages', [$this,'show_point_overview'] );
		add_shortcode('Spotmessages', [$this,'show_point_overview'] );
	}
	function show_point_overview($atts){
		// error_log("Shortcode init vals: ".wp_json_encode($atts));
		$a = array_merge(
			shortcode_atts([
				'count'=> 10,
				'types'=>'HELP,HELP-CANCEL,OK,CUSTOM',
				'feeds' => $this->db->get_all_feednames(),
				'group'=>'',
				'date-range-from' => '',
				'date' => '',
				'date-range-to' => '',
				'auto-reload' => FALSE,
				'filter-points' => !empty( get_option('spotmap_default_values')['filter-points'] ) ?get_option('spotmap_default_values')['filter-points'] : 5,
			], $atts),
			$atts);
		// get the keys that don't require a value
		if(array_key_exists('auto-reload',$atts)){
			$a['auto-reload']=TRUE;
		}
		// error_log("Shortcode after vals: ".wp_json_encode($a));
		foreach (['types','feeds'] as $value) {
			if(!empty($a[$value]) && !is_array($a[$value])){
				// error_log($a[$value]);
				$a[$value] = explode(',',$a[$value]);
			}
		}
		foreach ($a as $key => &$values) {
			if(is_array($values)){
				foreach($values as &$entry){
					$entry =_sanitize_text_fields($entry);
				}
			} else {
				$values = _sanitize_text_fields($values);
			}
		}
		
		$options = [
			'select' => "type,id,message,local_timezone,feed_name, time",
			'type'=>$a['types'],
			'filterPoints' => $a['filter-points'],
			'feeds' => $a['feeds'],
			'dateRange' => [
				'from' => $a['date-range-from'],
				'to' => $a['date-range-to']
			],
			'date' => $a['date'],
			'orderBy' => "time DESC",
			'limit' => $a['count'],
			'groupBy' => $a['group'],
			'autoReload' => $a['auto-reload'],
		];
		$table_id = "spotmap-table-".mt_rand();
		return '
	<table id='.$table_id.'></table>
	<script type=text/javascript>var spotmap; jQuery(function(){spotmap = new Spotmap('. wp_json_encode($options).');spotmap.initTable("'.$table_id.'")})</script>';

	}

	public function show_spotmap_block($options){
		$options_json = wp_json_encode($options);
		// error_log("BLOCK init vals: ". $options_json);
		return '<div id="'.$options['mapId'].'" class='. (!empty($a['align']) ? 'align'.$a['align'] : '' ). ' style="z-index: 0;"></div>
	<script type=text/javascript>var spotmap; jQuery(function(){spotmap = new Spotmap('.$options_json.');spotmap.initMap()})</script>';
	}
	public function show_spotmap($atts,$content = null){
		if(empty($atts)){
			$atts = [];
		}
		// error_log("Shortcode init vals: ".wp_json_encode($atts));
		// $atts['feeds'] = $atts['devices'];
		$a = array_merge(
			shortcode_atts( [
				'height' => !empty( get_option('spotmap_default_values')['height'] ) ?get_option('spotmap_default_values')['height'] : 500,
				'mapcenter' => !empty( get_option('spotmap_default_values')['mapcenter'] ) ?get_option('spotmap_default_values')['mapcenter'] : 'all',
				'feeds' => $this->db->get_all_feednames(),
				'width' => !empty(get_option('spotmap_default_values')['width']) ?get_option('spotmap_default_values')['width'] : 'normal',
				'colors' => !empty(get_option('spotmap_default_values')['color']) ?get_option('spotmap_default_values')['color'] : 'blue,red',
				'splitlines' => !empty(get_option('spotmap_default_values')['splitlines']) ?get_option('spotmap_default_values')['splitlines'] : '12',
				'tiny-types' => !empty(get_option('spotmap_default_values')['tiny-types']) ?get_option('spotmap_default_values')['tiny-types'] : NULL,
				'auto-reload' => FALSE,
				'last-point' => FALSE,
				'date-range-from' => NULL,
				'date' => NULL,
				'date-range-to' => NULL,
				'gpx-name' => [],
				'gpx-url' => [],
				'gpx-color' => ['blue', 'gold', 'red', 'green', 'orange', 'yellow', 'violet'],
				'maps' => !empty( get_option('spotmap_default_values')['maps'] ) ?get_option('spotmap_default_values')['maps'] : 'openstreetmap,opentopomap',
				'map-overlays' => !empty( get_option('spotmap_default_values')['map-overlays'] ) ?get_option('spotmap_default_values')['map-overlays'] : NULL,
				'filter-points' => !empty( get_option('spotmap_default_values')['filter-points'] ) ?get_option('spotmap_default_values')['filter-points'] : 5,
				'debug'=> FALSE,
			], $atts ),
			$atts);
		// get the keys that don't require a value 
		foreach (['auto-reload','debug','last-point',] as $value) {
			if(in_array($value,$atts)){
				if (array_key_exists($value,$atts) && !empty($atts[$value])){
					// if a real value was provided in the shortcode
					$a[$value] = $atts[$value];
				} else {
					$a[$value]=TRUE;
				}
			}
		}
		
		foreach (['feeds','splitlines','colors','gpx-name','gpx-url','gpx-color','maps','map-overlays','tiny-types'] as $value) {
			if(!empty($a[$value]) && !is_array($a[$value])){
				// error_log($a[$value]);
				$a[$value] = explode(',',$a[$value]);
				foreach ($a[$value] as $key => &$data) {
					if (empty($data)){
						unset($a[$value][$key]);
					}
				}
			}
		}
		// error_log(wp_json_encode($a));
		foreach ($atts as $key => &$values) {
			if(is_array($values)){
				foreach($values as &$entry){
					$entry =_sanitize_text_fields($entry);
				}
			} else {
				$values = _sanitize_text_fields($values);
			}
		}
	
		// valid inputs for feeds?
		$styles = [];
		if(!empty($a['feeds'])){
			$number_of_feeds = count($a['feeds']);
			$count_present_numbers = count($a['splitlines']);
			if($count_present_numbers < $number_of_feeds){
				$fillup_array = array_fill($count_present_numbers, $number_of_feeds - $count_present_numbers, $a['splitlines'][0]);
				$a['splitlines'] = array_merge($a['splitlines'],$fillup_array);

				// error_log(print_r($a['splitlines'],true));
			}
			if(count($a['colors']) < $number_of_feeds){
				$a['colors'] = array_fill(0,$number_of_feeds, $a['colors'][0]);
			}
			foreach ($a['feeds'] as $key => $value) {
				$styles[$value] = [
					'color'=>$a['colors'][$key],
					'splitLines' => $a['splitlines'][$key],
					'tinyTypes' => $a['tiny-types']
					];
			}
		}

		// valid inputs for gpx tracks?
		$gpx = [];
		if(!empty($a['gpx-url'])){
			$number_of_tracks = count($a['gpx-url']);
			$count_present_numbers = count($a['gpx-color']);
			if($count_present_numbers < $number_of_tracks){
				$fillup_array = [];
				for ($i = $count_present_numbers; $i < $number_of_tracks; $i++) { 
					$value = $a['gpx-color'][$i % $count_present_numbers];
					$a['gpx-color'] = array_merge($a['gpx-color'],[$value]);
				}
			}
			if(empty($a['gpx-name'])){
				$a['gpx-name'][0] = "GPX";
			}
			if(count($a['gpx-name']) < $number_of_tracks){
				$a['gpx-name'] = array_fill(0,$number_of_tracks, $a['gpx-name'][0]);
			}
			foreach ($a['gpx-url'] as $key => $url) {
				$name = $a['gpx-name'][$key];
				$gpx[] = [
					'title' => $name,
					'url' => $url,
					"color" => $a['gpx-color'][$key]
				];
			}
		}
		$map_id = "spotmap-container-".mt_rand();
		// generate the option object for init the map
		$options = wp_json_encode([
			'feeds' => $a['feeds'],
			'filterPoints' => $a['filter-points'],
			'styles' => $styles,
			'gpx' => $gpx,
			'date' => $a['date'],
			'dateRange' => [
				'from' => $a['date-range-from'],
				'to' => $a['date-range-to']
			],
			'mapcenter' => $a['mapcenter'],
			'maps' => $a['maps'],
			'mapOverlays' => $a['map-overlays'],
			'autoReload' => $a['auto-reload'],
			'lastPoint' => $a['last-point'],
			'debug' => $a['debug'],
			'mapId' => $map_id
		]);
		// error_log($options);
		
		$css ='height: '.$a['height'].'px;z-index: 0;';
		if($a['width'] == 'full'){
			$css .= "max-width: 100%;";
		}

		return '
	<div id="'.$map_id.'" style="'.$css.'"></div>
	<script type=text/javascript>var spotmap; jQuery(function(){spotmap = new Spotmap('.$options.');spotmap.initMap()})</script>';
	}


	public function get_positions(){
		// error_log(print_r($_POST,true));
		$points = $this->db->get_points($_POST);
		// error_log(print_r($points,true));
		if(empty($points)){
			$points = ['error'=> true,'title'=>'No points to show (yet)','message'=> ""];
		}
		wp_send_json($points);
	}

}
