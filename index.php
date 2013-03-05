<?php
/*
Plugin Name: wp-deploy-flow
Plugin URI: http://demental.info
Description: A command-line task to deploy
Version: 0.1
Author: Arnaud Sellenet
Author URI: http://demental.info
License: GPL2
*/
if (defined('WP_CLI') && WP_CLI) { 
  include './deploy.php';
}
