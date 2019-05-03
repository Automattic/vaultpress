<?php

define( 'JETPACK__VERSION', '7.3' );
define( 'JETPACK_MASTER_USER', true );
define( 'JETPACK__API_VERSION', 1 );
defined( 'JETPACK__API_BASE' ) or define( 'JETPACK__API_BASE', 'https://jetpack.wordpress.com/jetpack.' );
define( 'JETPACK__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETPACK__PLUGIN_FILE', __FILE__ );
defined( 'JETPACK_CLIENT__AUTH_LOCATION' ) or define( 'JETPACK_CLIENT__AUTH_LOCATION', 'header' );

require 'class.jetpack-connect.php';
require 'class.jetpack-connect-data.php';
require 'class.jetpack-connect-options.php';
require 'class.jetpack-connect-client.php';
require 'class.jetpack-connect-xmlrpc-server.php';