# Gemini ↔ WordPress Abilities Integration Flow

## Complete Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: ABILITY REGISTRATION (On Plugin Load)                              │
│  Location: register_wp_ability() method                                     │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  Define Ability Config (SINGLE SOURCE OF TRUTH)    │
        │  ─────────────────────────────────────────────     │
        │  $ability_config = [                               │
        │    'name' => 'search_wp_content',                  │
        │    'description' => '...',                         │
        │    'schema' => [...],                              │
        │    'execution_callback' => [...]                   │
        │  ]                                                 │
        └────────────────────────────────────────────────────┘
                                     │
                    ┌────────────────┴────────────────┐
                    │                                 │
                    ▼                                 ▼
        ┌─────────────────────┐         ┌─────────────────────────┐
        │  WordPress Registry │         │  Local Storage          │
        │  ─────────────────  │         │  ─────────────          │
        │  wp_register_       │         │  $this->registered_     │
        │    ability()        │         │    abilities[]          │
        │                     │         │                         │
        │  (For WP to use)    │         │  (For Gemini API)       │
        └─────────────────────┘         └─────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: USER SENDS MESSAGE                                                 │
│  Location: Frontend (script.js)                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
                      User types: "What are your recent articles?"
                                     │
                                     ▼
                        $.ajax({
                          action: 'gemini_chat',
                          prompt: 'What are your recent articles?'
                        })
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: WORDPRESS RECEIVES REQUEST                                         │
│  Location: handle_chat_request() method                                     │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
                            call_gemini_api()
                                     │
                                     ▼


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: CONVERT ABILITIES TO GEMINI TOOLS                                  │
│  Location: get_tool_declarations_from_abilities() method                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  Fetch from $this->registered_abilities            │
        │  ───────────────────────────────────────           │
        │  foreach ($this->registered_abilities as $ability) │
        │    $gemini_tools[] = [                             │
        │      'name' => $ability['name'],                   │
        │      'description' => $ability['description'],     │
        │      'parameters' => $ability['schema']            │
        │    ]                                               │
        └────────────────────────────────────────────────────┘
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  Gemini Tool Format                                │
        │  ──────────────────                                │
        │  {                                                 │
        │    "function_declarations": [{                     │
        │      "name": "search_wp_content",                  │
        │      "description": "Retrieves content...",        │
        │      "parameters": {                               │
        │        "type": "object",                           │
        │        "properties": {                             │
        │          "query": { "type": "string" }             │
        │        }                                           │
        │      }                                             │
        │    }]                                              │
        │  }                                                 │
        └────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 5: SEND REQUEST TO GEMINI API                                         │
│  Location: call_gemini_api() method                                         │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        POST https://generativelanguage.googleapis.com/.../generateContent
        
        {
          "contents": [
            { "role": "user", "parts": [{ "text": "What are your recent articles?" }] }
          ],
          "tools": [
            { "function_declarations": [...] }  ← From step 4
          ]
        }
                                     │
                                     │  (API processes request)
                                     ▼
                          ┌──────────────────────┐
                          │   GEMINI DECIDES:    │
                          │                      │
                          │  "I need WordPress   │
                          │   content to answer  │
                          │   this question!"    │
                          └──────────────────────┘
                                     │
                                     ▼


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 6: GEMINI RESPONDS WITH FUNCTION CALL                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        {
          "candidates": [{
            "content": {
              "parts": [{
                "functionCall": {
                  "name": "search_wp_content",
                  "args": { "query": "recent articles" }
                }
              }]
            }
          }]
        }
                                     │
                                     ▼


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 7: EXECUTE WORDPRESS ABILITY                                          │
│  Location: execute_wp_search_ability() method                               │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
                    $this->execute_wp_search_ability([
                      'query' => 'recent articles'
                    ])
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  WP_Query                                          │
        │  ────────                                          │
        │  new WP_Query([                                    │
        │    's' => 'recent articles',                       │
        │    'post_type' => ['post', 'page'],                │
        │    'posts_per_page' => 3                           │
        │  ])                                                │
        └────────────────────────────────────────────────────┘
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  Formatted Results                                 │
        │  ─────────────────                                 │
        │  "WordPress Content Snippets:                      │
        │                                                    │
        │  TITLE: How to Use WordPress                       │
        │  URL: https://example.com/...                      │
        │  SNIPPET: This guide shows...                      │
        │  ---                                               │
        │                                                    │
        │  TITLE: Getting Started with Plugins               │
        │  URL: https://example.com/...                      │
        │  SNIPPET: Learn how to...                          │
        │  ---"                                              │
        └────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 8: SEND FUNCTION RESULT BACK TO GEMINI                                │
│  Location: call_gemini_api_with_tool_result() method                        │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        POST https://generativelanguage.googleapis.com/.../generateContent
        
        {
          "contents": [
            // Turn 1: Original user message
            { "role": "user", "parts": [{ "text": "What are your recent articles?" }] },
            
            // Turn 2: Model's function call request
            { "role": "model", "parts": [{
                "functionCall": {
                  "name": "search_wp_content",
                  "args": { "query": "recent articles" }
                }
              }]
            },
            
            // Turn 3: Function execution result
            { "role": "tool", "parts": [{
                "functionResponse": {
                  "name": "search_wp_content",
                  "response": {
                    "result": "WordPress Content Snippets: ..."  ← From step 7
                  }
                }
              }]
            }
          ]
        }
                                     │
                                     │  (Gemini processes with context)
                                     ▼


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 9: GEMINI GENERATES FINAL RESPONSE                                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
        {
          "candidates": [{
            "content": {
              "parts": [{
                "text": "Based on your site, here are recent articles:\n\n
                         1. **How to Use WordPress** - This guide shows...\n
                         [Read more](https://example.com/...)\n\n
                         2. **Getting Started with Plugins** - Learn how to...\n
                         [Read more](https://example.com/...)"
              }]
            }
          }]
        }
                                     │
                                     ▼


┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 10: RETURN TO USER                                                    │
│  Location: handle_chat_request() → Frontend                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
                    wp_send_json_success([
                      'message' => 'Based on your site...'
                    ])
                                     │
                                     ▼
                         appendMessage(message, 'bot')
                                     │
                                     ▼
        ┌────────────────────────────────────────────────────┐
        │  Chat Interface                                    │
        │  ──────────────                                    │
        │                                                    │
        │  User: What are your recent articles?              │
        │                                                    │
        │  Bot: Based on your site, here are recent          │
        │       articles:                                    │
        │                                                    │
        │       1. How to Use WordPress - This guide...      │
        │          Read more →                               │
        │                                                    │
        │       2. Getting Started with Plugins - Learn...   │
        │          Read more →                               │
        └────────────────────────────────────────────────────┘
```

## Key Integration Points

### 🔗 Point 1: Single Source of Truth (Step 1)
- **Location**: `register_wp_ability()` method, lines 47-73
- **What**: Ability config defined once
- **Why**: No duplication, stays in sync

### 🔗 Point 2: Abilities → Tools Conversion (Step 4)
- **Location**: `get_tool_declarations_from_abilities()` method, lines 223-238
- **What**: Transforms WordPress abilities to Gemini tool format
- **Why**: Bridges WordPress and Gemini APIs

### 🔗 Point 3: Function Call Detection (Step 6)
- **Location**: `call_gemini_api()` method, lines 250-258
- **What**: Detects when Gemini requests a function
- **Why**: Triggers WordPress ability execution

### 🔗 Point 4: Multi-Turn Conversation (Step 8)
- **Location**: `call_gemini_api_with_tool_result()` method, lines 269-316
- **What**: Sends conversation history + function result
- **Why**: Gives Gemini context to generate final answer

## Adding New Abilities - Quick Reference

When you add a new ability:

```php
// 1. Define the config
$new_ability_config = [
    'name' => 'your_ability_name',
    'description' => 'What it does',
    'schema' => [...],
    'execution_callback' => [ $this, 'execute_your_ability' ]
];

// 2. Register with WordPress
if ( function_exists( 'wp_register_ability' ) ) {
    wp_register_ability( 'your_ability_name', $new_ability_config );
}

// 3. Store for Gemini
$this->registered_abilities['your_ability_name'] = $new_ability_config;

// THAT'S IT! ✅
// - Automatically available to Gemini
// - Automatically in tool declarations
// - No need to modify API calls
```

## Comparison: Before vs After

### ❌ BEFORE (Duplicated)

```php
// Location 1: WordPress Registry
wp_register_ability('search_wp_content', [
    'name' => 'search_wp_content',
    'description' => 'Searches content...',
    'schema' => [...]
]);

// Location 2: Gemini API Call (DUPLICATED!)
$tool_declaration = [
    'function_declarations' => [
        [
            'name' => 'search_wp_content',
            'description' => 'Searches content...',  // Same!
            'parameters' => [...]                    // Same!
        ]
    ]
];
```

**Problems:**
- Schema defined twice
- Must update both places
- Easy to get out of sync

### ✅ AFTER (Single Source)

```php
// DEFINE ONCE
$ability_config = [
    'name' => 'search_wp_content',
    'description' => 'Searches content...',
    'schema' => [...]
];

// Register with WordPress
wp_register_ability('search_wp_content', $ability_config);

// Store for Gemini
$this->registered_abilities['search_wp_content'] = $ability_config;

// Use in Gemini API (AUTOMATIC!)
$tool_declaration = $this->get_tool_declarations_from_abilities();
```

**Benefits:**
- ✅ Single source of truth
- ✅ Always in sync
- ✅ Easy to add more abilities
- ✅ Cleaner code

## Summary

The integration works by:

1. **Registering** abilities in WordPress (for discoverability)
2. **Storing** them locally (for Gemini access)
3. **Converting** to Gemini tool format (bridge)
4. **Sending** to Gemini API (makes them available)
5. **Executing** via WordPress callbacks (when Gemini requests)
6. **Returning** results to Gemini (for final response)

This creates a seamless bridge between WordPress's ability system and Gemini's function calling capability! 🌉

