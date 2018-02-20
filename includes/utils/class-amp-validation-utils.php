<?php
/**
 * Class AMP_Validation_Utils
 *
 * @package AMP
 */

/**
 * Class AMP_Validation_Utils
 *
 * @since 0.7
 */
class AMP_Validation_Utils {

	/**
	 * Key for the markup value in the REST API endpoint.
	 *
	 * @var string
	 */
	const MARKUP_KEY = 'markup';

	/**
	 * Key for the error value in the response.
	 *
	 * @var string
	 */
	const ERROR_KEY = 'has_error';

	/**
	 * Class of the style sanitizer.
	 *
	 * @var string
	 */
	const STYLE_SANITIZER = 'AMP_Style_Sanitizer';

	/**
	 * The nodes that the sanitizer removed.
	 *
	 * @var DOMNode[]
	 */
	public static $removed_nodes = array();

	/**
	 * The plugins that have had nodes removed.
	 *
	 * @var array
	 */
	public static $plugins_removed_nodes = array();

	/**
	 * Add the actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'amp_rest_validation' ) );
		add_action( 'edit_form_top', array( __CLASS__, 'validate_content' ), 10, 2 );
	}

	/**
	 * Tracks when a sanitizer removes a node (element or attribute).
	 *
	 * @param DOMNode     $node   The node which was removed.
	 * @param string|null $source The source of the removed element.
	 * @return void
	 */
	public static function track_removed( $node, $source = null ) {
		self::$removed_nodes[] = $node;
		if ( isset( $source ) && ! in_array( $source, self::$plugins_removed_nodes, true ) ) {
			self::$plugins_removed_nodes[] = $source;
		}
	}

	/**
	 * Gets the source of the asset.
	 *
	 * This attempts to find if it's from a theme or a plugin.
	 * In that case, it also returns the name of the theme or plugin.
	 *
	 * @param string $asset The asset path to search for the source.
	 * @return array $source The asset type and source.
	 */
	public static function get_source( $asset ) {
		preg_match( ':wp-content/(themes|plugins|)/(.+?)/:s', $asset, $matches );
		if ( isset( $matches[1], $matches[2] ) ) {
			return array(
				$matches[1],
				$matches[2],
			);
		} elseif ( false !== strpos( $asset, 'mu-plugins' ) ) {
			return array(
				'mu-plugins',
				'',
			);
		} elseif ( ( false !== strpos( $asset, get_home_url() ) ) || ( function_exists( 'get_home_path' ) && ( false !== strpos( $asset, get_home_path() ) ) ) ) {
			return array(
				'core',
				'',
			);
		} else {
			return array(
				'external',
				'',
			);
		}
	}

	/**
	 * Gets whether a node was removed in a sanitizer.
	 *
	 * @return boolean.
	 */
	public static function was_node_removed() {
		return ! empty( self::$removed_nodes );
	}

	/**
	 * Processes markup, to determine AMP validity.
	 *
	 * Passes $markup through the AMP sanitizers.
	 * Also passes a 'remove_invalid_callback' to keep track of stripped attributes and nodes.
	 *
	 * @param string $markup The markup to process.
	 * @return void
	 */
	public static function process_markup( $markup ) {
		if ( ! self::has_cap() ) {
			return;
		}

		AMP_Theme_Support::register_content_embed_handlers();
		add_filter( 'amp_content_sanitizers', array( __CLASS__, 'style_callback' ), 10, 2 );
		remove_filter( 'the_content', 'wpautop' );

		/** This filter is documented in wp-includes/post-template.php */
		$markup = apply_filters( 'the_content', $markup );
		$args   = array(
			'content_max_width'       => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH,
			'remove_invalid_callback' => 'AMP_Validation_Utils::track_removed',
		);
		AMP_Content_Sanitizer::sanitize( $markup, amp_get_content_sanitizers(), $args );
	}

	/**
	 * Registers the REST API endpoint for validation.
	 *
	 * @return void
	 */
	public static function amp_rest_validation() {
		register_rest_route( 'amp-wp/v1', '/validate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'validate_markup' ),
			'args'                => array(
				self::MARKUP_KEY => array(
					'validate_callback' => array( __CLASS__, 'validate_arg' ),
				),
			),
			'permission_callback' => array( __CLASS__, 'has_cap' ),
		) );
	}

	/**
	 * Whether the user has the required capability.
	 *
	 * Checks for permissions before validating.
	 * Also serves as the permission callback for REST requests.
	 *
	 * @return boolean $has_cap Whether the current user has the capability.
	 */
	public static function has_cap() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Validate the markup passed to the REST API.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return array|WP_Error.
	 */
	public static function validate_markup( WP_REST_Request $request ) {
		$json = $request->get_json_params();
		if ( empty( $json[ self::MARKUP_KEY ] ) ) {
			return new WP_Error( 'no_markup', 'No markup passed to validator', array(
				'status' => 404,
			) );
		}

		return self::get_response( $json[ self::MARKUP_KEY ] );
	}

	/**
	 * Gets the AMP validation response.
	 *
	 * If $markup isn't passed,
	 * It will return the validation errors the sanitizers found in rendering the page.
	 *
	 * @param string $markup   To validate for AMP compatibility (optional).
	 * @return array $response The AMP validity of the markup.
	 */
	public static function get_response( $markup = null ) {
		$response = array();
		if ( isset( $markup ) ) {
			self::process_markup( $markup );
			$response['processed_markup'] = $markup;
		}

		$removed_elements   = array();
		$removed_attributes = array();
		foreach ( self::$removed_nodes as $node ) {
			if ( $node instanceof DOMAttr ) {
				if ( ! isset( $removed_attributes[ $node->nodeName ] ) ) {
					$removed_attributes[ $node->nodeName ] = 1;
				} else {
					$removed_attributes[ $node->nodeName ]++;
				}
			} elseif ( $node instanceof DOMElement ) {
				if ( ! isset( $removed_elements[ $node->nodeName ] ) ) {
					$removed_elements[ $node->nodeName ] = 1;
				} else {
					$removed_elements[ $node->nodeName ]++;
				}
			}
		}

		$response = array_merge(
			array(
				self::ERROR_KEY => self::was_node_removed(),
			),
			compact(
				'removed_elements',
				'removed_attributes'
			),
		$response );
		self::reset_removed();

		return $response;
	}

	/**
	 * Reset the stored removed nodes and attributes.
	 *
	 * After testing if the markup is valid,
	 * these static values will remain.
	 * So reset them in case another test is needed.
	 *
	 * @return void
	 */
	public static function reset_removed() {
		self::$removed_nodes         = array();
		self::$plugins_removed_nodes = array();
	}

	/**
	 * Validate the argument in the REST API request.
	 *
	 * It would be ideal to simply pass 'is_string' in register_rest_route().
	 * But it always returned false.
	 *
	 * @param mixed $arg      The argument to validate.
	 * @return boolean $is_valid Whether the argument is valid.
	 */
	public static function validate_arg( $arg ) {
		return is_string( $arg );
	}

	/**
	 * Checks the AMP validity of the post content.
	 *
	 * If it's not valid AMP,
	 * it displays an error message above the 'Classic' editor.
	 *
	 * @param WP_Post $post The updated post.
	 * @return void
	 */
	public static function validate_content( $post ) {
		if ( ! post_supports_amp( $post ) || ! self::has_cap() ) {
			return;
		}
		AMP_Theme_Support::register_content_embed_handlers();
		/** This filter is documented in wp-includes/post-template.php */
		$filtered_content = apply_filters( 'the_content', $post->post_content, $post->ID );
		$response         = self::get_response( $filtered_content );
		if ( isset( $response[ self::ERROR_KEY ] ) && ( true === $response[ self::ERROR_KEY ] ) ) {
			self::display_error( $response );
		}
	}

	/**
	 * Wraps callbacks in comments, to indicate to the sanitizer which plugin added them.
	 *
	 * Iterates through all of the registered callbacks.
	 * If a callback is from a plugin and outputs markup,
	 * this wraps the markup in comments.
	 * Later, the sanitizer can identify which plugin any illegal markup is from.
	 *
	 * @global array $wp_filter
	 * @return void
	 */
	public static function callback_wrappers() {
		global $wp_filter;
		foreach ( $wp_filter as $filter_tag => $wp_hook ) {
			if ( 'query' === $filter_tag ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$plugin   = self::get_plugin( $function );
					if ( isset( $plugin ) ) {
						remove_action( $filter_tag, $function, $priority );
						$wrapped_callback = self::wrapped_callback( $function, $plugin );
						add_action( $filter_tag, $wrapped_callback, $priority, $callback['accepted_args'] );
					}
				}
			}
		}
	}

	/**
	 * Gets the plugin of the callback, if one exists.
	 *
	 * @param string|array $callback The callback for which to get the plugin.
	 * @return string|null $plugin   The plugin to which the callback belongs, or null.
	 */
	public static function get_plugin( $callback ) {
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			// The $callback is a function or static method.
			$exploded_callback = explode( '::', $callback );
			if ( count( $exploded_callback ) > 1 ) {
				$reflection = new ReflectionClass( $exploded_callback[0] );
			} else {
				$reflection = new ReflectionFunction( $callback );
			}
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], $callback[1] ) ) {
			// The $callback is a method.
			$reflection = new ReflectionClass( $callback[0] );
		} elseif ( is_object( $callback ) && ( 'Closure' === get_class( $callback ) ) ) {
			$reflection = new ReflectionFunction( $callback );
		}

		$file = isset( $reflection ) ? $reflection->getFileName() : null;
		if ( ! isset( $file ) ) {
			return null;
		}
		list( $source_type, $source ) = self::get_source( $file );
		if ( 'plugins' === $source_type ) {
			return $source;
		}
		return null;
	}

	/**
	 * Wraps a callback in comments if it outputs markup.
	 *
	 * If the sanitizer removes markup,
	 * this indicates which plugin it was from.
	 *
	 * @param string|array $callback The callback to wrap.
	 * @param string       $plugin   The plugin where the callback is located.
	 * @return closure $wrapped_callback The callback, wrapped in comments.
	 */
	public static function wrapped_callback( $callback, $plugin ) {
		return function() use ( $callback, $plugin ) {
			$args = func_get_args();
			ob_start();
			if ( false !== $args ) {
				$result = call_user_func_array( $callback, func_get_args() );
			} else {
				$result = call_user_func( $callback );
			}
			$output = ob_get_clean();

			if ( ! empty( $output ) ) {
				printf( '<!--before:%s-->', esc_attr( $plugin ) );
				echo $output; // WPCS: XSS ok.
				printf( '<!--after:%s-->', esc_attr( $plugin ) );
			}
			return $result;
		};
	}

	/**
	 * Displays an error message on /wp-admin/post.php.
	 *
	 * Located at the top of the 'Classic' editor.
	 * States that the content is not valid AMP.
	 *
	 * @param array $response The validation response, an associative array.
	 * @return void
	 */
	public static function display_error( $response ) {
		echo '<div class="notice notice-warning">';
		printf( '<p>%s</p>', esc_html__( 'Warning: There is content which fails AMP validation; it will be stripped when served as AMP.', 'amp' ) );
		$removed_sets = array();
		if ( ! empty( $response['removed_elements'] ) && is_array( $response['removed_elements'] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid elements:', 'amp' ),
				'names' => array_map( 'sanitize_key', $response['removed_elements'] ),
			);
		}
		if ( ! empty( $response['removed_attributes'] ) && is_array( $response['removed_attributes'] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid attributes:', 'amp' ),
				'names' => array_map( 'sanitize_key', $response['removed_attributes'] ),
			);
		}
		foreach ( $removed_sets as $removed_set ) {
			printf( '<p>%s ', esc_html( $removed_set['label'] ) );
			$items = array();
			foreach ( $removed_set['names'] as $name => $count ) {
				if ( 1 === intval( $count ) ) {
					$items[] = sprintf( '<code>%s</code>', esc_html( $name ) );
				} else {
					$items[] = sprintf( '<code>%s</code> (%d)', esc_html( $name ), $count );
				}
			}
			echo implode( ', ', $items ); // WPCS: XSS OK.
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Adds a callback to the style sanitizer, to track stylesheet removal.
	 *
	 * @param array $sanitizers The content sanitizers.
	 * @return array $sanitizers The filtered content sanitizers.
	 */
	public static function style_callback( $sanitizers ) {
		if ( self::has_cap() && isset( $sanitizers[ self::STYLE_SANITIZER ] ) && is_array( $sanitizers[ self::STYLE_SANITIZER ] ) ) {
			$sanitizers[ self::STYLE_SANITIZER ]['remove_style_callback'] = __CLASS__ . '::track_style';
		}
		return $sanitizers;
	}

}