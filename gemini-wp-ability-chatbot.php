<?php
/**
 * Plugin Name: Gemini WP Ability Chatbot
 * Description: A custom AI chatbot using the Gemini API and the WordPress Abilities API for context-aware responses (Function Calling/Tool Use).
 * Version: 1.0.0
 * Author: AI Developer
 * Author URI: https://ai.google/gemini
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins:  abilities-api
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
	 * Assumed to be available in the future / via an Abilities API feature plugin.
	 * Registers a 'search_wp_content' ability to allow Gemini to search the site.
	 */
	public function register_wp_ability() {
		// IMPORTANT: This class 'Abilities\API' is conceptual, based on the WP Abilities API documentation.
		// It will only work if the Abilities API is installed/included in your environment.
		// if ( ! class_exists( 'Abilities\API' ) ) {
		// 	add_action( 'admin_notices', function() {
		// 		echo '<div class="notice notice-error"><p><strong>Gemini WP Ability Chatbot Error:</strong> The Abilities API is not active. The chatbot will only use its base knowledge.</p></div>';
		// 	} );
		// 	return;
		// }

		$search_callback = [ $this, 'execute_wp_search_ability' ];

		// Register the ability: 'search_wp_content'
		// This is the "WP ability" exposed to the AI model.
			wp_register_ability( 'search_wp_content', [
				'id'                => 'plugin/search_wp_content',
				'name'              => 'search_wp_content',
				'description'       => 'Retrieves relevant content snippets from the WordPress site\'s posts and pages based on a search query. Use this only when the user asks a question specifically about the site\'s content, like "what are your recent articles" or "do you have a post about [topic]".',
				'permission_callback' => '__return_true', // Publicly accessible ability
				'execution_callback' => $search_callback,
				'schema'            => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => 'The specific search term to use for querying the WordPress content database.',
						],
					],
					'required'   => [ 'query' ],
				],
			] );
		
	}

	/**
	 * The actual PHP function that executes the WordPress search.
	 * This is the 'execution_callback' for the 'search_wp_content' ability.
	 *
	 * @param array $args The arguments provided by the AI model's function call.
	 * @return string The search result formatted as a string for the AI to use.
	 */
	public function execute_wp_search_ability( $args ) {
		$search_query = isset( $args['query'] ) ? sanitize_text_field( $args['query'] ) : '';

		if ( empty( $search_query ) ) {
			return 'Error: No search query provided for WordPress content search.';
		}

		// WP_Query to search the site content
		$search_results = new WP_Query( [
			's'              => $search_query,
			'post_type'      => [ 'post', 'page' ],
			'posts_per_page' => 3, // Limit to top 3 relevant results
			'post_status'    => 'publish',
		] );

		$context = '';

		if ( $search_results->have_posts() ) {
			$context .= "WordPress Content Snippets for query '{$search_query}':\n\n";
			while ( $search_results->have_posts() ) {
				$search_results->the_post();
				// Use strip_tags to get clean text for the AI.
				$snippet = substr( strip_tags( get_the_content() ), 0, 300 ) . '...';
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
		// Assuming we can call register_wp_ability early on.
		add_action( 'plugins_loaded', [ $this, 'register_wp_ability' ] );
		
		// Enqueue chat assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Shortcode to display the chat interface
		add_shortcode( 'gemini_wp_chatbot', [ $this, 'render_chatbot_shortcode' ] );

		// AJAX handler for the chat interaction
		add_action( 'wp_ajax_gemini_chat', [ $this, 'handle_chat_request' ] );
		add_action( 'wp_ajax_nopriv_gemini_chat', [ $this, 'handle_chat_request' ] );
	}

	/**
	 * Enqueue styles and scripts for the chatbot.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'gemini-wp-chat-style', plugin_dir_url( __FILE__ ) . 'style.css', array(), GEMINI_WP_CHAT_VERSION );
		wp_enqueue_script( 'gemini-wp-chat-script', plugin_dir_url( __FILE__ ) . 'script.js', array( 'jquery' ), GEMINI_WP_CHAT_VERSION, true );
		
		// Pass necessary data to the JavaScript file
		wp_localize_script( 'gemini-wp-chat-script', 'GeminiChatData', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( GEMINI_WP_CHAT_NONCE ),
		] );
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
			wp_send_json_error( [ 'message' => 'Gemini API Key is missing.' ] );
		}

		$response = $this->call_gemini_api( $user_prompt, $api_key );
		
		// If Gemini requests a function call, a multi-turn process is initiated.
		if ( isset( $response['function_call'] ) ) {
			$function_call = $response['function_call'];
			$tool_result = '';

			// Step 1: Execute the requested WP Ability
			if ( $function_call['name'] === 'search_wp_content' ) {
				$tool_result = $this->execute_wp_search_ability( $function_call['args'] );
			}

			// Step 2: Send the tool result back to Gemini for the final answer
			$final_response = $this->call_gemini_api_with_tool_result(
				$user_prompt,
				$api_key,
				$function_call,
				$tool_result
			);

			wp_send_json_success( [ 'message' => $final_response ] );

		} elseif ( isset( $response['text'] ) ) {
			// Direct text response
			wp_send_json_success( [ 'message' => $response['text'] ] );
		} else {
			wp_send_json_error( [ 'message' => 'An error occurred while communicating with the AI model.' ] );
		}
	}

	/**
	 * Call the Gemini API, including the Abilities/Tools.
	 */
	private function call_gemini_api( $prompt, $api_key ) {
		// Define the tool declaration based on the Abilities API registration
		$tool_declaration = [
			'function_declarations' => [
				[
					'name'        => 'search_wp_content',
					'description' => 'Retrieves relevant content snippets from the WordPress site\'s posts and pages based on a search query. Use this only when the user asks a question specifically about the site\'s content.',
					'parameters'  => [
						'type'       => 'object',
						'properties' => [
							'query' => [
								'type'        => 'string',
								'description' => 'The specific search term to use for querying the WordPress content database.',
							],
						],
						'required'   => [ 'query' ],
					],
				],
			],
		];

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
		
		$body = [
			'contents' => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ]
			],
			'tools' => [ $tool_declaration ],
		];

		$response = wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => json_encode( $body ),
			'timeout' => 45, // Set a higher timeout for API calls
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'text' => 'API Error: ' . $response->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Check for a function call request from the model
		if ( isset( $body['candidates'][0]['content']['parts'][0]['functionCall'] ) ) {
			$function_call = $body['candidates'][0]['content']['parts'][0]['functionCall'];
			return [ 
				'function_call' => [
					'name' => $function_call['name'],
					'args' => $function_call['args'],
				]
			];
		}
		
		// Return direct text response
		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I couldn\'t generate a response.';
		return [ 'text' => $text ];
	}

	/**
	 * Second API call to Gemini, sending the search result (tool output) back as context.
	 */
	private function call_gemini_api_with_tool_result( $user_prompt, $api_key, $function_call, $tool_result ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
		
		$body = [
			'contents' => [
				// Turn 1: User Prompt
				[ 'role' => 'user', 'parts' => [ [ 'text' => $user_prompt ] ] ],
				
				// Turn 2: Model requested a tool execution
				[ 
					'role' => 'model', 
					'parts' => [
						[
							'functionCall' => [
								'name' => $function_call['name'],
								'args' => $function_call['args'],
							]
						]
					] 
				],
				
				// Turn 3: Tool response (the content retrieved from the WP database)
				[ 
					'role' => 'tool', 
					'parts' => [
						[
							'functionResponse' => [
								'name' => $function_call['name'],
								'response' => [ 'result' => $tool_result ],
							]
						]
					]
				],
			],
		];

		$response = wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => json_encode( $body ),
			'timeout' => 45,
		] );

		if ( is_wp_error( $response ) ) {
			return 'API Error (Turn 2): ' . $response->get_error_message();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, the final response could not be generated.';
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