<?php
/*
Plugin Name: Audio/Video Bonus Pack
Description: Experimental/Supplemental features not included in WordPress core.
Author: Scott Taylor
Author URI: http://profiles.wordpress.org/wonderboymusic/
Version: 0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class AudioVideoBonusPack {
	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance instanceof AudioVideoBonusPack ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	private function __construct() {
		// stuff goes here
	}
}
AudioVideoBonusPack::get_instance();