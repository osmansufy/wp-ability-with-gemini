# How Gemini Interacts with WordPress Abilities API

## The Current Problem

Looking at the code, there's an important issue to understand: **Gemini doesn't directly read from the WordPress Abilities Registry**.

### What's Happening Now

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress Abilities Registry                                │
│  (Stored in WordPress)                                       │
│                                                               │
│  wp_register_ability('search_wp_content', [...])            │
│     ├─ name: 'search_wp_content'                            │
│     ├─ description: '...'                                    │
│     ├─ schema: { type: 'object', ... }                      │
│     └─ execution_callback: function                         │
└─────────────────────────────────────────────────────────────┘
                          ❌ NOT CONNECTED
┌─────────────────────────────────────────────────────────────┐
│  Gemini API Tool Declaration                                 │
│  (Sent in API Request)                                       │
│                                                               │
│  $tool_declaration = [                                       │
│    'function_declarations' => [                              │
│      'name' => 'search_wp_content',  // ⚠️ DUPLICATED      │
│      'description' => '...',         // ⚠️ DUPLICATED      │
│      'parameters' => {...}           // ⚠️ DUPLICATED      │
│    ]                                                         │
│  ]                                                           │
└─────────────────────────────────────────────────────────────┘
```

### Why They're Separate

1. **WordPress Abilities Registry**: 
   - Lives in WordPress (PHP)
   - Stores abilities for WordPress to discover and execute
   - Used by WordPress core and other plugins

2. **Gemini Tool Declarations**:
   - Sent to Google's servers via API
   - Lives in JSON format in API requests
   - Tells Gemini what functions are available

**The problem**: The schema is manually duplicated in two places (lines 43-59 and lines 209-226).

## The Correct Integration Pattern

Here's how they SHOULD work together:

```
┌────────────────────────────────────────────────────────────────────┐
│  1. REGISTER ABILITY IN WORDPRESS                                   │
│                                                                     │
│  wp_register_ability('search_wp_content', [                        │
│    'description' => 'Searches WordPress content',                  │
│    'schema' => [...],                                              │
│    'execution_callback' => [...]                                   │
│  ]);                                                                │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 2. RETRIEVE FROM REGISTRY
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  $abilities = wp_get_abilities();  // Get all registered abilities │
│                                                                     │
│  // Transform to Gemini format                                     │
│  $tool_declarations = [];                                          │
│  foreach ($abilities as $ability) {                                │
│    $tool_declarations[] = [                                        │
│      'name' => $ability['name'],                                   │
│      'description' => $ability['description'],                     │
│      'parameters' => $ability['schema']                            │
│    ];                                                              │
│  }                                                                 │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 3. SEND TO GEMINI
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  POST to Gemini API                                                 │
│  {                                                                  │
│    "contents": [...],                                              │
│    "tools": [{                                                     │
│      "function_declarations": $tool_declarations                   │
│    }]                                                              │
│  }                                                                 │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 4. GEMINI RESPONDS WITH FUNCTION CALL
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  {                                                                  │
│    "functionCall": {                                               │
│      "name": "search_wp_content",                                  │
│      "args": {"query": "recent articles"}                          │
│    }                                                               │
│  }                                                                 │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 5. EXECUTE VIA WORDPRESS ABILITY
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  // WordPress looks up the ability and executes it                 │
│  $result = wp_execute_ability('search_wp_content', [               │
│    'query' => 'recent articles'                                    │
│  ]);                                                               │
│                                                                     │
│  // OR manually call the execution callback                        │
│  $result = $this->execute_wp_search_ability(['query' => '...']);  │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 6. SEND RESULT BACK TO GEMINI
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  POST to Gemini API (multi-turn)                                   │
│  {                                                                  │
│    "contents": [                                                   │
│      { "role": "user", ... },                                      │
│      { "role": "model", "functionCall": {...} },                   │
│      { "role": "tool", "functionResponse": {                       │
│          "name": "search_wp_content",                              │
│          "response": {"result": "WordPress Content..."}            │
│        }                                                           │
│      }                                                             │
│    ]                                                               │
│  }                                                                 │
└─────────────────────────────────┬──────────────────────────────────┘
                                  │
                                  │ 7. GEMINI GENERATES FINAL RESPONSE
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│  "Based on your site content, here are recent articles..."         │
└────────────────────────────────────────────────────────────────────┘
```

## Why Use wp_register_ability()?

Even though Gemini doesn't directly access it, registering abilities in WordPress is valuable for:

### 1. **Discoverability**
Other plugins and tools can discover what your plugin can do:

```php
// Another plugin can find all available abilities
$abilities = wp_get_abilities();
foreach ($abilities as $ability) {
    echo "Available: " . $ability['name'];
}
```

### 2. **Permission Management**
WordPress can check if an AI model or user has permission to execute an ability:

```php
wp_register_ability('delete_posts', [
    'permission_callback' => function() {
        return current_user_can('delete_posts');
    },
    // ...
]);
```

### 3. **Standardization**
All plugins declare abilities in the same format, making WordPress AI-ready:

```php
// WooCommerce plugin
wp_register_ability('search_products', [...]);

// BuddyPress plugin
wp_register_ability('get_user_groups', [...]);

// Your plugin
wp_register_ability('search_wp_content', [...]);
```

### 4. **Future-Proofing**
When WordPress core or other AI tools want to use your abilities, they're already registered:

```php
// Future: WordPress AI Assistant
$wp_ai_assistant = new WP_AI_Assistant();
$wp_ai_assistant->auto_discover_abilities(); // Finds all registered abilities
$wp_ai_assistant->connect_to_gemini(); // Automatically sends them to Gemini
```

### 5. **Schema Validation**
WordPress can validate function call arguments before execution:

```php
// Before executing, WordPress validates the args match the schema
$is_valid = wp_validate_ability_args('search_wp_content', $args);
if (!$is_valid) {
    return new WP_Error('invalid_args', 'Arguments do not match schema');
}
```

## The Ideal Implementation

Here's how the code SHOULD work to properly integrate both systems:

```php
class Gemini_WP_Chatbot {
    
    private $registered_abilities = [];
    
    public function register_wp_ability() {
        // Define the ability once
        $ability_config = [
            'id'                  => 'plugin/search_wp_content',
            'name'                => 'search_wp_content',
            'description'         => 'Retrieves relevant content snippets from WordPress...',
            'permission_callback' => '__return_true',
            'execution_callback'  => [ $this, 'execute_wp_search_ability' ],
            'schema'              => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'The search term to query...',
                    ],
                ],
                'required'   => [ 'query' ],
            ],
        ];
        
        // Register with WordPress
        wp_register_ability( 'search_wp_content', $ability_config );
        
        // Store for later use with Gemini
        $this->registered_abilities['search_wp_content'] = $ability_config;
    }
    
    private function get_tool_declarations_from_abilities() {
        $function_declarations = [];
        
        // Convert WordPress abilities to Gemini tool format
        foreach ( $this->registered_abilities as $name => $ability ) {
            $function_declarations[] = [
                'name'        => $ability['name'],
                'description' => $ability['description'],
                'parameters'  => $ability['schema'], // Same schema!
            ];
        }
        
        return [
            'function_declarations' => $function_declarations,
        ];
    }
    
    private function call_gemini_api( $prompt, $api_key ) {
        // Get tool declarations from registered abilities
        // NO DUPLICATION!
        $tool_declaration = $this->get_tool_declarations_from_abilities();
        
        $body = [
            'contents' => [
                [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ]
            ],
            'tools' => [ $tool_declaration ],
        ];
        
        // ... rest of API call
    }
}
```

## Benefits of Proper Integration

### ✅ Single Source of Truth
- Ability schema defined once
- No duplication = no sync issues
- Update in one place, affects both WordPress and Gemini

### ✅ Dynamic Ability Discovery
- Add new abilities without modifying API code
- Other plugins can register abilities that Gemini can use
- WordPress becomes a universal AI ability platform

### ✅ Automatic Tool Declaration
- WordPress abilities → Gemini tools automatically
- No manual JSON construction
- Less error-prone

### ✅ Scalability
- Easy to add 10, 20, 100+ abilities
- Each plugin can register its own abilities
- Gemini automatically gets access to all of them

## Real-World Example

Imagine multiple plugins registering abilities:

```php
// YOUR PLUGIN: Content Search
wp_register_ability('search_wp_content', [...]);

// WOOCOMMERCE: Product Search
wp_register_ability('search_products', [
    'description' => 'Search WooCommerce products',
    'schema' => [
        'properties' => [
            'query' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'price_range' => ['type' => 'object']
        ]
    ],
    'execution_callback' => 'wc_search_products_callback'
]);

// BUDDYPRESS: User Groups
wp_register_ability('get_user_groups', [
    'description' => 'Get user groups and communities',
    'schema' => [
        'properties' => [
            'user_id' => ['type' => 'number']
        ]
    ],
    'execution_callback' => 'bp_get_groups_callback'
]);
```

Your chatbot automatically gains ALL these abilities:

```php
// In your plugin
$all_abilities = wp_get_abilities();
$gemini_tools = $this->convert_abilities_to_gemini_tools($all_abilities);

// Now Gemini can:
// - Search WordPress content
// - Search WooCommerce products
// - Get BuddyPress user groups
// All automatically!
```

User asks: "Show me tech products under $100 and recent blog posts about PHP"

Gemini:
1. Calls `search_products` with filters
2. Calls `search_wp_content` with query
3. Combines results in one response

## Conclusion

The WordPress Abilities API provides the **registry and execution framework**, while Gemini provides the **AI intelligence to decide when to use abilities**.

The key is to:
1. ✅ Register abilities in WordPress (standardized discovery)
2. ✅ Retrieve abilities from the registry (single source of truth)
3. ✅ Convert to Gemini tool format (dynamic tool declarations)
4. ✅ Let Gemini decide when to call them (intelligent function calling)
5. ✅ Execute via WordPress callbacks (controlled execution)

This creates a powerful, extensible, and standardized AI integration for WordPress.

