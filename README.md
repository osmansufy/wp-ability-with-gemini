# Gemini WP Ability Chatbot

A WordPress plugin that integrates Google's Gemini AI with WordPress using the **WordPress Abilities API** to create a context-aware chatbot that can search and retrieve content from your WordPress site.

## Overview

This plugin demonstrates the power of combining AI language models with WordPress's new Abilities API to create intelligent, context-aware chatbots. The chatbot can:

- Answer general questions using Gemini's knowledge base
- Search your WordPress site's content when asked about site-specific information
- Intelligently decide when to use function calling to retrieve WordPress data

### 📚 Additional Documentation

- **[DOCUMENTATION-INDEX.md](DOCUMENTATION-INDEX.md)** - Quick navigation guide to all documentation
- **[INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md)** - Deep dive into how Gemini interacts with WordPress Abilities API
- **[FLOW-DIAGRAM.md](FLOW-DIAGRAM.md)** - Visual step-by-step flow of the complete integration
- **[CHANGELOG.md](CHANGELOG.md)** - ✨ **NEW:** Complete list of changes to follow official WordPress Abilities API

### ⚡ Quick Answer: How They Work Together

**Short version:** WordPress Abilities API provides the **registry and execution framework**. Gemini provides the **AI intelligence to decide when to use them**. This plugin is the **bridge** that converts WordPress abilities to Gemini-compatible tool declarations.

```
┌─────────────────┐         ┌──────────────┐         ┌─────────────────┐
│   WordPress     │         │  This Plugin │         │  Gemini API     │
│  Abilities API  │────────▶│   (Bridge)   │────────▶│ Function Calling│
│                 │         │              │         │                 │
│ • Registers     │         │ • Converts   │         │ • Decides when  │
│ • Validates     │         │ • Translates │         │ • Calls tools   │
│ • Executes      │         │ • Manages    │         │ • Generates     │
└─────────────────┘         └──────────────┘         └─────────────────┘
    (Declaration)            (Translation)              (Intelligence)
```

For the complete explanation, see [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md).

## How It Works

### Architecture

The plugin uses a three-layer architecture:

```
┌─────────────────────────────────────────────────────────────┐
│                     Frontend (User)                          │
│              [Chat Interface via Shortcode]                  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            │ AJAX Request
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  WordPress (PHP Backend)                     │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Gemini_WP_Chatbot Class                             │  │
│  │  - Registers WP Ability                              │  │
│  │  - Handles AJAX requests                             │  │
│  │  - Manages API communication                         │  │
│  └──────────────────────────────────────────────────────┘  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            │ API Call with Tools
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Google Gemini API                         │
│              [gemini-2.5-flash model]                        │
│            Function Calling / Tool Use                       │
└─────────────────────────────────────────────────────────────┘
```

### Integration with WordPress Abilities API

The [WordPress Abilities API](https://github.com/WordPress/abilities-api) is part of the **AI Building Blocks for WordPress** initiative. It provides a standardized way for WordPress plugins, themes, and core to declare what they can do ("abilities") in a machine-readable format.

#### What is the Abilities API?

The Abilities API enables:

- **Discoverability**: Every ability can be listed, queried, and inspected
- **Interoperability**: A uniform schema lets unrelated components compose workflows
- **Security-first**: Explicit permissions determine who/what may invoke an ability
- **AI Integration**: Abilities can be exposed to AI models as callable functions

#### How This Plugin Uses Abilities API

The plugin implements a **single source of truth** pattern following the [official WordPress Abilities API specification](https://github.com/WordPress/abilities-api/blob/trunk/docs/getting-started.md) to ensure WordPress abilities and Gemini tools stay perfectly synchronized.

1. **Category Registration** (Lines 36-40):

Categories must be registered BEFORE abilities on the `wp_abilities_api_categories_init` hook:

```php
public function register_ability_categories() {
    wp_register_ability_category( 'content-retrieval', array(
        'label'       => __( 'Content Retrieval', 'gemini-wp-chat' ),
        'description' => __( 'Abilities that retrieve and return content...', 'gemini-wp-chat' ),
    ) );
}
```

2. **Ability Registration** (Lines 48-86):

```php
// Define ability configuration ONCE (Single Source of Truth)
// Following official WordPress Abilities API specification
$ability_config = array(
    'label'               => __( 'Search WordPress Content', 'gemini-wp-chat' ),
    'description'         => __( 'Retrieves relevant content snippets...', 'gemini-wp-chat' ),
    'category'            => 'content-retrieval', // Must reference registered category
    'input_schema'        => array(
        'type'       => 'object',
        'properties' => array(
            'query' => array(
                'type'        => 'string',
                'description' => 'The search term to query WordPress content.',
            ),
        ),
        'required'   => array( 'query' ),
    ),
    'output_schema'       => array(
        'type'        => 'string',
        'description' => 'Formatted string containing search results...',
    ),
    'execute_callback'    => array( $this, 'execute_wp_search_ability' ),
    'permission_callback' => '__return_true',
    'meta'                => array(
        'show_in_rest' => true, // Expose via REST API if needed
    ),
);

// Register with WordPress
$ability = wp_register_ability( 'gemini-wp-chat/search-content', $ability_config );

// Store locally for Gemini tool declarations
if ( $ability ) {
    $this->registered_abilities['gemini-wp-chat/search-content'] = array_merge(
        array( 'name' => 'gemini-wp-chat/search-content' ),
        $ability_config
    );
}
```

**Key Benefits:**
- ✅ Follows [official WordPress Abilities API specification](https://github.com/WordPress/abilities-api/blob/trunk/docs/php-api.md)
- ✅ Schema defined once, used by both WordPress and Gemini
- ✅ Proper category organization
- ✅ Separate input and output schemas
- ✅ No duplication, no synchronization issues
- ✅ Easy to add more abilities without modifying API code

3. **Tool Declaration Conversion** (Lines 310-325):

This is the bridge between WordPress Abilities API and Gemini Function Calling:

```php
private function get_tool_declarations_from_abilities() {
    $function_declarations = array();
    
    // Convert each registered ability to Gemini tool format
    foreach ( $this->registered_abilities as $name => $ability ) {
        $function_declarations[] = array(
            'name'        => str_replace( '/', '_', $ability['name'] ), // Gemini doesn't like slashes
            'description' => $ability['description'],
            'parameters'  => $ability['input_schema'], // Uses input_schema from WordPress!
        );
    }
    
    return array(
        'function_declarations' => $function_declarations,
    );
}
```

4. **Ability Execution via WordPress API** (Lines 273-278):

When Gemini requests a function call, the plugin uses the official WordPress Abilities API to execute it:

```php
if ( class_exists( 'WP_Ability' ) ) {
    $ability = wp_get_ability( $ability_name );
    if ( $ability ) {
        $result = $ability->execute( $function_call['args'] );
        $tool_result = is_wp_error( $result ) ? $result->get_error_message() : $result;
    }
}
```

5. **Ability Implementation** (Lines 143-176):
   
When Gemini decides to search WordPress content, it calls this ability, which executes a `WP_Query` to search posts and pages, returning formatted results.

### Integration with Gemini API

#### Gemini Function Calling (Tool Use)

The plugin leverages Gemini's **function calling** capability, which allows the AI model to:

1. Recognize when it needs external data
2. Request specific function calls with appropriate parameters
3. Incorporate the function results into its final response

#### The Request Flow

**Step 1: Initial User Request**

```javascript
// Frontend (script.js)
$.ajax({
    url: GeminiChatData.ajax_url,
    data: {
        action: 'gemini_chat',
        nonce: GeminiChatData.nonce,
        prompt: 'What are your recent articles?'
    }
});
```

**Step 2: WordPress Sends Request to Gemini with Tools**

```php
// Backend API call structure
{
    "contents": [
        {
            "role": "user",
            "parts": [
                { "text": "What are your recent articles?" }
            ]
        }
    ],
    "tools": [
        {
            "function_declarations": [
                {
                    "name": "search_wp_content",
                    "description": "Retrieves relevant content snippets...",
                    "parameters": {
                        "type": "object",
                        "properties": {
                            "query": {
                                "type": "string",
                                "description": "The search term..."
                            }
                        },
                        "required": ["query"]
                    }
                }
            ]
        }
    ]
}
```

**Step 3: Gemini Responds with Function Call Request**

If Gemini determines it needs WordPress content, it responds with:

```json
{
    "candidates": [
        {
            "content": {
                "parts": [
                    {
                        "functionCall": {
                            "name": "search_wp_content",
                            "args": {
                                "query": "recent articles"
                            }
                        }
                    }
                ]
            }
        }
    ]
}
```

**Step 4: WordPress Executes the Ability**

```php
// Plugin executes the search
$tool_result = $this->execute_wp_search_ability([
    'query' => 'recent articles'
]);

// Returns formatted results:
// "WordPress Content Snippets for query 'recent articles':
// 
// TITLE: My Latest Post
// URL: https://example.com/my-latest-post
// SNIPPET: This is the content of my latest post...
// ---"
```

**Step 5: Send Function Result Back to Gemini**

The plugin sends a multi-turn conversation back to Gemini:

```php
{
    "contents": [
        // Turn 1: Original user prompt
        {
            "role": "user",
            "parts": [{ "text": "What are your recent articles?" }]
        },
        // Turn 2: Model's function call
        {
            "role": "model",
            "parts": [{
                "functionCall": {
                    "name": "search_wp_content",
                    "args": { "query": "recent articles" }
                }
            }]
        },
        // Turn 3: Function result
        {
            "role": "tool",
            "parts": [{
                "functionResponse": {
                    "name": "search_wp_content",
                    "response": {
                        "result": "WordPress Content Snippets..."
                    }
                }
            }]
        }
    ]
}
```

**Step 6: Gemini Generates Final Response**

Gemini uses the WordPress content to generate a natural language response:

```
"Based on the latest content from your site, here are your recent articles:

1. **My Latest Post** - This article discusses... [Read more](https://example.com/my-latest-post)

2. **Another Great Article** - This post covers... [Read more](https://example.com/another-article)

Would you like to know more about any of these articles?"
```

**Step 7: Response Sent to User**

The formatted response is displayed in the chat interface.

### Why This Matters

#### Traditional Chatbot Limitations
Without function calling, a chatbot can only use:
- Pre-trained knowledge (often outdated)
- Information from the initial prompt

#### With Function Calling + Abilities API
The chatbot can:
- ✅ Access real-time WordPress data
- ✅ Search current posts and pages
- ✅ Retrieve user-specific content
- ✅ Extend functionality through additional abilities
- ✅ Maintain security through permission callbacks

## Installation

### Prerequisites

1. **WordPress 6.0+**
2. **PHP 7.4+**
3. **WordPress Abilities API** (required for full functionality)
   - Install from: [github.com/WordPress/abilities-api/releases/latest](https://github.com/WordPress/abilities-api/releases/latest)
   - Or with WP-CLI: `wp plugin install https://github.com/WordPress/abilities-api/releases/latest/download/abilities-api.zip`
   - Or use Composer: `composer require wordpress/abilities-api`
   - See [Installation Guide](https://github.com/WordPress/abilities-api/blob/trunk/docs/getting-started.md#installation)
4. **Gemini API Key**
   - Get one from [Google AI Studio](https://aistudio.google.com/app/apikey)

### Setup Steps

1. **Install the Plugin**
   - Upload the `gemini-wp-ability-chatbot` folder to `/wp-content/plugins/`
   - Activate the plugin through WordPress admin

2. **Configure API Key**
   - Go to **Settings → Gemini Chatbot**
   - Enter your Gemini API Key
   - Save changes

3. **Add Chatbot to Your Site**
   - Add the shortcode to any page or post:
     ```
     [gemini_wp_chatbot]
     ```
   - Or use in PHP templates:
     ```php
     <?php echo do_shortcode('[gemini_wp_chatbot]'); ?>
     ```

## Usage

### Basic Queries

Users can ask general questions:
- "What is WordPress?"
- "How do I reset my password?"
- "Tell me about machine learning"

The chatbot responds using Gemini's base knowledge.

### Site-Specific Queries

When users ask about your site content:
- "What are your recent articles?"
- "Do you have posts about SEO?"
- "Show me content about WordPress development"

The chatbot automatically:
1. Recognizes the query needs site data
2. Calls the `search_wp_content` ability
3. Searches your WordPress content
4. Returns formatted, relevant results

## Technical Details

### Key Functions

#### `call_gemini_api()`
Makes the initial API call to Gemini with tool declarations.

**Key Parameters:**
- `contents`: The conversation history
- `tools`: Array of function declarations (abilities)

#### `call_gemini_api_with_tool_result()`
Sends function execution results back to Gemini for final response generation.

**Key Parameters:**
- Multi-turn conversation including user prompt, function call, and function response

#### `execute_wp_search_ability()`
Executes WordPress content search using `WP_Query`.

**Returns:**
- Formatted string with title, URL, and content snippets
- Limited to top 3 most relevant results

### Security Features

1. **Nonce Verification**
   - All AJAX requests verified with WordPress nonces
   
2. **Input Sanitization**
   - User input sanitized with `sanitize_text_field()`
   
3. **Permission Callbacks**
   - Abilities use permission callbacks (currently `__return_true` for public access)
   - Can be customized for role-based access:
     ```php
     'permission_callback' => function() {
         return current_user_can('read');
     }
     ```

4. **API Key Protection**
   - Stored in WordPress options (never exposed to frontend)
   - Used only in server-side API calls

### Customization

#### Add More Abilities

You can register additional abilities for other WordPress functions:

```php
wp_register_ability( 'get_user_profile', [
    'id'                  => 'plugin/get_user_profile',
    'name'                => 'get_user_profile',
    'description'         => 'Retrieves current user profile information',
    'permission_callback' => function() {
        return is_user_logged_in();
    },
    'execution_callback'  => function( $args ) {
        $user = wp_get_current_user();
        return sprintf(
            'Username: %s, Email: %s',
            $user->user_login,
            $user->user_email
        );
    },
    'schema' => [
        'type' => 'object',
        'properties' => [],
    ],
] );
```

Then add it to the Gemini tool declarations in `call_gemini_api()`.

#### Customize Search Results

Modify `execute_wp_search_ability()` to:
- Include custom post types
- Change number of results
- Add custom fields or metadata
- Filter by categories or tags

#### Change AI Model

Update line 227 to use a different Gemini model:

```php
// Use a more powerful model
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=' . $api_key;

// Or use flash for faster responses
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
```

## Troubleshooting

### "Sorry, I couldn't generate a response"

**Causes:**
- Invalid API key
- API request malformed
- Gemini API rate limit exceeded
- Network connectivity issues

**Solutions:**
- Verify API key in Settings
- Check browser console for errors
- Enable WordPress debug mode to see detailed errors
- Check Gemini API quotas in Google AI Studio

### Function Calling Not Working

**Symptoms:**
- Chatbot answers generally but doesn't search WordPress content

**Solutions:**
- Ensure tool declarations match ability schema exactly
- Check that `tools` is at root level of API request (not nested in `config`)
- Verify the ability description guides Gemini to use it appropriately

### No Search Results

**Causes:**
- No published posts/pages
- Search query doesn't match content
- Permission issues

**Solutions:**
- Publish some content first
- Test with broader search terms
- Check `execute_wp_search_ability()` query parameters

## Roadmap

- [ ] Support for conversation history (multi-turn chat)
- [ ] Additional abilities (get categories, recent comments, etc.)
- [ ] Admin interface to manage abilities
- [ ] Support for custom post types
- [ ] Integration with WooCommerce (product search)
- [ ] User authentication and personalized responses
- [ ] Rate limiting and caching
- [ ] Export/import chat conversations

## Contributing

Contributions are welcome! Areas for improvement:

1. **Ability Expansion**: Create more WordPress abilities
2. **UI Enhancement**: Improve chatbot interface
3. **Performance**: Add caching for API responses
4. **Security**: Enhanced permission callbacks
5. **Documentation**: More examples and use cases

## Resources

- [WordPress Abilities API](https://github.com/WordPress/abilities-api) - Official GitHub repository
- [Abilities API Handbook](https://github.com/WordPress/abilities-api/tree/trunk/docs) - Developer documentation
- [Gemini API Documentation](https://ai.google.dev/docs) - Google's Gemini API docs
- [Function Calling Guide](https://ai.google.dev/docs/function_calling) - How function calling works
- [WordPress AI Initiative](https://make.wordpress.org/ai/) - WordPress AI building blocks

## License

GPL-2.0+

This plugin is free software released under the GNU General Public License version 2 or later.

## Credits

- Built with [Google Gemini API](https://ai.google.dev/)
- Uses [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- Part of the AI Building Blocks for WordPress initiative

---

**Code is Poetry.** 🎨

