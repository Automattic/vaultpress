<?php

class Jetpack_Connect {

	public $xmlrpc_server = null;

	private $xmlrpc_verification = null;
	private $rest_authentication_status = null;

	public $HTTP_RAW_POST_DATA = null; // copy of $GLOBALS['HTTP_RAW_POST_DATA']

	/**
	 * Holds the singleton instance of this class
	 * @since 2.3.3
	 * @var Jetpack_Connect
	 */
	static $instance = false;

	static $capability_translations = array(
		'administrator' => 'manage_options',
		'editor'        => 'edit_others_posts',
		'author'        => 'publish_posts',
		'contributor'   => 'edit_posts',
		'subscriber'    => 'read',
	);

	/**
	 * Contains all assets that have had their URL rewritten to minified versions.
	 *
	 * @var array
	 */
	static $min_assets = array();

	/**
	 * Singleton
	 * @static
	 */
	public static function init() {
		if ( ! self::$instance ) {
			self::$instance = new Jetpack_Connect;

			self::$instance->plugin_upgrade();
		}

		return self::$instance;
	}

	/**
	 * Constructor.  Initializes WordPress hooks
	 */
	function __construct() {
		/*
		 * Check for and alert any deprecated hooks
		 */
//        add_action( 'init', array( $this, 'deprecated_hooks' ) );

		/*
		 * Enable enhanced handling of previewing sites in Calypso
		 */
//        if ( Jetpack::is_active() ) {
//            require_once JETPACK__PLUGIN_DIR . '_inc/lib/class.jetpack-iframe-embed.php';
//            add_action( 'init', array( 'Jetpack_Iframe_Embed', 'init' ), 9, 0 );
//        }

		/*
		 * Load things that should only be in Network Admin.
		 *
		 * For now blow away everything else until a more full
		 * understanding of what is needed at the network level is
		 * available
		 */
//        if( is_multisite() ) {
//            Jetpack_Network::init();
//        }

		add_action( 'set_user_role', array( $this, 'maybe_clear_other_linked_admins_transient' ), 10, 3 );

		// Unlink user before deleting the user from .com
		add_action( 'deleted_user', array( $this, 'unlink_user' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( $this, 'unlink_user' ), 10, 1 );

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && isset( $_GET['for'] ) && 'jetpack' == $_GET['for'] ) {
			@ini_set( 'display_errors', false ); // Display errors can cause the XML to be not well formed.

			require_once JETPACK__PLUGIN_DIR . 'class.jetpack-connect-xmlrpc-server.php';
			$this->xmlrpc_server = new Jetpack_Connect_XMLRPC_Server();

			$this->require_jetpack_authentication();

			if ( Jetpack_Connect::is_active() ) {
				// Hack to preserve $HTTP_RAW_POST_DATA
				add_filter( 'xmlrpc_methods', array( $this, 'xmlrpc_methods' ) );

				$signed = $this->verify_xml_rpc_signature();
				if ( $signed && ! is_wp_error( $signed ) ) {
					// The actual API methods.
					add_filter( 'xmlrpc_methods', array( $this->xmlrpc_server, 'xmlrpc_methods' ) );
				} else {
					// The jetpack.authorize method should be available for unauthenticated users on a site with an
					// active Jetpack connection, so that additional users can link their account.
					add_filter( 'xmlrpc_methods', array( $this->xmlrpc_server, 'authorize_xmlrpc_methods' ) );
				}
			} else {
				// The bootstrap API methods.
				add_filter( 'xmlrpc_methods', array( $this->xmlrpc_server, 'bootstrap_xmlrpc_methods' ) );
				$signed = $this->verify_xml_rpc_signature();
				if ( $signed && ! is_wp_error( $signed ) ) {
					// the jetpack Provision method is available for blog-token-signed requests
					add_filter( 'xmlrpc_methods', array( $this->xmlrpc_server, 'provision_xmlrpc_methods' ) );
				}
			}

			// Now that no one can authenticate, and we're whitelisting all XML-RPC methods, force enable_xmlrpc on.
			add_filter( 'pre_option_enable_xmlrpc', '__return_true' );
		} elseif (
			is_admin() &&
			isset( $_POST['action'] ) && (
				'jetpack_upload_file' == $_POST['action'] ||
				'jetpack_update_file' == $_POST['action']
			)
		) {
			$this->require_jetpack_authentication();
			$this->add_remote_request_handlers();
		} else {
			if ( self::is_active() ) {
				add_action( 'login_form_jetpack_json_api_authorization', array( &$this, 'login_form_json_api_authorization' ) );
				add_filter( 'xmlrpc_methods', array( $this, 'public_xmlrpc_methods' ) );
			}
		}

		if ( Jetpack_Connect::is_active() ) {
			Jetpack_Connect_Heartbeat::init();
//            if ( Jetpack::is_module_active( 'stats' ) && Jetpack::is_module_active( 'search' ) ) {
//                require_once JETPACK__PLUGIN_DIR . '_inc/lib/class.jetpack-search-performance-logger.php';
//                Jetpack_Search_Performance_Logger::init();
//            }
		}

		if ( self::is_active() ) {
			// Add wordpress.com to the safe redirect whitelist if Jetpack is connected
			// so the customizer can `return` to wordpress.com if invoked from there.
			add_action( 'customize_register', array( $this, 'add_wpcom_to_allowed_redirect_hosts' ) );
		}

		add_filter( 'determine_current_user', array( $this, 'wp_rest_authenticate' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'wp_rest_authentication_errors' ) );

		add_action( 'jetpack_clean_nonces', array( 'Jetpack', 'clean_nonces' ) );
		if ( ! wp_next_scheduled( 'jetpack_clean_nonces' ) ) {
			wp_schedule_event( time(), 'hourly', 'jetpack_clean_nonces' );
		}

		add_filter( 'xmlrpc_blog_options', array( $this, 'xmlrpc_options' ) );

//        add_action( 'admin_init', array( $this, 'admin_init' ) );
//        add_action( 'admin_init', array( $this, 'dismiss_jetpack_notice' ) );

//        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
//
//        add_action( 'wp_dashboard_setup', array( $this, 'wp_dashboard_setup' ) );
//        // Filter the dashboard meta box order to swap the new one in in place of the old one.
//        add_filter( 'get_user_option_meta-box-order_dashboard', array( $this, 'get_user_option_meta_box_order_dashboard' ) );
//
//        // returns HTTPS support status
//        add_action( 'wp_ajax_jetpack-recheck-ssl', array( $this, 'ajax_recheck_ssl' ) );
//
//        // If any module option is updated before Jump Start is dismissed, hide Jump Start.
//        add_action( 'update_option', array( $this, 'jumpstart_has_updated_module_option' ) );
//
//        // JITM AJAX callback function
//        add_action( 'wp_ajax_jitm_ajax',  array( $this, 'jetpack_jitm_ajax_callback' ) );
//
//        // Universal ajax callback for all tracking events triggered via js
//        add_action( 'wp_ajax_jetpack_tracks', array( $this, 'jetpack_admin_ajax_tracks_callback' ) );
//
//        add_action( 'wp_ajax_Jetpack_Connect_banner', array( $this, 'Jetpack_Connect_banner_callback' ) );

//        add_action( 'wp_loaded', array( $this, 'register_assets' ) );
//        add_action( 'wp_enqueue_scripts', array( $this, 'devicepx' ) );
//        add_action( 'customize_controls_enqueue_scripts', array( $this, 'devicepx' ) );
//        add_action( 'admin_enqueue_scripts', array( $this, 'devicepx' ) );
//
//        // gutenberg locale
//        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_gutenberg_locale' ) );
//
//        add_action( 'plugins_loaded', array( $this, 'extra_oembed_providers' ), 100 );
//
//        /**
//         * These actions run checks to load additional files.
//         * They check for external files or plugins, so they need to run as late as possible.
//         */
//        add_action( 'wp_head', array( $this, 'check_open_graph' ),       1 );
//        add_action( 'plugins_loaded', array( $this, 'check_twitter_tags' ),     999 );
//        add_action( 'plugins_loaded', array( $this, 'check_rest_api_compat' ), 1000 );
//
//        add_filter( 'plugins_url',      array( 'Jetpack', 'maybe_min_asset' ),     1, 3 );
//        add_action( 'style_loader_src', array( 'Jetpack', 'set_suffix_on_min' ), 10, 2  );
//        add_filter( 'style_loader_tag', array( 'Jetpack', 'maybe_inline_style' ), 10, 2 );
//
//        add_filter( 'map_meta_cap', array( $this, 'jetpack_custom_caps' ), 1, 4 );
//
//        add_filter( 'jetpack_get_default_modules', array( $this, 'filter_default_modules' ) );
//        add_filter( 'jetpack_get_default_modules', array( $this, 'handle_deprecated_modules' ), 99 );
//
//        // A filter to control all just in time messages
//        add_filter( 'jetpack_just_in_time_msgs', '__return_true', 9 );
//        add_filter( 'jetpack_just_in_time_msg_cache', '__return_true', 9);

//        // If enabled, point edit post, page, and comment links to Calypso instead of WP-Admin.
//        // We should make sure to only do this for front end links.
//        if ( Jetpack_Connect_Options::get_option( 'edit_links_calypso_redirect' ) && ! is_admin() ) {
//            add_filter( 'get_edit_post_link', array( $this, 'point_edit_post_links_to_calypso' ), 1, 2 );
//            add_filter( 'get_edit_comment_link', array( $this, 'point_edit_comment_links_to_calypso' ), 1 );
//
//            //we'll override wp_notify_postauthor and wp_notify_moderator pluggable functions
//            //so they point moderation links on emails to Calypso
//            jetpack_require_lib( 'functions.wp-notify' );
//        }

		// Update the Jetpack plan from API on heartbeats
//        add_action( 'jetpack_heartbeat', array( $this, 'refresh_active_plan_from_wpcom' ) );

//        /**
//         * This is the hack to concatenate all css files into one.
//         * For description and reasoning see the implode_frontend_css method
//         *
//         * Super late priority so we catch all the registered styles
//         */
//        if( !is_admin() ) {
//            add_action( 'wp_print_styles', array( $this, 'implode_frontend_css' ), -1 ); // Run first
//            add_action( 'wp_print_footer_scripts', array( $this, 'implode_frontend_css' ), -1 ); // Run first to trigger before `print_late_styles`
//        }

//        /**
//         * These are sync actions that we need to keep track of for jitms
//         */
//        add_filter( 'jetpack_sync_before_send_updated_option', array( $this, 'jetpack_track_last_sync_callback' ), 99 );

//        // Actually push the stats on shutdown.
//        if ( ! has_action( 'shutdown', array( $this, 'push_stats' ) ) ) {
//            add_action( 'shutdown', array( $this, 'push_stats' ) );
//        }
	}

	/**
	 * This is ported over from the manage module, which has been deprecated and baked in here.
	 *
	 * @param $domains
	 */
	function add_wpcom_to_allowed_redirect_hosts( $domains ) {
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_wpcom_domain' ) );
	}

	/**
	 * Return $domains, with 'wordpress.com' appended.
	 * This is ported over from the manage module, which has been deprecated and baked in here.
	 *
	 * @param $domains
	 * @return array
	 */
	function allow_wpcom_domain( $domains ) {
		if ( empty( $domains ) ) {
			$domains = array();
		}
		$domains[] = 'wordpress.com';
		return array_unique( $domains );
	}

	function require_jetpack_authentication() {
		// Don't let anyone authenticate
		$_COOKIE = array();
		remove_all_filters( 'authenticate' );
		remove_all_actions( 'wp_login_failed' );

		if ( self::is_active() ) {
			// Allow Jetpack authentication
			add_filter( 'authenticate', array( $this, 'authenticate_jetpack' ), 10, 3 );
		}
	}

	function add_remote_request_handlers() {
		add_action( 'wp_ajax_nopriv_jetpack_upload_file', array( $this, 'remote_request_handlers' ) );
		add_action( 'wp_ajax_nopriv_jetpack_update_file', array( $this, 'remote_request_handlers' ) );
	}

	/**
	 * Authenticates XML-RPC and other requests from the Jetpack Server
	 */
	function authenticate_jetpack( $user, $username, $password ) {
		if ( is_a( $user, 'WP_User' ) ) {
			return $user;
		}

		$token_details = $this->verify_xml_rpc_signature();

		if ( ! $token_details || is_wp_error( $token_details ) ) {
			return $user;
		}

		if ( 'user' !== $token_details['type'] ) {
			return $user;
		}

		if ( ! $token_details['user_id'] ) {
			return $user;
		}

		nocache_headers();

		return new WP_User( $token_details['user_id'] );
	}

	function admin_page_load() {
		if ( ! empty( $_GET['jetpack_restate'] ) ) {
			// Should only be used in intermediate redirects to preserve state across redirects
			Jetpack_Connect::restate();
		}

		$error = false;
		if ( isset( $_GET['connect_url_redirect'] ) ) {
			// User clicked in the iframe to link their accounts
			if ( ! Jetpack_Connect::is_user_connected() ) {
				$from = ! empty( $_GET['from'] ) ? $_GET['from'] : 'iframe';
				$redirect = ! empty( $_GET['redirect_after_auth'] ) ? $_GET['redirect_after_auth'] : false;

				add_filter( 'allowed_redirect_hosts', array( &$this, 'allow_wpcom_environments' ) );
				$connect_url = $this->build_connect_url( true, $redirect, $from );
				remove_filter( 'allowed_redirect_hosts', array( &$this, 'allow_wpcom_environments' ) );

				if ( isset( $_GET['notes_iframe'] ) )
					$connect_url .= '&notes_iframe';
				wp_redirect( $connect_url );
				exit;
			} else {
				if ( ! isset( $_GET['calypso_env'] ) ) {
					wp_safe_redirect( Jetpack_Connect::admin_url() );
					exit;
				} else {
					$connect_url = $this->build_connect_url( true, false, 'iframe' );
					$connect_url .= '&already_authorized=true';
					wp_redirect( $connect_url );
					exit;
				}
			}
		}


		if ( isset( $_GET['action'] ) ) {
			switch ( $_GET['action'] ) {
				case 'authorize':
					if ( Jetpack_Connect::is_active() && Jetpack_Connect::is_user_connected() ) {
						Jetpack_Connect::state( 'message', 'already_authorized' );
						wp_safe_redirect( Jetpack_Connect::admin_url() );
						exit;
					}
					$client_server = new Jetpack_Connect_Client_Server;
					$client_server->client_authorize();
					exit;
				case 'register' :
//                    error_log( 1 );
					if ( ! current_user_can( 'jetpack_connect' ) ) {
						$error = 'cheatin';
//                        error_log( 2 );
//                        break;
					}
//                    error_log( 3 );
					check_admin_referer( 'jetpack-register' );
					$registered = Jetpack_Connect::try_registration();
					if ( is_wp_error( $registered ) ) {
						Jetpack_Connect::state( 'error', $error );
						Jetpack_Connect::state( 'error', $registered->get_error_message() );
						$error = $registered->get_error_code();
						break;
					}
//                    error_log( 4 );
					$from = isset( $_GET['from'] ) ? $_GET['from'] : false;
					$redirect = isset( $_GET['redirect'] ) ? $_GET['redirect'] : false;

					$url = $this->build_connect_url( true, $redirect, $from );

					if ( ! empty( $_GET['onboarding'] ) ) {
						$url = add_query_arg( 'onboarding', $_GET['onboarding'], $url );
					}

					if ( ! empty( $_GET['auth_approved'] ) && 'true' === $_GET['auth_approved'] ) {
						$url = add_query_arg( 'auth_approved', 'true', $url );
					}

					wp_redirect( $url );
					exit;
				case 'activate' :
					if ( ! current_user_can( 'jetpack_activate_modules' ) ) {
						$error = 'cheatin';
						break;
					}

					$module = stripslashes( $_GET['module'] );
					check_admin_referer( "jetpack_activate-$module" );
					// The following two lines will rarely happen, as Jetpack_Connect::activate_module normally exits at the end.
					wp_safe_redirect( Jetpack_Connect::admin_url( 'page=vaultpress' ) );
					exit;
				case 'activate_default_modules' :
					// This step not needed
					wp_safe_redirect( Jetpack_Connect::admin_url( 'page=vaultpress' ) );
					exit;
				case 'disconnect' :
					if ( ! current_user_can( 'jetpack_disconnect' ) ) {
						$error = 'cheatin';
						break;
					}

					check_admin_referer( 'jetpack-disconnect' );
					Jetpack_Connect::disconnect();
					wp_safe_redirect( Jetpack_Connect::admin_url( 'disconnected=true' ) );
					exit;
				case 'reconnect' :
					if ( ! current_user_can( 'jetpack_reconnect' ) ) {
						$error = 'cheatin';
						break;
					}

					check_admin_referer( 'jetpack-reconnect' );
					$this->disconnect();
					wp_redirect( $this->build_connect_url( true, false, 'reconnect' ) );
					exit;
				case 'deactivate' :
					// @todo not needed
					wp_safe_redirect( Jetpack_Connect::admin_url( 'page=vaultpress' ) );
					exit;
				case 'unlink' :
//                    $redirect = isset( $_GET['redirect'] ) ? $_GET['redirect'] : '';
//                    check_admin_referer( 'jetpack-unlink' );
//                    $this->unlink_user();
//                    if ( 'sub-unlink' == $redirect ) {
//                        wp_safe_redirect( admin_url() );
//                    } else {
//                        wp_safe_redirect( Jetpack_Connect::admin_url( array( 'page' => $redirect ) ) );
//                    }
					exit;
				default:
					/**
					 * Fires when a Jetpack admin page is loaded with an unrecognized parameter.
					 *
					 * @since 2.6.0
					 *
					 * @param string sanitize_key( $_GET['action'] ) Unrecognized URL parameter.
					 */
					do_action( 'jetpack_unrecognized_action', sanitize_key( $_GET['action'] ) );
			}
		}
	}

	/**
	 * Takes the response from the Jetpack register new site endpoint and
	 * verifies it worked properly.
	 *
	 * @since 2.6
	 * @return string|WP_Error A JSON object on success or WP_Error on failures
	 **/
	public function validate_remote_register_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'register_http_request_failed', $response->get_error_message() );
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$entity = wp_remote_retrieve_body( $response );
		if ( $entity )
			$registration_response = json_decode( $entity );
		else
			$registration_response = false;

		$code_type = intval( $code / 100 );
		if ( 5 == $code_type ) {
			return new WP_Error( 'wpcom_5??', sprintf( __( 'Error Details: %s', 'jetpack' ), $code ), $code );
		} elseif ( 408 == $code ) {
			return new WP_Error( 'wpcom_408', sprintf( __( 'Error Details: %s', 'jetpack' ), $code ), $code );
		} elseif ( ! empty( $registration_response->error ) ) {
			if ( 'xml_rpc-32700' == $registration_response->error && ! function_exists( 'xml_parser_create' ) ) {
				$error_description = __( "PHP's XML extension is not available. Jetpack requires the XML extension to communicate with WordPress.com. Please contact your hosting provider to enable PHP's XML extension.", 'jetpack' );
			} else {
				$error_description = isset( $registration_response->error_description ) ? sprintf( __( 'Error Details: %s', 'jetpack' ), (string) $registration_response->error_description ) : '';
			}

			return new WP_Error( (string) $registration_response->error, $error_description, $code );
		} elseif ( 200 != $code ) {
			return new WP_Error( 'wpcom_bad_response', sprintf( __( 'Error Details: %s', 'jetpack' ), $code ), $code );
		}

		// Jetpack ID error block
		if ( empty( $registration_response->jetpack_id ) ) {
			return new WP_Error( 'jetpack_id', sprintf( __( 'Error Details: Jetpack ID is empty. Do not publicly post this error message! %s', 'jetpack' ), $entity ), $entity );
		} elseif ( ! is_scalar( $registration_response->jetpack_id ) ) {
			return new WP_Error( 'jetpack_id', sprintf( __( 'Error Details: Jetpack ID is not a scalar. Do not publicly post this error message! %s', 'jetpack' ) , $entity ), $entity );
		} elseif ( preg_match( '/[^0-9]/', $registration_response->jetpack_id ) ) {
			return new WP_Error( 'jetpack_id', sprintf( __( 'Error Details: Jetpack ID begins with a numeral. Do not publicly post this error message! %s', 'jetpack' ) , $entity ), $entity );
		}

		return $registration_response;
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function register() {
		// @todo tracking
//        JetpackTracking::record_user_event( 'jpc_register_begin' );
		add_action( 'pre_update_jetpack_option_register', array( 'Jetpack_Connect_Options', 'delete_option' ) );
		$secrets = Jetpack_Connect::generate_secrets( 'register' );

		if (
			empty( $secrets['secret_1'] ) ||
			empty( $secrets['secret_2'] ) ||
			empty( $secrets['exp'] )
		) {
			return new WP_Error( 'missing_secrets' );
		}

		// better to try (and fail) to set a higher timeout than this system
		// supports than to have register fail for more users than it should
		$timeout = self::set_min_time_limit( 60 ) / 2;

		$gmt_offset = get_option( 'gmt_offset' );
		if ( ! $gmt_offset ) {
			$gmt_offset = 0;
		}

		$stats_options = get_option( 'stats_options' );
		$stats_id = isset($stats_options['blog_id']) ? $stats_options['blog_id'] : null;

		$args = array(
			'method'  => 'POST',
			'body'    => array(
				'siteurl'         => site_url(),
				'home'            => home_url(),
				'gmt_offset'      => $gmt_offset,
				'timezone_string' => (string) get_option( 'timezone_string' ),
				'site_name'       => (string) get_option( 'blogname' ),
				'secret_1'        => $secrets['secret_1'],
				'secret_2'        => $secrets['secret_2'],
				'site_lang'       => get_locale(),
				'timeout'         => $timeout,
				'stats_id'        => $stats_id,
				'state'           => get_current_user_id(),
//                '_ui'             => $tracks_identity['_ui'],
//                '_ut'             => $tracks_identity['_ut'],
				'jetpack_version' => JETPACK__VERSION
			),
			'headers' => array(
				'Accept' => 'application/json',
			),
			'timeout' => $timeout,
		);

		self::apply_activation_source_to_args( $args['body'] );

		$response = Jetpack_Connect_Client::_wp_remote_request( Jetpack_Connect::fix_url_for_bad_hosts( Jetpack_Connect::api_url( 'register' ) ), $args, true );

		// Make sure the response is valid and does not contain any Jetpack errors
		$registration_details = Jetpack_Connect::init()->validate_remote_register_response( $response );
		if ( is_wp_error( $registration_details ) ) {
			return $registration_details;
		} elseif ( ! $registration_details ) {
			return new WP_Error( 'unknown_error', __( 'Unknown error registering your Jetpack site', 'jetpack' ), wp_remote_retrieve_response_code( $response ) );
		}

		if ( empty( $registration_details->jetpack_secret ) || ! is_string( $registration_details->jetpack_secret ) ) {
			return new WP_Error( 'jetpack_secret', '', wp_remote_retrieve_response_code( $response ) );
		}

		if ( isset( $registration_details->jetpack_public ) ) {
			$jetpack_public = (int) $registration_details->jetpack_public;
		} else {
			$jetpack_public = false;
		}

		Jetpack_Connect_Options::update_options(
			array(
				'id'         => (int)    $registration_details->jetpack_id,
				'blog_token' => (string) $registration_details->jetpack_secret,
				'public'     => $jetpack_public,
			)
		);

		/**
		 * Fires when a site is registered on WordPress.com.
		 *
		 * @since 3.7.0
		 *
		 * @param int $json->jetpack_id Jetpack Blog ID.
		 * @param string $json->jetpack_secret Jetpack Blog Token.
		 * @param int|bool $jetpack_public Is the site public.
		 */
		do_action( 'jetpack_site_registered', $registration_details->jetpack_id, $registration_details->jetpack_secret, $jetpack_public );

		// Initialize Jump Start for the first and only time.
		if ( ! Jetpack_Connect_Options::get_option( 'jumpstart' ) ) {
			Jetpack_Connect_Options::update_option( 'jumpstart', 'new_connection' );

			$jetpack = Jetpack_Connect::init();
		};

		return true;
	}

	/**
	 * Builds a URL to the Jetpack connection auth page
	 *
	 * @since 3.9.5
	 *
	 * @param bool $raw If true, URL will not be escaped.
	 * @param bool|string $redirect If true, will redirect back to Jetpack wp-admin landing page after connection.
	 *                              If string, will be a custom redirect.
	 * @param bool|string $from If not false, adds 'from=$from' param to the connect URL.
	 * @param bool $register If true, will generate a register URL regardless of the existing token, since 4.9.0
	 *
	 * @return string Connect URL
	 */
	function build_connect_url( $raw = false, $redirect = false, $from = false, $register = false ) {
		$site_id = Jetpack_Connect_Options::get_option( 'id' );
		$token = Jetpack_Connect_Options::get_option( 'blog_token' );

		if ( $register || ! $token || ! $site_id ) {
			$url = Jetpack_Connect::nonce_url_no_esc( Jetpack_Connect::admin_url( 'action=register' ), 'jetpack-register' );

			if ( ! empty( $redirect ) ) {
				$url = add_query_arg(
					'redirect',
					urlencode( wp_validate_redirect( esc_url_raw( $redirect ) ) ),
					$url
				);
			}

			if( is_network_admin() ) {
				$url = add_query_arg( 'is_multisite', network_admin_url( 'admin.php?page=jetpack-settings' ), $url );
			}
		} else {

			// Let's check the existing blog token to see if we need to re-register. We only check once per minute
			// because otherwise this logic can get us in to a loop.
			$last_connect_url_check = intval( Jetpack_Connect_Options::get_raw_option( 'jetpack_last_connect_url_check' ) );
			if ( ! $last_connect_url_check || ( time() - $last_connect_url_check ) > MINUTE_IN_SECONDS ) {
				Jetpack_Connect_Options::update_raw_option( 'jetpack_last_connect_url_check', time() );

				$response = Jetpack_Connect_Client::wpcom_json_api_request_as_blog(
					sprintf( '/sites/%d', $site_id ) .'?force=wpcom',
					'1.1'
				);

				if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
					// Generating a register URL instead to refresh the existing token
					return $this->build_connect_url( $raw, $redirect, $from, true );
				}
			}

//            if ( defined( 'JETPACK__GLOTPRESS_LOCALES_PATH' ) && include_once JETPACK__GLOTPRESS_LOCALES_PATH ) {
//                $gp_locale = GP_Locales::by_field( 'wp_locale', get_locale() );
//            }

			$role = self::translate_current_user_to_role();
			$signed_role = self::sign_role( $role );

			$user = wp_get_current_user();

			$jetpack_admin_page = esc_url_raw( admin_url( 'admin.php?page=vaultpress' ) );
			$redirect = $redirect
				? wp_validate_redirect( esc_url_raw( $redirect ), $jetpack_admin_page )
				: $jetpack_admin_page;

			if( isset( $_REQUEST['is_multisite'] ) ) {
				$redirect = Jetpack_Network::init()->get_url( 'network_admin_page' );
			}

			$secrets = Jetpack_Connect::generate_secrets( 'authorize', false, 2 * HOUR_IN_SECONDS );

			$site_icon = ( function_exists( 'has_site_icon') && has_site_icon() )
				? get_site_icon_url()
				: false;

			/**
			 * Filter the type of authorization.
			 * 'calypso' completes authorization on wordpress.com/jetpack/connect
			 * while 'jetpack' ( or any other value ) completes the authorization at jetpack.wordpress.com.
			 *
			 * @since 4.3.3
			 *
			 * @param string $auth_type Defaults to 'calypso', can also be 'jetpack'.
			 */
			$auth_type = apply_filters( 'jetpack_auth_type', 'calypso' );

//            $tracks_identity = jetpack_tracks_get_identity( get_current_user_id() );

			$args = urlencode_deep(
				array(
					'response_type' => 'code',
					'client_id'     => Jetpack_Connect_Options::get_option( 'id' ),
					'redirect_uri'  => add_query_arg(
						array(
							'action'   => 'authorize',
							'_wpnonce' => wp_create_nonce( "jetpack-authorize_{$role}_{$redirect}" ),
							'redirect' => urlencode( $redirect ),
						),
						esc_url( admin_url( 'admin.php?page=vaultpress' ) )
					),
					'state'         => $user->ID,
					'scope'         => $signed_role,
					'user_email'    => $user->user_email,
					'user_login'    => $user->user_login,
					'is_active'     => Jetpack_Connect::is_active(),
					'jp_version'    => JETPACK__VERSION,
					'auth_type'     => $auth_type,
					'secret'        => $secrets['secret_1'],
					'locale'        => ( isset( $gp_locale ) && isset( $gp_locale->slug ) ) ? $gp_locale->slug : '',
					'blogname'      => get_option( 'blogname' ),
					'site_url'      => site_url(),
					'home_url'      => home_url(),
					'site_icon'     => $site_icon,
					'site_lang'     => get_locale(),
//                    '_ui'           => $tracks_identity['_ui'],
//                    '_ut'           => $tracks_identity['_ut']
				)
			);

			self::apply_activation_source_to_args( $args );

			$url = add_query_arg( $args, Jetpack_Connect::api_url( 'authorize' ) );
		}

		if ( $from ) {
			$url = add_query_arg( 'from', $from, $url );
		}


		if ( isset( $_GET['calypso_env'] ) ) {
			$url = add_query_arg( 'calypso_env', sanitize_key( $_GET['calypso_env'] ), $url );
		}

		return $raw ? $url : esc_url( $url );
	}

	static function translate_current_user_to_role() {
		foreach ( self::$capability_translations as $role => $cap ) {
			if ( current_user_can( $role ) || current_user_can( $cap ) ) {
				return $role;
			}
		}

		return false;
	}

	static function translate_user_to_role( $user ) {
		foreach ( self::$capability_translations as $role => $cap ) {
			if ( user_can( $user, $role ) || user_can( $user, $cap ) ) {
				return $role;
			}
		}

		return false;
	}

	static function translate_role_to_cap( $role ) {
		if ( ! isset( self::$capability_translations[$role] ) ) {
			return false;
		}

		return self::$capability_translations[$role];
	}

	static function sign_role( $role, $user_id = null ) {
		if ( empty( $user_id ) ) {
			$user_id = (int) get_current_user_id();
		}

		if ( ! $user_id  ) {
			return false;
		}

		$token = Jetpack_Connect_Data::get_access_token();
		if ( ! $token || is_wp_error( $token ) ) {
			return false;
		}

		return $role . ':' . hash_hmac( 'md5', "{$role}|{$user_id}", $token->secret );
	}

	/**
	 * Attempts Jetpack registration.  If it fail, a state flag is set: @see ::admin_page_load()
	 */
	public static function try_registration() {
		// The user has agreed to the TOS at some point by now.
		Jetpack_Connect_Options::update_option( 'tos_agreed', true );

		// Let's get some testing in beta versions and such.
		// @todo
//        if ( self::is_development_version() && defined( 'PHP_URL_HOST' ) ) {
//            // Before attempting to connect, let's make sure that the domains are viable.
//            $domains_to_check = array_unique( array(
//                'siteurl' => parse_url( get_site_url(), PHP_URL_HOST ),
//                'homeurl' => parse_url( get_home_url(), PHP_URL_HOST ),
//            ) );
//            foreach ( $domains_to_check as $domain ) {
//                $result = Jetpack_Connect_Data::is_usable_domain( $domain );
//                if ( is_wp_error( $result ) ) {
//                    return $result;
//                }
//            }
//        }

		$result = Jetpack_Connect::register();

		// If there was an error with registration and the site was not registered, record this so we can show a message.
		if ( ! $result || is_wp_error( $result ) ) {
			return $result;
		} else {
			return true;
		}
	}

	/**
	 * Is Jetpack active?
	 */
	public static function is_active() {
		return (bool) Jetpack_Connect_Data::get_access_token( JETPACK_MASTER_USER );
	}

	/**
	 * Is a given user (or the current user if none is specified) linked to a WordPress.com user?
	 */
	public static function is_user_connected( $user_id = false ) {
		$user_id = false === $user_id ? get_current_user_id() : absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		return (bool) Jetpack_Connect_Data::get_access_token( $user_id );
	}

	public static function admin_url( $args = null ) {
		$args = wp_parse_args( $args, array( 'page' => 'vaultpress' ) );
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		return $url;
	}

	/**
	 * Returns the Jetpack XML-RPC API
	 *
	 * @return string
	 */
	public static function xmlrpc_api_url() {
		$base = preg_replace( '#(https?://[^?/]+)(/?.*)?$#', '\\1', JETPACK__API_BASE );
		return untrailingslashit( $base ) . '/xmlrpc.php';
	}

	/**
	 * State is passed via cookies from one request to the next, but never to subsequent requests.
	 * SET: state( $key, $value );
	 * GET: $value = state( $key );
	 *
	 * @param string $key
	 * @param string $value
	 * @param bool $restate private
	 */
	public static function state( $key = null, $value = null, $restate = false ) {
		static $state = array();
		static $path, $domain;
		if ( ! isset( $path ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			$admin_url = Jetpack_Connect::admin_url();
			$bits      = parse_url( $admin_url );

			if ( is_array( $bits ) ) {
				$path   = ( isset( $bits['path'] ) ) ? dirname( $bits['path'] ) : null;
				$domain = ( isset( $bits['host'] ) ) ? $bits['host'] : null;
			} else {
				$path = $domain = null;
			}
		}

		// Extract state from cookies and delete cookies
		if ( isset( $_COOKIE[ 'jetpackState' ] ) && is_array( $_COOKIE[ 'jetpackState' ] ) ) {
			$yum = $_COOKIE[ 'jetpackState' ];
			unset( $_COOKIE[ 'jetpackState' ] );
			foreach ( $yum as $k => $v ) {
				if ( strlen( $v ) )
					$state[ $k ] = $v;
				setcookie( "jetpackState[$k]", false, 0, $path, $domain );
			}
		}

		if ( $restate ) {
			foreach ( $state as $k => $v ) {
				setcookie( "jetpackState[$k]", $v, 0, $path, $domain );
			}
			return;
		}

		// Get a state variable
		if ( isset( $key ) && ! isset( $value ) ) {
			if ( array_key_exists( $key, $state ) )
				return $state[ $key ];
			return null;
		}

		// Set a state variable
		if ( isset ( $key ) && isset( $value ) ) {
			if( is_array( $value ) && isset( $value[0] ) ) {
				$value = $value[0];
			}
			$state[ $key ] = $value;
			setcookie( "jetpackState[$key]", $value, 0, $path, $domain );
		}
	}

	public static function restate() {
		self::state( null, null, true );
	}

	/**
	 * Creates two secret tokens and the end of life timestamp for them.
	 *
	 * Note these tokens are unique per call, NOT static per site for connecting.
	 *
	 * @since 2.6
	 * @return array
	 */
	public static function generate_secrets( $action, $user_id = false, $exp = 600 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$secret_name  = 'jetpack_' . $action . '_' . $user_id;
		$secrets      = Jetpack_Connect_Options::get_raw_option( 'jetpack_secrets', array() );

		if (
			isset( $secrets[ $secret_name ] ) &&
			$secrets[ $secret_name ]['exp'] > time()
		) {
			return $secrets[ $secret_name ];
		}

		$secret_value = array(
			'secret_1'  => wp_generate_password( 32, false ),
			'secret_2'  => wp_generate_password( 32, false ),
			'exp'       => time() + $exp,
		);

		$secrets[ $secret_name ] = $secret_value;

		Jetpack_Connect_Options::update_raw_option( 'jetpack_secrets', $secrets );
		return $secrets[ $secret_name ];
	}

	public static function get_secrets( $action, $user_id ) {
		$secret_name = 'jetpack_' . $action . '_' . $user_id;
		$secrets = Jetpack_Connect_Options::get_raw_option( 'jetpack_secrets', array() );

		if ( ! isset( $secrets[ $secret_name ] ) ) {
			return new WP_Error( 'verify_secrets_missing', 'Verification secrets not found' );
		}

		if ( $secrets[ $secret_name ]['exp'] < time() ) {
			self::delete_secrets( $action, $user_id );
			return new WP_Error( 'verify_secrets_expired', 'Verification took too long' );
		}

		return $secrets[ $secret_name ];
	}

	function verify_xml_rpc_signature() {
		if ( $this->xmlrpc_verification ) {
			return $this->xmlrpc_verification;
		}

		// It's not for us
		if ( ! isset( $_GET['token'] ) || empty( $_GET['signature'] ) ) {
			return false;
		}

		@list( $token_key, $version, $user_id ) = explode( ':', $_GET['token'] );
		if (
			empty( $token_key )
			||
			empty( $version ) || strval( JETPACK__API_VERSION ) !== $version
		) {
			return false;
		}

		if ( '0' === $user_id ) {
			$token_type = 'blog';
			$user_id = 0;
		} else {
			$token_type = 'user';
			if ( empty( $user_id ) || ! ctype_digit( $user_id ) ) {
				return false;
			}
			$user_id = (int) $user_id;

			$user = new WP_User( $user_id );
			if ( ! $user || ! $user->exists() ) {
				return false;
			}
		}

		$token = Jetpack_Connect_Data::get_access_token( $user_id );
		if ( ! $token ) {
			return false;
		}

		$token_check = "$token_key.";
		if ( ! hash_equals( substr( $token->secret, 0, strlen( $token_check ) ), $token_check ) ) {
			return false;
		}

		require_once JETPACK__PLUGIN_DIR . 'class.jetpack-connect-signature.php';

		$jetpack_signature = new Jetpack_Connect_Signature( $token->secret, (int) Jetpack_Connect_Options::get_option( 'time_diff' ) );
		if ( isset( $_POST['_jetpack_is_multipart'] ) ) {
			$post_data   = $_POST;
			$file_hashes = array();
			foreach ( $post_data as $post_data_key => $post_data_value ) {
				if ( 0 !== strpos( $post_data_key, '_jetpack_file_hmac_' ) ) {
					continue;
				}
				$post_data_key = substr( $post_data_key, strlen( '_jetpack_file_hmac_' ) );
				$file_hashes[$post_data_key] = $post_data_value;
			}

			foreach ( $file_hashes as $post_data_key => $post_data_value ) {
				unset( $post_data["_jetpack_file_hmac_{$post_data_key}"] );
				$post_data[$post_data_key] = $post_data_value;
			}

			ksort( $post_data );

			$body = http_build_query( stripslashes_deep( $post_data ) );
		} elseif ( is_null( $this->HTTP_RAW_POST_DATA ) ) {
			$body = file_get_contents( 'php://input' );
		} else {
			$body = null;
		}

		$signature = $jetpack_signature->sign_current_request(
			array( 'body' => is_null( $body ) ? $this->HTTP_RAW_POST_DATA : $body, )
		);

		if ( ! $signature ) {
			return false;
		} else if ( is_wp_error( $signature ) ) {
			return $signature;
		} else if ( ! hash_equals( $signature, $_GET['signature'] ) ) {
			return false;
		}

		$timestamp = (int) $_GET['timestamp'];
		$nonce     = stripslashes( (string) $_GET['nonce'] );

		if ( ! $this->add_nonce( $timestamp, $nonce ) ) {
			return false;
		}

		// Let's see if this is onboarding. In such case, use user token type and the provided user id.
		if ( isset( $this->HTTP_RAW_POST_DATA ) || ! empty( $_GET['onboarding'] ) ) {
			if ( ! empty( $_GET['onboarding'] ) ) {
				$jpo = $_GET;
			} else {
				$jpo = json_decode( $this->HTTP_RAW_POST_DATA, true );
			}

			$jpo_token = ! empty( $jpo['onboarding']['token'] ) ? $jpo['onboarding']['token'] : null;
			$jpo_user = ! empty( $jpo['onboarding']['jpUser'] ) ? $jpo['onboarding']['jpUser'] : null;

			if (
				isset( $jpo_user ) && isset( $jpo_token ) &&
				is_email( $jpo_user ) && ctype_alnum( $jpo_token ) &&
				isset( $_GET['rest_route'] ) &&
				self::validate_onboarding_token_action( $jpo_token, $_GET['rest_route'] )
			) {
				$jpUser = get_user_by( 'email', $jpo_user );
				if ( is_a( $jpUser, 'WP_User' ) ) {
					wp_set_current_user( $jpUser->ID );
					$user_can = is_multisite()
						? current_user_can_for_blog( get_current_blog_id(), 'manage_options' )
						: current_user_can( 'manage_options' );
					if ( $user_can ) {
						$token_type = 'user';
						$token->external_user_id = $jpUser->ID;
					}
				}
			}
		}

		$this->xmlrpc_verification = array(
			'type'    => $token_type,
			'user_id' => $token->external_user_id,
		);

		return $this->xmlrpc_verification;
	}

	public static function delete_secrets( $action, $user_id ) {
		$secret_name = 'jetpack_' . $action . '_' . $user_id;
		$secrets = Jetpack_Connect_Options::get_raw_option( 'jetpack_secrets', array() );
		if ( isset( $secrets[ $secret_name ] ) ) {
			unset( $secrets[ $secret_name ] );
			Jetpack_Connect_Options::update_raw_option( 'jetpack_secrets', $secrets );
		}
	}

	/**
	 * Checks if the site is currently in an identity crisis.
	 *
	 * @return array|bool Array of options that are in a crisis, or false if everything is OK.
	 */
	public static function check_identity_crisis() {
		return false;
		if ( ! self::is_active() || self::is_development_mode() || ! self::validate_sync_error_idc_option() ) {
			return false;
		}

		return Jetpack_Connect_Options::get_option( 'sync_error_idc' );
	}

	/**
	 * Disconnects from the Jetpack servers.
	 * Forgets all connection details and tells the Jetpack servers to do the same.
	 * @static
	 */
	public static function disconnect( $update_activated_state = true ) {
		wp_clear_scheduled_hook( 'jetpack_clean_nonces' );
		self::clean_nonces( true );

		// If the site is in an IDC because sync is not allowed,
		// let's make sure to not disconnect the production site.
		if ( true ) {
//            JetpackTracking::record_user_event( 'disconnect_site', array() );
			self::load_xml_rpc_client();
			$xml = new Jetpack_Connect_IXR_Client();
			$xml->query( 'jetpack.deregister' );
		}

		Jetpack_Connect_Options::delete_option(
			array(
				'blog_token',
				'user_token',
				'user_tokens',
				'master_user',
				'time_diff',
				'fallback_no_verify_ssl_certs',
			)
		);
// @todo
//        Jetpack_IDC::clear_all_idc_options();
		Jetpack_Connect_Options::delete_raw_option( 'jetpack_secrets' );

		if ( $update_activated_state ) {
			Jetpack_Connect_Options::update_option( 'activated', 4 );
		}

		if ( $jetpack_unique_connection = Jetpack_Connect_Options::get_option( 'unique_connection' ) ) {
			// Check then record unique disconnection if site has never been disconnected previously
			if ( - 1 == $jetpack_unique_connection['disconnected'] ) {
				$jetpack_unique_connection['disconnected'] = 1;
			} else {
				if ( 0 == $jetpack_unique_connection['disconnected'] ) {
					//track unique disconnect
					$jetpack = self::init();
				}
				// increment number of times disconnected
				$jetpack_unique_connection['disconnected'] += 1;
			}

			Jetpack_Connect_Options::update_option( 'unique_connection', $jetpack_unique_connection );
		}

		// Delete cached connected user data
		$transient_key = "jetpack_connected_user_data_" . get_current_user_id();
		delete_transient( $transient_key );

		// Delete all the sync related data. Since it could be taking up space.
//        require_once JETPACK__PLUGIN_DIR . 'sync/class.jetpack-sync-sender.php';
//        Jetpack_Sync_Sender::get_instance()->uninstall();

		// Disable the Heartbeat cron
		// @todo
//        Jetpack_Heartbeat::init()->deactivate();
	}

	/**
	 * Sets a minimum request timeout, and returns the current timeout
	 *
	 * @since 5.4
	 **/
	public static function set_min_time_limit( $min_timeout ) {
		$timeout = self::get_max_execution_time();
		if ( $timeout < $min_timeout ) {
			$timeout = $min_timeout;
			set_time_limit( $timeout );
		}
		return $timeout;
	}

	/**
	 * Builds the timeout limit for queries talking with the wpcom servers.
	 *
	 * Based on local php max_execution_time in php.ini
	 *
	 * @since 5.4
	 * @return int
	 **/
	public static function get_max_execution_time() {
		$timeout = (int) ini_get( 'max_execution_time' );

		// Ensure exec time set in php.ini
		if ( ! $timeout ) {
			$timeout = 30;
		}
		return $timeout;
	}

	public static function apply_activation_source_to_args( &$args ) {
		list( $activation_source_name, $activation_source_keyword ) = get_option( 'jetpack_activation_source' );

		if ( $activation_source_name ) {
			$args['_as'] = urlencode( $activation_source_name );
		}

		if ( $activation_source_keyword ) {
			$args['_ak'] = urlencode( $activation_source_keyword );
		}
	}

	public static function nonce_url_no_esc( $actionurl, $action = -1, $name = '_wpnonce' ) {
		$actionurl = str_replace( '&amp;', '&', $actionurl );
		return add_query_arg( $name, wp_create_nonce( $action ), $actionurl );
	}

	function add_nonce( $timestamp, $nonce ) {
		global $wpdb;
		static $nonces_used_this_request = array();

		if ( isset( $nonces_used_this_request["$timestamp:$nonce"] ) ) {
			return $nonces_used_this_request["$timestamp:$nonce"];
		}

		// This should always have gone through Jetpack_Signature::sign_request() first to check $timestamp an $nonce
		$timestamp = (int) $timestamp;
		$nonce     = esc_sql( $nonce );

		// Raw query so we can avoid races: add_option will also update
		$show_errors = $wpdb->show_errors( false );

		$old_nonce = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$wpdb->options` WHERE option_name = %s", "jetpack_nonce_{$timestamp}_{$nonce}" )
		);

		if ( is_null( $old_nonce ) ) {
			$return = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
					"jetpack_nonce_{$timestamp}_{$nonce}",
					time(),
					'no'
				)
			);
		} else {
			$return = false;
		}

		$wpdb->show_errors( $show_errors );

		$nonces_used_this_request["$timestamp:$nonce"] = $return;

		return $return;
	}

	/**
	 * Must never be called statically
	 */
	function plugin_upgrade() {
		if ( self::is_active() ) {
			list( $version ) = explode( ':', Jetpack_Connect_Options::get_option( 'version' ) );
//            if ( JETPACK__VERSION != $version ) {
//                // Prevent multiple upgrades at once - only a single process should trigger
//                // an upgrade to avoid stampedes
//                if ( false !== get_transient( self::$plugin_upgrade_lock_key ) ) {
//                    return;
//                }
//
//                // Set a short lock to prevent multiple instances of the upgrade
//                set_transient( self::$plugin_upgrade_lock_key, 1, 10 );
//
//                // check which active modules actually exist and remove others from active_modules list
//                $modules = array_filter( $unfiltered_modules, array( 'Jetpack', 'is_module' ) );
//                if ( array_diff( $unfiltered_modules, $modules ) ) {
//                    self::update_active_modules( $modules );
//                }
//
//                add_action( 'init', array( __CLASS__, 'activate_new_modules' ) );
//
//                // Upgrade to 4.3.0
//                if ( Jetpack_Connect_Options::get_option( 'identity_crisis_whitelist' ) ) {
//                    Jetpack_Connect_Options::delete_option( 'identity_crisis_whitelist' );
//                }
//
//                // Make sure Markdown for posts gets turned back on
//                if ( ! get_option( 'wpcom_publish_posts_with_markdown' ) ) {
//                    update_option( 'wpcom_publish_posts_with_markdown', true );
//                }
//
//                if ( did_action( 'wp_loaded' ) ) {
//                    self::upgrade_on_load();
//                } else {
//                    add_action(
//                        'wp_loaded',
//                        array( __CLASS__, 'upgrade_on_load' )
//                    );
//                }
//            }
		}
	}

	/**
	 * Returns the requested Jetpack API URL
	 *
	 * @return string
	 */
	public static function api_url( $relative_url ) {
		return trailingslashit( JETPACK__API_BASE . $relative_url  ) . JETPACK__API_VERSION . '/';
	}


	/**
	 * Some hosts disable the OpenSSL extension and so cannot make outgoing HTTPS requsets
	 */
	public static function fix_url_for_bad_hosts( $url ) {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return $url;
		}

		switch ( JETPACK_CLIENT__HTTPS ) {
			case 'ALWAYS' :
				return $url;
			case 'NEVER' :
				return set_url_scheme( $url, 'http' );
			// default : case 'AUTO' :
		}

		// we now return the unmodified SSL URL by default, as a security precaution
		return $url;
	}

	public static function clean_nonces( $all = false ) {
		global $wpdb;

		$sql = "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE %s";
		$sql_args = array( $wpdb->esc_like( 'jetpack_nonce_' ) . '%' );

		if ( true !== $all ) {
			$sql .= ' AND CAST( `option_value` AS UNSIGNED ) < %d';
			$sql_args[] = time() - 3600;
		}

		$sql .= ' ORDER BY `option_id` LIMIT 100';

		$sql = $wpdb->prepare( $sql, $sql_args );

		for ( $i = 0; $i < 1000; $i++ ) {
			if ( ! $wpdb->query( $sql ) ) {
				break;
			}
		}
	}

	/**
	 * Enters a user token into the user_tokens option
	 *
	 * @param int $user_id
	 * @param string $token
	 * return bool
	 */
	public static function update_user_token( $user_id, $token, $is_master_user ) {
		// not designed for concurrent updates
		$user_tokens = Jetpack_Connect_Options::get_option( 'user_tokens' );
		if ( ! is_array( $user_tokens ) )
			$user_tokens = array();
		$user_tokens[$user_id] = $token;
		if ( $is_master_user ) {
			$master_user = $user_id;
			$options     = compact( 'user_tokens', 'master_user' );
		} else {
			$options = compact( 'user_tokens' );
		}
		return Jetpack_Connect_Options::update_options( $options );
	}

	/**
	 * Loads the Jetpack XML-RPC client
	 */
	public static function load_xml_rpc_client() {
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once JETPACK__PLUGIN_DIR . 'class.jetpack-connect-ixr-client.php';
	}

	/**
	 * Handles activating default modules as well general cleanup for the new connection.
	 *
	 * @param boolean $activate_sso                 Whether to activate the SSO module when activating default modules.
	 * @param boolean $redirect_on_activation_error Whether to redirect on activation error.
	 * @return void
	 */
	public static function handle_post_authorization_actions( $activate_sso = false, $redirect_on_activation_error = false ) {
//		$other_modules = $activate_sso
//			? array( 'sso' )
//			: array();
//
//		if ( $active_modules = Jetpack_Connect_Options::get_option( 'active_modules' ) ) {
//			Jetpack::delete_active_modules();
//
//			Jetpack::activate_default_modules( 999, 1, array_merge( $active_modules, $other_modules ), $redirect_on_activation_error, false );
//		} else {
//			Jetpack::activate_default_modules( false, false, $other_modules, $redirect_on_activation_error, false );
//		}

		// Since this is a fresh connection, be sure to clear out IDC options
		Jetpack_Connect_IDC::clear_all_idc_options();
		Jetpack_Connect_Options::delete_raw_option( 'jetpack_last_connect_url_check' );

		// Start nonce cleaner
		wp_clear_scheduled_hook( 'jetpack_clean_nonces' );
		wp_schedule_event( time(), 'hourly', 'jetpack_clean_nonces' );

		Jetpack_Connect::state( 'message', 'authorized' );
	}

	/**
	 * Normalizes a url by doing three things:
	 *  - Strips protocol
	 *  - Strips www
	 *  - Adds a trailing slash
	 *
	 * @since 4.4.0
	 * @param string $url
	 * @return WP_Error|string
	 */
	public static function normalize_url_protocol_agnostic( $url ) {
		$parsed_url = wp_parse_url( trailingslashit( esc_url_raw( $url ) ) );
		if ( ! $parsed_url || empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) ) {
			return new WP_Error( 'cannot_parse_url', sprintf( esc_html__( 'Cannot parse URL %s', 'jetpack' ), $url ) );
		}

		// Strip www and protocols
		$url = preg_replace( '/^www\./i', '', $parsed_url['host'] . $parsed_url['path'] );
		return $url;
	}

	/**
	 * Gets the value that is to be saved in the jetpack_sync_error_idc option.
	 *
	 * @since 4.4.0
	 * @since 5.4.0 Add transient since home/siteurl retrieved directly from DB
	 *
	 * @param array $response
	 * @return array Array of the local urls, wpcom urls, and error code
	 */
	public static function get_sync_error_idc_option( $response = array() ) {
		// Since the local options will hit the database directly, store the values
		// in a transient to allow for autoloading and caching on subsequent views.
		$local_options = get_transient( 'jetpack_idc_local' );
		if ( false === $local_options ) {
			require_once JETPACK__PLUGIN_DIR . 'sync/class.jetpack-sync-functions.php';
			$local_options = array(
				'home'    => Jetpack_Sync_Functions::home_url(),
				'siteurl' => Jetpack_Sync_Functions::site_url(),
			);
			set_transient( 'jetpack_idc_local', $local_options, MINUTE_IN_SECONDS );
		}

		$options = array_merge( $local_options, $response );

		$returned_values = array();
		foreach( $options as $key => $option ) {
			if ( 'error_code' === $key ) {
				$returned_values[ $key ] = $option;
				continue;
			}

			if ( is_wp_error( $normalized_url = self::normalize_url_protocol_agnostic( $option ) ) ) {
				continue;
			}

			$returned_values[ $key ] = $normalized_url;
		}

		set_transient( 'jetpack_idc_option', $returned_values, MINUTE_IN_SECONDS );

		return $returned_values;
	}

	/**
	 * Checks whether the sync_error_idc option is valid or not, and if not, will do cleanup.
	 *
	 * @since 4.4.0
	 * @since 5.4.0 Do not call get_sync_error_idc_option() unless site is in IDC
	 *
	 * @return bool
	 */
	public static function validate_sync_error_idc_option() {
		$is_valid = false;

		$idc_allowed = get_transient( 'jetpack_idc_allowed' );
		if ( false === $idc_allowed ) {
			$response = wp_remote_get( 'https://jetpack.com/is-idc-allowed/' );
			if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$json = json_decode( wp_remote_retrieve_body( $response ) );
				$idc_allowed = isset( $json, $json->result ) && $json->result ? '1' : '0';
				$transient_duration = HOUR_IN_SECONDS;
			} else {
				// If the request failed for some reason, then assume IDC is allowed and set shorter transient.
				$idc_allowed = '1';
				$transient_duration = 5 * MINUTE_IN_SECONDS;
			}

			set_transient( 'jetpack_idc_allowed', $idc_allowed, $transient_duration );
		}

		// Is the site opted in and does the stored sync_error_idc option match what we now generate?
		$sync_error = Jetpack_Connect_Options::get_option( 'sync_error_idc' );
		if ( $idc_allowed && $sync_error && self::sync_idc_optin() ) {
			$local_options = self::get_sync_error_idc_option();
			if ( $sync_error['home'] === $local_options['home'] && $sync_error['siteurl'] === $local_options['siteurl'] ) {
				$is_valid = true;
			}
		}

		/**
		 * Filters whether the sync_error_idc option is valid.
		 *
		 * @since 4.4.0
		 *
		 * @param bool $is_valid If the sync_error_idc is valid or not.
		 */
		$is_valid = (bool) apply_filters( 'jetpack_sync_error_idc_validation', $is_valid );

		if ( ! $idc_allowed || ( ! $is_valid && $sync_error ) ) {
			// Since the option exists, and did not validate, delete it
			Jetpack_Connect_Options::delete_option( 'sync_error_idc' );
		}

		return $is_valid;
	}

	/**
	 * Is Jetpack in development (offline) mode?
	 */
	public static function is_development_mode() {
		$development_mode = false;

		if ( defined( 'JETPACK_DEV_DEBUG' ) ) {
			$development_mode = JETPACK_DEV_DEBUG;
		} elseif ( $site_url = site_url() ) {
			$development_mode = false === strpos( $site_url, '.' );
		}

		/**
		 * Filters Jetpack's development mode.
		 *
		 * @see https://jetpack.com/support/development-mode/
		 *
		 * @since 2.2.1
		 *
		 * @param bool $development_mode Is Jetpack's development mode active.
		 */
		$development_mode = ( bool ) apply_filters( 'jetpack_development_mode', $development_mode );
		return $development_mode;
	}

	/**
	 * Checks whether the home and siteurl specifically are whitelisted
	 * Written so that we don't have re-check $key and $value params every time
	 * we want to check if this site is whitelisted, for example in footer.php
	 *
	 * @since  3.8.0
	 * @return bool True = already whitelisted False = not whitelisted
	 */
	public static function is_staging_site() {
		$is_staging = false;

		$known_staging = array(
			'urls' => array(
				'#\.staging\.wpengine\.com$#i', // WP Engine
				'#\.staging\.kinsta\.com$#i',   // Kinsta.com
			),
			'constants' => array(
				'IS_WPE_SNAPSHOT',      // WP Engine
				'KINSTA_DEV_ENV',       // Kinsta.com
				'WPSTAGECOACH_STAGING', // WP Stagecoach
				'JETPACK_STAGING_MODE', // Generic
			)
		);
		/**
		 * Filters the flags of known staging sites.
		 *
		 * @since 3.9.0
		 *
		 * @param array $known_staging {
		 *     An array of arrays that each are used to check if the current site is staging.
		 *     @type array $urls      URLs of staging sites in regex to check against site_url.
		 *     @type array $constants PHP constants of known staging/developement environments.
		 *  }
		 */
		$known_staging = apply_filters( 'jetpack_known_staging', $known_staging );

		if ( isset( $known_staging['urls'] ) ) {
			foreach ( $known_staging['urls'] as $url ){
				if ( preg_match( $url, site_url() ) ) {
					$is_staging = true;
					break;
				}
			}
		}

		if ( isset( $known_staging['constants'] ) ) {
			foreach ( $known_staging['constants'] as $constant ) {
				if ( defined( $constant ) && constant( $constant ) ) {
					$is_staging = true;
				}
			}
		}

		// Last, let's check if sync is erroring due to an IDC. If so, set the site to staging mode.
		if ( ! $is_staging && self::validate_sync_error_idc_option() ) {
			$is_staging = true;
		}

		/**
		 * Filters is_staging_site check.
		 *
		 * @since 3.9.0
		 *
		 * @param bool $is_staging If the current site is a staging site.
		 */
		return apply_filters( 'jetpack_is_staging_site', $is_staging );
	}

	/**
	 * Return whether we are dealing with a multi network setup or not.
	 * The reason we are type casting this is because we want to avoid the situation where
	 * the result is false since when is_main_network_option return false it cases
	 * the rest the get_option( 'jetpack_is_multi_network' ); to return the value that is set in the
	 * database which could be set to anything as opposed to what this function returns.
	 * @param  bool  $option
	 *
	 * @return boolean
	 */
	public function is_main_network_option( $option ) {
		// return '1' or ''
		return (string) (bool) Jetpack::is_multi_network();
	}

	/**
	 * Return true if we are with multi-site or multi-network false if we are dealing with single site.
	 *
	 * @param  string  $option
	 * @return boolean
	 */
	public function is_multisite( $option ) {
		return (string) (bool) is_multisite();
	}

	/**
	 * Implemented since there is no core is multi network function
	 * Right now there is no way to tell if we which network is the dominant network on the system
	 *
	 * @since  3.3
	 * @return boolean
	 */
	public static function is_multi_network() {
		global  $wpdb;

		// if we don't have a multi site setup no need to do any more
		if ( ! is_multisite() ) {
			return false;
		}

		$num_sites = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->site}" );
		if ( $num_sites > 1 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get back if the current site is single user site.
	 *
	 * @return bool
	 */
	public static function is_single_user_site() {
		global $wpdb;

		if ( false === ( $some_users = get_transient( 'jetpack_is_single_user' ) ) ) {
			$some_users = $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' LIMIT 2) AS someusers" );
			set_transient( 'jetpack_is_single_user', (int) $some_users, 12 * HOUR_IN_SECONDS );
		}
		return 1 === (int) $some_users;
	}

	/**
	 * jetpack_updates is saved in the following schema:
	 *
	 * array (
	 *      'plugins'                       => (int) Number of plugin updates available.
	 *      'themes'                        => (int) Number of theme updates available.
	 *      'wordpress'                     => (int) Number of WordPress core updates available.
	 *      'translations'                  => (int) Number of translation updates available.
	 *      'total'                         => (int) Total of all available updates.
	 *      'wp_update_version'             => (string) The latest available version of WordPress, only present if a WordPress update is needed.
	 * )
	 * @return array
	 */
	public static function get_updates() {
		$update_data = wp_get_update_data();

		// Stores the individual update counts as well as the total count.
		if ( isset( $update_data['counts'] ) ) {
			$updates = $update_data['counts'];
		}

		// If we need to update WordPress core, let's find the latest version number.
		if ( ! empty( $updates['wordpress'] ) ) {
			$cur = get_preferred_from_update_core();
			if ( isset( $cur->response ) && 'upgrade' === $cur->response ) {
				$updates['wp_update_version'] = $cur->current;
			}
		}
		return isset( $updates ) ? $updates : array();
	}

	public static function get_active_modules() {
		return array(
			'json-api',
			'manage',
		);
	}

	/**
	 * Extract a module's slug from its full path.
	 */
	public static function get_module_slug( $file ) {
		return str_replace( '.php', '', basename( $file ) );
	}

	/**
	 * List available Jetpack modules. Simply lists .php files in /modules/.
	 * Make sure to tuck away module "library" files in a sub-directory.
	 */
	public static function get_available_modules( $min_version = false, $max_version = false ) {
		return self::get_active_modules();
	}

	/**
	 * Gets all plugins currently active in values, regardless of whether they're
	 * traditionally activated or network activated.
	 *
	 * @todo Store the result in core's object cache maybe?
	 */
	public static function get_active_plugins() {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			// Due to legacy code, active_sitewide_plugins stores them in the keys,
			// whereas active_plugins stores them in the values.
			$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
			if ( $network_plugins ) {
				$active_plugins = array_merge( $active_plugins, $network_plugins );
			}
		}

		sort( $active_plugins );

		return array_unique( $active_plugins );
	}

	/**
	 * Check whether or not a Jetpack module is active.
	 *
	 * @param string $module The slug of a Jetpack module.
	 * @return bool
	 *
	 * @static
	 */
	public static function is_module_active( $module ) {
		return in_array( $module, self::get_active_modules() );
	}

	public static function is_module( $module ) {
		return ! empty( $module ) && ! validate_file( $module, Jetpack_Connect::get_available_modules() );
	}

	/**
	 * Checks whether a specific plugin is active.
	 *
	 * We don't want to store these in a static variable, in case
	 * there are switch_to_blog() calls involved.
	 */
	public static function is_plugin_active( $plugin = 'jetpack/jetpack.php' ) {
		return in_array( $plugin, self::get_active_plugins() );
	}

	/**
	 * Returns the value of the jetpack_sync_idc_optin filter, or constant.
	 * If set to true, the site will be put into staging mode.
	 *
	 * @since 4.3.2
	 * @return bool
	 */
	public static function sync_idc_optin() {
		if ( Jetpack_Connect_Constants::is_defined( 'JETPACK_SYNC_IDC_OPTIN' ) ) {
			$default = Jetpack_Connect_Constants::get_constant( 'JETPACK_SYNC_IDC_OPTIN' );
		} else {
			$default = ! Jetpack_Connect_Constants::is_defined( 'SUNRISE' ) && ! is_multisite();
		}

		/**
		 * Allows sites to optin to IDC mitigation which blocks the site from syncing to WordPress.com when the home
		 * URL or site URL do not match what WordPress.com expects. The default value is either false, or the value of
		 * JETPACK_SYNC_IDC_OPTIN constant if set.
		 *
		 * @since 4.3.2
		 *
		 * @param bool $default Whether the site is opted in to IDC mitigation.
		 */
		return (bool) apply_filters( 'jetpack_sync_idc_optin', $default );
	}

	/**
	 * Checks if one or more function names is in debug_backtrace
	 *
	 * @param $names Mixed string name of function or array of string names of functions
	 *
	 * @return bool
	 */
	public static function is_function_in_backtrace( $names ) {
		$backtrace = debug_backtrace( false );
		if ( ! is_array( $names ) ) {
			$names = array( $names );
		}
		$names_as_keys = array_flip( $names );

		//Do check in constant O(1) time for PHP5.5+
		if ( function_exists( 'array_column' ) ) {
			$backtrace_functions = array_column( $backtrace, 'function' );
			$backtrace_functions_as_keys = array_flip( $backtrace_functions );
			$intersection = array_intersect_key( $backtrace_functions_as_keys, $names_as_keys );
			return ! empty ( $intersection );
		}

		//Do check in linear O(n) time for < PHP5.5 ( using isset at least prevents O(n^2) )
		foreach ( $backtrace as $call ) {
			if ( isset( $names_as_keys[ $call['function'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	public function xmlrpc_options( $options ) {
		$jetpack_client_id = false;
		if ( self::is_active() ) {
			$jetpack_client_id = Jetpack_Connect_Options::get_option( 'id' );
		}
		$options['jetpack_version'] = array(
			'desc'          => __( 'Jetpack Plugin Version', 'jetpack' ),
			'readonly'      => true,
			'value'         => JETPACK__VERSION,
		);

		$options['jetpack_client_id'] = array(
			'desc'          => __( 'The Client ID/WP.com Blog ID of this site', 'jetpack' ),
			'readonly'      => true,
			'value'         => $jetpack_client_id,
		);
		return $options;
	}

	/**
	 * In some setups, $HTTP_RAW_POST_DATA can be emptied during some IXR_Server paths since it is passed by reference to various methods.
	 * Capture it here so we can verify the signature later.
	 */
	function xmlrpc_methods( $methods ) {
		$this->HTTP_RAW_POST_DATA = $GLOBALS['HTTP_RAW_POST_DATA'];
		return $methods;
	}

	function public_xmlrpc_methods( $methods ) {
		if ( array_key_exists( 'wp.getOptions', $methods ) ) {
			$methods['wp.getOptions'] = array( $this, 'jetpack_getOptions' );
		}
		return $methods;
	}

	function jetpack_getOptions( $args ) {
		global $wp_xmlrpc_server;

		$wp_xmlrpc_server->escape( $args );

		$username	= $args[1];
		$password	= $args[2];

		if ( !$user = $wp_xmlrpc_server->login($username, $password) ) {
			return $wp_xmlrpc_server->error;
		}

		$options = array();
		$user_data = $this->get_connected_user_data();
		if ( is_array( $user_data ) ) {
			$options['jetpack_user_id'] = array(
				'desc'          => __( 'The WP.com user ID of the connected user', 'jetpack' ),
				'readonly'      => true,
				'value'         => $user_data['ID'],
			);
			$options['jetpack_user_login'] = array(
				'desc'          => __( 'The WP.com username of the connected user', 'jetpack' ),
				'readonly'      => true,
				'value'         => $user_data['login'],
			);
			$options['jetpack_user_email'] = array(
				'desc'          => __( 'The WP.com user email of the connected user', 'jetpack' ),
				'readonly'      => true,
				'value'         => $user_data['email'],
			);
			$options['jetpack_user_site_count'] = array(
				'desc'          => __( 'The number of sites of the connected WP.com user', 'jetpack' ),
				'readonly'      => true,
				'value'         => $user_data['site_count'],
			);
		}
		$wp_xmlrpc_server->blog_options = array_merge( $wp_xmlrpc_server->blog_options, $options );
		$args = stripslashes_deep( $args );
		return $wp_xmlrpc_server->wp_getOptions( $args );
	}

	// Authenticates requests from Jetpack server to WP REST API endpoints.
	// Uses the existing XMLRPC request signing implementation.
	function wp_rest_authenticate( $user ) {
		if ( ! empty( $user ) ) {
			// Another authentication method is in effect.
			return $user;
		}

		if ( ! isset( $_GET['_for'] ) || $_GET['_for'] !== 'jetpack' ) {
			// Nothing to do for this authentication method.
			return null;
		}

		if ( ! isset( $_GET['token'] ) && ! isset( $_GET['signature'] ) ) {
			// Nothing to do for this authentication method.
			return null;
		}

		// Ensure that we always have the request body available.  At this
		// point, the WP REST API code to determine the request body has not
		// run yet.  That code may try to read from 'php://input' later, but
		// this can only be done once per request in PHP versions prior to 5.6.
		// So we will go ahead and perform this read now if needed, and save
		// the request body where both the Jetpack signature verification code
		// and the WP REST API code can see it.
		if ( ! isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) ) {
			$GLOBALS['HTTP_RAW_POST_DATA'] = file_get_contents( 'php://input' );
		}
		$this->HTTP_RAW_POST_DATA = $GLOBALS['HTTP_RAW_POST_DATA'];

		// Only support specific request parameters that have been tested and
		// are known to work with signature verification.  A different method
		// can be passed to the WP REST API via the '?_method=' parameter if
		// needed.
		if ( $_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			$this->rest_authentication_status = new WP_Error(
				'rest_invalid_request',
				__( 'This request method is not supported.', 'jetpack' ),
				array( 'status' => 400 )
			);
			return null;
		}
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' && ! empty( $this->HTTP_RAW_POST_DATA ) ) {
			$this->rest_authentication_status = new WP_Error(
				'rest_invalid_request',
				__( 'This request method does not support body parameters.', 'jetpack' ),
				array( 'status' => 400 )
			);
			return null;
		}

		if ( ! empty( $_SERVER['CONTENT_TYPE'] ) ) {
			$content_type = $_SERVER['CONTENT_TYPE'];
		} elseif ( ! empty( $_SERVER['HTTP_CONTENT_TYPE'] ) ) {
			$content_type = $_SERVER['HTTP_CONTENT_TYPE'];
		}

		if (
			isset( $content_type ) &&
			$content_type !== 'application/x-www-form-urlencoded' &&
			$content_type !== 'application/json'
		) {
			$this->rest_authentication_status = new WP_Error(
				'rest_invalid_request',
				__( 'This Content-Type is not supported.', 'jetpack' ),
				array( 'status' => 400 )
			);
			return null;
		}

		$verified = $this->verify_xml_rpc_signature();

		if ( is_wp_error( $verified ) ) {
			$this->rest_authentication_status = $verified;
			return null;
		}

		if (
			$verified &&
			isset( $verified['type'] ) &&
			'user' === $verified['type'] &&
			! empty( $verified['user_id'] )
		) {
			// Authentication successful.
			$this->rest_authentication_status = true;
			return $verified['user_id'];
		}

		// Something else went wrong.  Probably a signature error.
		$this->rest_authentication_status = new WP_Error(
			'rest_invalid_signature',
			__( 'The request is not signed correctly.', 'jetpack' ),
			array( 'status' => 400 )
		);
		return null;
	}

	/**
	 * Report authentication status to the WP REST API.
	 *
	 * @param  WP_Error|mixed $result Error from another authentication handler, null if we should handle it, or another value if not
	 * @return WP_Error|boolean|null {@see WP_JSON_Server::check_authentication}
	 */
	public function wp_rest_authentication_errors( $value ) {
		if ( $value !== null ) {
			return $value;
		}
		return $this->rest_authentication_status;
	}

	/**
	 * Checks to see if the URL is using SSL to connect with Jetpack
	 *
	 * @since 2.3.3
	 * @return boolean
	 */
	public static function permit_ssl( $force_recheck = false ) {
		// Do some fancy tests to see if ssl is being supported
		if ( $force_recheck || false === ( $ssl = get_transient( 'jetpack_https_test' ) ) ) {
			$message = '';
			if ( 'https' !== substr( JETPACK__API_BASE, 0, 5 ) ) {
				$ssl = 0;
			} else {
				switch ( JETPACK_CLIENT__HTTPS ) {
					case 'NEVER':
						$ssl = 0;
						$message = __( 'JETPACK_CLIENT__HTTPS is set to NEVER', 'jetpack' );
						break;
					case 'ALWAYS':
					case 'AUTO':
					default:
						$ssl = 1;
						break;
				}

				// If it's not 'NEVER', test to see
				if ( $ssl ) {
					if ( ! wp_http_supports( array( 'ssl' => true ) ) ) {
						$ssl = 0;
						$message = __( 'WordPress reports no SSL support', 'jetpack' );
					} else {
						$response = wp_remote_get( JETPACK__API_BASE . 'test/1/' );
						if ( is_wp_error( $response ) ) {
							$ssl = 0;
							$message = __( 'WordPress reports no SSL support', 'jetpack' );
						} elseif ( 'OK' !== wp_remote_retrieve_body( $response ) ) {
							$ssl = 0;
							$message = __( 'Response was not OK: ', 'jetpack' ) . wp_remote_retrieve_body( $response );
						}
					}
				}
			}
			set_transient( 'jetpack_https_test', $ssl, DAY_IN_SECONDS );
			set_transient( 'jetpack_https_test_message', $message, DAY_IN_SECONDS );
		}

		return (bool) $ssl;
	}
}
