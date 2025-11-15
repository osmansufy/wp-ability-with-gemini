<?php
/**
 * Plugin Name: Gemini WP Ability Chatbot
 * Description: A custom AI chatbot using the Gemini API and the WordPress Abilities API for context-aware responses (Function Calling/Tool Use).
 * Version: 1.0.0
 * Author: AI Developer
 * Author URI: https://ai.google/gemini
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: abilities-api
 */

defined( 'ABSPATH' ) || exit;

// --- 1. CONFIGURATION AND INITIALIZATION ---

define( 'GEMINI_WP_CHAT_VERSION', '1.0.0' );
define( 'GEMINI_WP_CHAT_NONCE', 'gemini_wp_chat_nonce' );

/**
 * Class to handle Gemini API communication and Abilities integration.
 */
class Gemini_WP_Chatbot {

	/**
	 * Store registered abilities for use with Gemini.
	 * This creates a single source of truth for both WordPress and Gemini.
	 */
	private $registered_abilities = [];

	/**
	 * Register ability categories.
	 * Categories must be registered BEFORE abilities.
	 * Hook: wp_abilities_api_categories_init
	 */
	public function register_ability_categories() {
		wp_register_ability_category( 'content-retrieval', array(
			'label'       => __( 'Content Retrieval', 'gemini-wp-chat' ),
			'description' => __( 'Abilities that retrieve and return content from the WordPress site.', 'gemini-wp-chat' ),
		) );
	}

	/**
	 * Register WordPress abilities following official Abilities API documentation.
	 * Hook: wp_abilities_api_init
	 * Reference: https://github.com/WordPress/abilities-api/blob/trunk/docs/getting-started.md
	 */
	public function register_wp_abilities() {

		// Define the ability configuration following official API schema
		$ability_config = array(
			'label'               => __( 'Search WordPress Content', 'gemini-wp-chat' ),
			'description'         => __( 'Retrieves relevant content snippets from the WordPress site\'s posts and pages based on a search query. Use this when the user asks about site-specific content, like "what are your recent articles" or "do you have a post about [topic]".', 'gemini-wp-chat' ),
			'category'            => 'content-retrieval', // Must reference a registered category
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'The search term to use for querying the WordPress content database.',
					),
				),
				'required'   => array( 'query' ),
			),
			'output_schema'       => array(
				'type'        => 'string',
				'description' => 'Formatted string containing search results with titles, URLs, and content snippets.',
			),
			'execute_callback'    => array( $this, 'execute_wp_search_ability' ),
			'permission_callback' => '__return_true', // Everyone can search
			'meta'                => array(
				'show_in_rest' => true, // Expose via REST API if needed
			),
		);

		// Register the ability with WordPress (if function exists)
		if ( function_exists( 'wp_register_ability' ) ) {
			$ability = wp_register_ability( 'gemini-wp-chat/search-content', $ability_config );
		} else {
			$ability = true; // Allow fallback mode
		}

		// Store locally for Gemini tool declarations
		// This ensures WordPress registry and Gemini tools stay in sync
		// Always store locally even if Abilities API isn't available (for fallback mode)
		$this->registered_abilities['gemini-wp-chat/search-content'] = array_merge(
			array( 'name' => 'gemini-wp-chat/search-content' ),
			$ability_config
		);

		/**
		 * ADDING MORE ABILITIES:
		 * 
		 * You can easily add more abilities here. Each one you add will automatically
		 * be available to Gemini via get_tool_declarations_from_abilities().
		 * 
		 * Example - Add a "get_recent_posts" ability:
		 * 
		 * $recent_posts_config = array(
		 *     'label'               => __( 'Get Recent Posts', 'gemini-wp-chat' ),
		 *     'description'         => __( 'Gets the most recent blog posts from the WordPress site.', 'gemini-wp-chat' ),
		 *     'category'            => 'content-retrieval',
		 *     'input_schema'        => array(
		 *         'type'       => 'object',
		 *         'properties' => array(
		 *             'limit' => array(
		 *                 'type'        => 'number',
		 *                 'description' => 'Number of posts to retrieve (default 5)',
		 *             ),
		 *         ),
		 *         'required'   => array(),
		 *     ),
		 *     'output_schema'       => array(
		 *         'type'        => 'string',
		 *         'description' => 'Formatted list of recent posts with titles and URLs.',
		 *     ),
		 *     'execute_callback'    => array( $this, 'execute_get_recent_posts' ),
		 *     'permission_callback' => '__return_true',
		 * );
		 * 
		 * $ability = wp_register_ability( 'gemini-wp-chat/get-recent-posts', $recent_posts_config );
		 * if ( $ability ) {
		 *     $this->registered_abilities['gemini-wp-chat/get-recent-posts'] = array_merge(
		 *         array( 'name' => 'gemini-wp-chat/get-recent-posts' ),
		 *         $recent_posts_config
		 *     );
		 * }
		 * 
		 * Then implement the execution callback:
		 * 
		 * public function execute_get_recent_posts( $input ) {
		 *     $limit = isset( $input['limit'] ) ? intval( $input['limit'] ) : 5;
		 *     $posts = get_posts( array( 'numberposts' => $limit ) );
		 *     // Format and return results...
		 * }
		 */
	}

	/**
	 * The actual PHP function that executes the WordPress search.
	 * This is the 'execute_callback' for the 'search_wp_content' ability.
	 *
	 * @param array $input The input parameters from the ability call (contains 'query' key).
	 * @return string The search result formatted as a string for the AI to use.
	 */
	public function execute_wp_search_ability( $input ) {
		$search_query = isset( $input['query'] ) ? sanitize_text_field( $input['query'] ) : '';

		if ( empty( $search_query ) ) {
			return 'Error: No search query provided for WordPress content search.';
		}

		// WP_Query to search the site content
		$search_results = new WP_Query( array(
			's'              => $search_query,
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => 3, // Limit to top 3 relevant results
			'post_status'    => 'publish',
		) );

		$context = '';

		if ( $search_results->have_posts() ) {
			$context .= "WordPress Content Snippets for query '{$search_query}':\n\n";
			while ( $search_results->have_posts() ) {
				$search_results->the_post();
				// Use wp_strip_all_tags to get clean text for the AI.
				$snippet = substr( wp_strip_all_tags( get_the_content() ), 0, 300 ) . '...';
				$context .= "TITLE: " . get_the_title() . "\n";
				$context .= "URL: " . get_permalink() . "\n";
				$context .= "SNIPPET: {$snippet}\n---\n";
			}
			wp_reset_postdata();
		} else {
			$context = "No relevant content found on the WordPress site for the query '{$search_query}'.";
		}

		return $context;
	}

	/**
	 * Constructor: Hooks into WordPress actions.
	 */
	public function __construct() {
		// Check if Abilities API is available (official check from documentation)
		if ( ! class_exists( 'WP_Ability' ) ) {
			add_action( 'admin_notices', function() {
				wp_admin_notice(
					__( 'Gemini WP Ability Chatbot: The Abilities API plugin is not installed. The chatbot will work but abilities won\'t be registered with WordPress. Install the Abilities API plugin for full functionality.', 'gemini-wp-chat' ),
					array( 'type' => 'warning' )
				);
			} );
		}

		// Register categories FIRST (must happen before abilities)
		// Only register if Abilities API is available
		if ( function_exists( 'wp_register_ability_category' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_categories' ) );
		}
		
		// Register abilities on the correct hook (if Abilities API is available)
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register_wp_abilities' ) );
		} else {
			// Fallback: Register abilities directly if Abilities API is not available
			// This ensures abilities are still available to Gemini even without the API plugin
			add_action( 'plugins_loaded', array( $this, 'register_wp_abilities' ), 20 );
		}

		// Enqueue chat assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Shortcode to display the chat interface
		add_shortcode( 'gemini_wp_chatbot', array( $this, 'render_chatbot_shortcode' ) );

		// AJAX handler for the chat interaction
		add_action( 'wp_ajax_gemini_chat', array( $this, 'handle_chat_request' ) );
		add_action( 'wp_ajax_nopriv_gemini_chat', array( $this, 'handle_chat_request' ) );
	}

	/**
	 * Enqueue styles and scripts for the chatbot.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'gemini-wp-chat-style', plugin_dir_url( __FILE__ ) . 'style.css', array(), GEMINI_WP_CHAT_VERSION );
		wp_enqueue_script( 'gemini-wp-chat-script', plugin_dir_url( __FILE__ ) . 'script.js', array( 'jquery' ), GEMINI_WP_CHAT_VERSION, true );
		
		// Pass necessary data to the JavaScript file
		wp_localize_script( 'gemini-wp-chat-script', 'GeminiChatData', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( GEMINI_WP_CHAT_NONCE ),
		) );
	}

	/**
	 * Renders the HTML structure for the chatbot.
	 */
	public function render_chatbot_shortcode() {
		if ( ! get_option( 'gemini_api_key' ) ) {
			return '<p style="color: red;">Chatbot is not configured. Please enter your Gemini API Key in the settings.</p>';
		}
		ob_start();
		?>
<div id="gemini-wp-chatbot-container">
    <div id="chat-messages">
        <div class="message bot-message">Hello! I'm your AI assistant. I can answer general questions and also search
            the site's content for you. How can I help?</div>
    </div>
    <form id="chat-form">
        <input type="text" id="user-input" placeholder="Ask me a question..." required>
        <button type="submit" id="send-btn">Send</button>
    </form>
</div>
<?php
		return ob_get_clean();
	}
	
	/**
	 * --- 2. THE CORE AI/CHAT HANDLER ---
	 * Handles the AJAX request from the frontend chat.
	 */
	public function handle_chat_request() {
		check_ajax_referer( GEMINI_WP_CHAT_NONCE, 'nonce' );

		$user_prompt = sanitize_text_field( $_POST['prompt'] );
		$api_key = get_option( 'gemini_api_key' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Gemini API Key is missing.' ) );
		}

		$response = $this->call_gemini_api( $user_prompt, $api_key );
		
		// If Gemini requests a function call, a multi-turn process is initiated.
		if ( isset( $response['function_call'] ) ) {
			$function_call = $response['function_call'];
			$tool_result = '';

			// Step 1: Execute the requested WP Ability
			// Convert function name back (Gemini uses underscores, we use slashes)
			$ability_name = str_replace( '_', '/', $function_call['name'] );
			
			// Use the WordPress Abilities API to execute (if available)
			if ( class_exists( 'WP_Ability' ) ) {
				$ability = wp_get_ability( $ability_name );
				if ( $ability ) {
					$result = $ability->execute( $function_call['args'] );
					$tool_result = is_wp_error( $result ) ? $result->get_error_message() : $result;
				}
			} else {
				// Fallback: execute manually if Abilities API not available
				if ( strpos( $ability_name, 'search-content' ) !== false ) {
					$tool_result = $this->execute_wp_search_ability( $function_call['args'] );
				}
			}

			// Step 2: Send the tool result back to Gemini for the final answer
			$final_response = $this->call_gemini_api_with_tool_result(
				$user_prompt,
				$api_key,
				$function_call,
				$tool_result
			);

			wp_send_json_success( array( 'message' => $final_response ) );

		} elseif ( isset( $response['text'] ) ) {
			// Direct text response
			wp_send_json_success( array( 'message' => $response['text'] ) );
		} else {
			wp_send_json_error( array( 'message' => 'An error occurred while communicating with the AI model.' ) );
		}
	}

	/**
	 * Converts registered WordPress abilities to Gemini tool declaration format.
	 * This is the bridge between WordPress Abilities API and Gemini Function Calling.
	 *
	 * @return array Tool declarations in Gemini API format.
	 */
	private function get_tool_declarations_from_abilities() {
		$function_declarations = array();

		// Convert each registered ability to Gemini tool format
		foreach ( $this->registered_abilities as $name => $ability ) {
			$function_declarations[] = array(
				'name'        => str_replace( '/', '_', $ability['name'] ), // Gemini doesn't like slashes in function names
				'description' => $ability['description'],
				'parameters'  => $ability['input_schema'], // Uses input_schema from wp_register_ability!
			);
		}

		return array(
			'function_declarations' => $function_declarations,
		);
	}

	/**
	 * Call the Gemini API, including the Abilities/Tools.
	 * Tools are automatically generated from registered WordPress abilities.
	 */
	private function call_gemini_api( $prompt, $api_key ) {
		// Get tool declarations from registered abilities (no duplication!)
		$tool_declaration = $this->get_tool_declarations_from_abilities();

		// Only include tools if we have any registered abilities
		$request_body = array(
			'contents' => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) )
			),
		);

		// Add tools only if we have function declarations
		if ( ! empty( $tool_declaration['function_declarations'] ) ) {
			$request_body['tools'] = array( $tool_declaration );
		}

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . $api_key;
		
		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $request_body ),
			'timeout' => 45, // Set a higher timeout for API calls
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'text' => 'API Error: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$body = json_decode( $response_body, true );

		// Check for HTTP errors
		if ( $response_code !== 200 ) {
			$error_message = 'API returned error code: ' . $response_code;
			if ( isset( $body['error']['message'] ) ) {
				$error_message .= ' - ' . $body['error']['message'];
			}
			return array( 'text' => $error_message );
		}

		// Check if response body is valid
		if ( ! is_array( $body ) || ! isset( $body['candidates'] ) ) {
			// Log the actual response for debugging
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Gemini API Response Error: ' . print_r( $body, true ) );
			}
			return array( 'text' => 'Invalid API response format. Please check your API key and try again.' );
		}

		// Check if candidates array is empty
		if ( empty( $body['candidates'] ) ) {
			$error_message = 'No candidates returned from API.';
			if ( isset( $body['promptFeedback']['blockReason'] ) ) {
				$error_message .= ' Blocked: ' . $body['promptFeedback']['blockReason'];
			}
			return array( 'text' => $error_message );
		}

		// Check for a function call request from the model
		if ( isset( $body['candidates'][0]['content']['parts'][0]['functionCall'] ) ) {
			$function_call = $body['candidates'][0]['content']['parts'][0]['functionCall'];
			return array( 
				'function_call' => array(
					'name' => $function_call['name'],
					'args' => $function_call['args'] ?? array(),
				)
			);
		}
		
		// Return direct text response
		if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text = $body['candidates'][0]['content']['parts'][0]['text'];
			return array( 'text' => $text );
		}

		// If we get here, something unexpected happened
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Gemini API Unexpected Response: ' . print_r( $body, true ) );
		}
		
		return array( 'text' => 'Sorry, I couldn\'t generate a response. Please check your API key and try again.' );
	}

	/**
	 * Second API call to Gemini, sending the search result (tool output) back as context.
	 */
	private function call_gemini_api_with_tool_result( $user_prompt, $api_key, $function_call, $tool_result ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . $api_key;
		
		$body = array(
			'contents' => array(
				// Turn 1: User Prompt
				array( 'role' => 'user', 'parts' => array( array( 'text' => $user_prompt ) ) ),
				
				// Turn 2: Model requested a tool execution
				array( 
					'role' => 'model', 
					'parts' => array(
						array(
							'functionCall' => array(
								'name' => $function_call['name'],
								'args' => $function_call['args'],
							)
						)
					) 
				),
				
				// Turn 3: Tool response (the content retrieved from the WP database)
				array( 
					'role' => 'function', 
					'parts' => array(
						array(
							'functionResponse' => array(
								'name' => $function_call['name'],
								'response' => array( 'result' => $tool_result ),
							)
						)
					)
				),
			),
		);

		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $body ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return 'API Error (Turn 2): ' . $response->get_error_message();
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		// Check for HTTP errors
		if ( $response_code !== 200 ) {
			$error_message = 'API returned error code: ' . $response_code;
			if ( isset( $response_data['error']['message'] ) ) {
				$error_message .= ' - ' . $response_data['error']['message'];
			}
			return $error_message;
		}

		// Check if response is valid
		if ( ! is_array( $response_data ) || ! isset( $response_data['candidates'] ) || empty( $response_data['candidates'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Gemini API Response Error (Turn 2): ' . print_r( $response_data, true ) );
			}
			return 'Sorry, the final response could not be generated. Please try again.';
		}

		if ( isset( $response_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return $response_data['candidates'][0]['content']['parts'][0]['text'];
		}

		return 'Sorry, the final response could not be generated.';
	}
}

// Instantiate the main plugin class
new Gemini_WP_Chatbot();

// --- 3. ADMIN SETTINGS FOR API KEY (REQUIRED) ---

/**
 * Add an admin settings page for the API Key.
 */
function gemini_wp_chat_settings_page() {
	add_options_page(
		'Gemini Chatbot Settings',
		'Gemini Chatbot',
		'manage_options',
		'gemini-wp-chat-settings',
		'gemini_wp_chat_settings_content'
	);
}
add_action( 'admin_menu', 'gemini_wp_chat_settings_page' );

/**
 * Render the settings page content.
 */
function gemini_wp_chat_settings_content() {
	?>
<div class="wrap">
    <h1>Gemini WP Ability Chatbot Settings</h1>
    <form method="post" action="options.php">
        <?php
			settings_fields( 'gemini_wp_chat_options_group' );
			do_settings_sections( 'gemini-wp-chat-settings' );
			submit_button();
			?>
    </form>
</div>
<?php
}

/**
 * Register settings.
 */
function gemini_wp_chat_register_settings() {
	register_setting( 'gemini_wp_chat_options_group', 'gemini_api_key' );

	add_settings_section(
		'gemini_wp_chat_main_section',
		'Gemini API Configuration',
		'gemini_wp_chat_section_callback',
		'gemini-wp-chat-settings'
	);

	add_settings_field(
		'gemini_api_key_field',
		'Gemini API Key',
		'gemini_wp_chat_api_key_callback',
		'gemini-wp-chat-settings',
		'gemini_wp_chat_main_section'
	);
}
add_action( 'admin_init', 'gemini_wp_chat_register_settings' );

function gemini_wp_chat_section_callback() {
	echo '<p>Enter your Google Gemini API Key. You can get one from Google AI Studio.</p>';
}

function gemini_wp_chat_api_key_callback() {
	$api_key = get_option( 'gemini_api_key' );
	echo '<input type="password" name="gemini_api_key" value="' . esc_attr( $api_key ) . '" style="width: 400px;" />';
}