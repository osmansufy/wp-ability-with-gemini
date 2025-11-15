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
 * Class to handle Gemini API communication and WordPress Abilities integration.
 *
 * This class implements a dynamic integration between WordPress Abilities API and
 * Google's Gemini AI, enabling the chatbot to access WordPress content through
 * registered abilities. The implementation follows these key principles:
 *
 * 1. **Dynamic Ability Registration**: Registers multiple content abilities on the
 *    'wp_abilities_api_init' hook with proper namespacing (gemini-chatbot/*).
 *
 * 2. **Automatic Tool Generation**: Dynamically converts registered WP abilities
 *    into Gemini function declarations, eliminating the need for hardcoded tools.
 *
 * 3. **Dynamic Execution**: Abilities are executed by name using the Abilities API
 *    registry, allowing flexible addition of new capabilities without code changes.
 *
 * 4. **Schema Mapping**: Automatically transforms WordPress JSON Schema format
 *    to Gemini-compatible parameter specifications.
 *
 * @package Gemini_WP_Chatbot
 * @since 1.0.0
 */
class Gemini_WP_Chatbot {

	/**
	 * Registers multiple WordPress abilities for Gemini integration.
	 * Hooked to 'wp_abilities_api_init' to properly integrate with the Abilities API.
	 */
	public function register_wp_abilities() {
		// Register ability: 'gemini-chatbot/search-content'
		// Searches WordPress posts and pages based on a query
		wp_register_ability( 'gemini-chatbot/search-content', [
			'name'                => 'gemini-chatbot/search-content',
			'description'         => 'Retrieves relevant content snippets from the WordPress site\'s posts and pages based on a search query. Use this when the user asks about site content, like "what are your recent articles" or "do you have a post about [topic]".',
			'category'            => 'content',
			'permission_callback' => '__return_true',
			'execute_callback'    => [ $this, 'execute_search_ability' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'query' => [
						'type'        => 'string',
						'description' => 'The specific search term to use for querying the WordPress content database.',
					],
				],
				'required'   => [ 'query' ],
			],
			'output_schema'       => [
				'type'        => 'string',
				'description' => 'Formatted search results containing post titles, URLs, and content snippets.',
			],
		] );

		// Register ability: 'gemini-chatbot/get-post'
		// Gets a specific post by ID or slug
		wp_register_ability( 'gemini-chatbot/get-post', [
			'name'                => 'gemini-chatbot/get-post',
			'description'         => 'Retrieves a specific WordPress post by ID or slug. Use this when the user asks for a specific post like "show me post 123" or "get the post with slug my-article".',
			'category'            => 'content',
			'permission_callback' => '__return_true',
			'execute_callback'    => [ $this, 'execute_get_post_ability' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The WordPress post ID to retrieve.',
					],
					'slug'    => [
						'type'        => 'string',
						'description' => 'The WordPress post slug to retrieve.',
					],
				],
			],
			'output_schema'       => [
				'type'        => 'string',
				'description' => 'Full post details including title, content, URL, author, and date.',
			],
		] );

		// Register ability: 'gemini-chatbot/list-posts'
		// Lists recent posts with optional filtering
		wp_register_ability( 'gemini-chatbot/list-posts', [
			'name'                => 'gemini-chatbot/list-posts',
			'description'         => 'Lists recent WordPress posts with optional filtering. Use this when the user asks for "recent posts", "latest articles", or posts of a specific type.',
			'category'            => 'content',
			'permission_callback' => '__return_true',
			'execute_callback'    => [ $this, 'execute_list_posts_ability' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_type' => [
						'type'        => 'string',
						'description' => 'The post type to retrieve (default: "post"). Can be "post" or "page".',
						'default'     => 'post',
					],
					'limit'     => [
						'type'        => 'integer',
						'description' => 'Maximum number of posts to retrieve (default: 5, max: 20).',
						'default'     => 5,
						'minimum'     => 1,
						'maximum'     => 20,
					],
				],
			],
			'output_schema'       => [
				'type'        => 'string',
				'description' => 'Formatted list of posts with titles, URLs, excerpts, and metadata.',
			],
		] );
	}

	/**
	 * Executes the WordPress content search ability.
	 * This is the execution callback for the 'gemini-chatbot/search-content' ability.
	 *
	 * @param array $args The arguments provided by the AI model's function call.
	 * @return string The search result formatted as a string for the AI to use.
	 */
	public function execute_search_ability( $args ) {
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
	 * Executes the get post ability.
	 * This is the execution callback for the 'gemini-chatbot/get-post' ability.
	 *
	 * @param array $args The arguments provided by the AI model's function call.
	 * @return string|WP_Error The post details or error message.
	 */
	public function execute_get_post_ability( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$slug    = isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '';

		// Get post by ID or slug
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
		} elseif ( ! empty( $slug ) ) {
			$post = get_page_by_path( $slug, OBJECT, [ 'post', 'page' ] );
		} else {
			return new WP_Error( 'missing_parameter', 'Either post_id or slug must be provided.' );
		}

		if ( ! $post || $post->post_status !== 'publish' ) {
			return new WP_Error( 'post_not_found', 'The requested post was not found or is not published.' );
		}

		// Format post details for AI
		$content = strip_tags( $post->post_content );
		$author  = get_the_author_meta( 'display_name', $post->post_author );
		$date    = get_the_date( '', $post );

		$result = "POST DETAILS:\n\n";
		$result .= "Title: " . $post->post_title . "\n";
		$result .= "URL: " . get_permalink( $post ) . "\n";
		$result .= "Author: " . $author . "\n";
		$result .= "Date: " . $date . "\n";
		$result .= "Type: " . $post->post_type . "\n\n";
		$result .= "Content:\n" . $content . "\n";

		return $result;
	}

	/**
	 * Executes the list posts ability.
	 * This is the execution callback for the 'gemini-chatbot/list-posts' ability.
	 *
	 * @param array $args The arguments provided by the AI model's function call.
	 * @return string The formatted list of posts.
	 */
	public function execute_list_posts_ability( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_text_field( $args['post_type'] ) : 'post';
		$limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 5;

		// Ensure limit is within bounds
		$limit = min( max( $limit, 1 ), 20 );

		// Validate post type
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			$post_type = 'post';
		}

		// Query posts
		$query_args = [
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			return "No {$post_type}s found.";
		}

		$result = "Recent {$post_type}s (showing {$query->post_count} of {$query->found_posts} total):\n\n";

		while ( $query->have_posts() ) {
			$query->the_post();
			$excerpt = has_excerpt() ? get_the_excerpt() : substr( strip_tags( get_the_content() ), 0, 150 ) . '...';
			$author  = get_the_author();
			$date    = get_the_date();

			$result .= "- TITLE: " . get_the_title() . "\n";
			$result .= "  URL: " . get_permalink() . "\n";
			$result .= "  Author: " . $author . "\n";
			$result .= "  Date: " . $date . "\n";
			$result .= "  Excerpt: " . $excerpt . "\n\n";
		}

		wp_reset_postdata();

		return $result;
	}

	/**
	 * Converts WordPress ability schemas to Gemini function declaration format.
	 *
	 * @param array $schema The WordPress ability input schema.
	 * @return array The Gemini-compatible parameter format.
	 */
	private function convert_schema_to_gemini_format( $schema ) {
		// If schema is already in the correct format, return it
		if ( isset( $schema['type'] ) && isset( $schema['properties'] ) ) {
			return $schema;
		}

		// Default schema structure
		return [
			'type'       => 'object',
			'properties' => isset( $schema['properties'] ) ? $schema['properties'] : [],
			'required'   => isset( $schema['required'] ) ? $schema['required'] : [],
		];
	}

	/**
	 * Gets Gemini tool declarations from registered WordPress abilities.
	 * Dynamically converts all registered abilities to Gemini function declarations.
	 *
	 * @return array Array of Gemini function declarations.
	 */
	private function get_gemini_tools_from_abilities() {
		$function_declarations = [];

		// Check if wp_get_abilities() function exists
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			// Fallback to empty array if Abilities API is not available
			return $function_declarations;
		}

		// Get all registered abilities
		$abilities = wp_get_abilities();

		if ( empty( $abilities ) || ! is_array( $abilities ) ) {
			return $function_declarations;
		}

		// Filter for only gemini-chatbot abilities
		foreach ( $abilities as $ability_name => $ability ) {
			// Only include abilities in the gemini-chatbot namespace
			if ( strpos( $ability_name, 'gemini-chatbot/' ) !== 0 ) {
				continue;
			}

			// Extract the ability name without namespace for Gemini
			$function_name = str_replace( 'gemini-chatbot/', '', $ability_name );
			$function_name = str_replace( '-', '_', $function_name );

			// Get the input schema
			$input_schema = isset( $ability['input_schema'] ) ? $ability['input_schema'] : [];
			$parameters   = $this->convert_schema_to_gemini_format( $input_schema );

			// Build function declaration
			$function_declarations[] = [
				'name'        => $function_name,
				'description' => isset( $ability['description'] ) ? $ability['description'] : '',
				'parameters'  => $parameters,
			];
		}

		return $function_declarations;
	}

	/**
	 * Executes a registered WordPress ability by name.
	 *
	 * @param string $ability_name The name of the ability to execute (with gemini-chatbot/ namespace).
	 * @param array  $args         The arguments to pass to the ability.
	 * @return string The result of the ability execution.
	 */
	private function execute_ability( $ability_name, $args ) {
		// Check if wp_has_ability() and wp_get_ability() functions exist
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return 'Error: WordPress Abilities API is not available.';
		}

		// Check if ability exists
		if ( ! wp_has_ability( $ability_name ) ) {
			return "Error: Ability '{$ability_name}' is not registered.";
		}

		// Get the ability
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability || ! isset( $ability['execute_callback'] ) ) {
			return "Error: Ability '{$ability_name}' does not have a valid execution callback.";
		}

		// Execute the ability
		$result = call_user_func( $ability['execute_callback'], $args );

		// Handle WP_Error responses
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}

		// Format result - ensure it's a string for Gemini
		if ( is_array( $result ) || is_object( $result ) ) {
			return wp_json_encode( $result );
		}

		return (string) $result;
	}

	/**
	 * Constructor: Hooks into WordPress actions.
	 */
	public function __construct() {
		// Register abilities on the proper Abilities API hook
		add_action( 'wp_abilities_api_init', [ $this, 'register_wp_abilities' ] );
		
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
			
			// Step 1: Execute the requested WP Ability dynamically
			// Convert function name from Gemini format (with underscores) to WP ability name (with hyphens)
			$function_name = $function_call['name'];
			$ability_name = 'gemini-chatbot/' . str_replace( '_', '-', $function_name );
			
			// Execute the ability dynamically
			$tool_result = $this->execute_ability( $ability_name, $function_call['args'] );

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
	 * Dynamically generates tool declarations from registered WordPress abilities.
	 *
	 * @param string $prompt  The user prompt.
	 * @param string $api_key The Gemini API key.
	 * @return array The API response containing text or function call.
	 */
	private function call_gemini_api( $prompt, $api_key ) {
		// Get dynamic tool declarations from registered abilities
		$function_declarations = $this->get_gemini_tools_from_abilities();

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
		
		$body = [
			'contents' => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ]
			],
		];

		// Only add tools if we have function declarations
		if ( ! empty( $function_declarations ) ) {
			$body['tools'] = [
				[ 'function_declarations' => $function_declarations ]
			];
		}

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