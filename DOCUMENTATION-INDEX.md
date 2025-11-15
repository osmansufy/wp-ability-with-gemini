# Documentation Index

Complete documentation for the Gemini WP Ability Chatbot plugin.

## 📖 Documentation Files

### 1. [README.md](README.md) - Main Documentation
**Start here if you want to understand and use the plugin.**

Covers:
- Overview and features
- Installation and setup instructions
- Usage examples
- Technical details and security
- Customization and troubleshooting
- Complete code examples

**Best for:** Users, developers implementing the plugin

---

### 2. [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md) - Deep Dive
**Read this to understand the WordPress Abilities API ↔ Gemini integration.**

Covers:
- The problem: Gemini doesn't directly access WordPress registry
- How the integration actually works
- Why register abilities in WordPress if Gemini can't read them?
- Benefits of proper integration (single source of truth)
- Real-world examples of extensibility

**Best for:** Developers wanting to understand the architecture

---

### 3. [FLOW-DIAGRAM.md](FLOW-DIAGRAM.md) - Visual Guide
**Follow this to see the complete data flow step-by-step.**

Covers:
- Visual ASCII diagrams of the entire flow
- All 10 steps from ability registration to user response
- Key integration points highlighted
- Before/After comparison of the code
- Quick reference for adding new abilities

**Best for:** Visual learners, debugging, understanding the flow

---

## 🎯 Quick Navigation

### I want to...

#### ...understand what this plugin does
→ Start with [README.md § Overview](README.md#overview)

#### ...install and configure the plugin
→ Go to [README.md § Installation](README.md#installation)

#### ...understand how Gemini and WordPress communicate
→ Read [INTEGRATION-GUIDE.md § The Correct Integration Pattern](INTEGRATION-GUIDE.md#the-correct-integration-pattern)

#### ...see the complete flow visually
→ View [FLOW-DIAGRAM.md](FLOW-DIAGRAM.md)

#### ...add a new ability
→ Check [FLOW-DIAGRAM.md § Adding New Abilities](FLOW-DIAGRAM.md#adding-new-abilities---quick-reference)
→ Also see [README.md § Add More Abilities](README.md#add-more-abilities)

#### ...understand why we use wp_register_ability()
→ Read [INTEGRATION-GUIDE.md § Why Use wp_register_ability()?](INTEGRATION-GUIDE.md#why-use-wp_register_ability)

#### ...troubleshoot issues
→ See [README.md § Troubleshooting](README.md#troubleshooting)

#### ...understand the security features
→ Review [README.md § Security Features](README.md#security-features)

---

## 🔑 Key Concepts

### WordPress Abilities API
The [WordPress Abilities API](https://github.com/WordPress/abilities-api) is a standardized way for WordPress plugins, themes, and core to declare what they can do in a machine-readable format.

**Purpose:**
- Discoverability (other tools can find your abilities)
- Standardization (uniform schema across all plugins)
- Permission management (control who can use abilities)
- AI integration (expose capabilities to AI models)

### Gemini Function Calling
Gemini's [function calling](https://ai.google.dev/docs/function_calling) allows the AI model to:
- Recognize when it needs external data
- Request specific function calls with parameters
- Incorporate function results into responses

### The Bridge
This plugin creates a bridge between these two systems:

```
WordPress Abilities API  ←→  This Plugin  ←→  Gemini Function Calling
   (Declaration)            (Translation)        (Intelligent Use)
```

---

## 📊 Architecture Overview

```
┌──────────────────────────────────────────────────────────┐
│  User Interface (Chat Widget)                            │
│  [script.js + style.css]                                 │
└────────────────────┬─────────────────────────────────────┘
                     │ AJAX
                     ▼
┌──────────────────────────────────────────────────────────┐
│  WordPress Backend                                       │
│  ┌────────────────────────────────────────────────────┐ │
│  │  Gemini_WP_Chatbot Class                           │ │
│  │                                                     │ │
│  │  • Registers abilities                             │ │
│  │  • Converts abilities → tools                      │ │
│  │  • Manages API communication                       │ │
│  │  • Executes function calls                         │ │
│  └────────────────────────────────────────────────────┘ │
└────────────────────┬─────────────────────────────────────┘
                     │ API Calls
                     ▼
┌──────────────────────────────────────────────────────────┐
│  Google Gemini API                                       │
│  • Understands natural language                          │
│  • Decides when to call functions                        │
│  • Generates contextual responses                        │
└──────────────────────────────────────────────────────────┘
```

---

## 🚀 Getting Started Checklist

- [ ] Read the [README.md Overview](README.md#overview)
- [ ] Install WordPress Abilities API plugin (optional but recommended)
- [ ] Get a [Gemini API key](https://aistudio.google.com/app/apikey)
- [ ] Install and activate this plugin
- [ ] Configure the API key in Settings → Gemini Chatbot
- [ ] Add the `[gemini_wp_chatbot]` shortcode to a page
- [ ] Test with general questions
- [ ] Test with site-specific questions
- [ ] Review [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md) to understand the architecture
- [ ] (Optional) Add custom abilities following the examples

---

## 💡 Code Examples

### Adding a New Ability

See the inline comments in `gemini-wp-ability-chatbot.php` (lines 75-113) for a complete example of adding a `get_recent_posts` ability.

Quick template:

```php
$ability_config = [
    'name'                => 'your_ability_name',
    'description'         => 'What this ability does',
    'permission_callback' => '__return_true',
    'execution_callback'  => [ $this, 'execute_your_ability' ],
    'schema'              => [
        'type'       => 'object',
        'properties' => [
            'param_name' => [
                'type'        => 'string',
                'description' => 'Parameter description',
            ],
        ],
        'required'   => [ 'param_name' ],
    ],
];

if ( function_exists( 'wp_register_ability' ) ) {
    wp_register_ability( 'your_ability_name', $ability_config );
}

$this->registered_abilities['your_ability_name'] = $ability_config;
```

Then implement the execution callback:

```php
public function execute_your_ability( $args ) {
    $param = isset( $args['param_name'] ) ? sanitize_text_field( $args['param_name'] ) : '';
    
    // Your logic here
    
    return 'Formatted result string';
}
```

---

## 🔗 External Resources

- [WordPress Abilities API GitHub](https://github.com/WordPress/abilities-api)
- [WordPress AI Initiative](https://make.wordpress.org/ai/)
- [Gemini API Documentation](https://ai.google.dev/docs)
- [Gemini Function Calling Guide](https://ai.google.dev/docs/function_calling)
- [Google AI Studio](https://aistudio.google.com/) (Get API keys)

---

## 📝 File Structure

```
gemini-wp-ability-chatbot/
├── gemini-wp-ability-chatbot.php   # Main plugin file
├── script.js                        # Frontend chat interface
├── style.css                        # Chat widget styles
├── README.md                        # Main documentation
├── INTEGRATION-GUIDE.md             # Deep dive into integration
├── FLOW-DIAGRAM.md                  # Visual flow diagrams
└── DOCUMENTATION-INDEX.md           # This file
```

---

## 🤝 Contributing

Areas for improvement:
1. Additional WordPress abilities (categories, tags, custom post types)
2. Conversation history support
3. User authentication and personalization
4. WooCommerce integration
5. Performance optimizations (caching)
6. Enhanced error handling

---

## ❓ FAQ

### Q: Does Gemini directly access the WordPress Abilities registry?
**A:** No. Gemini receives tool declarations in API requests. The plugin bridges the two systems by converting registered abilities to Gemini tool format. See [INTEGRATION-GUIDE.md](INTEGRATION-GUIDE.md) for details.

### Q: Why register abilities in WordPress if Gemini can't read them?
**A:** For discoverability, standardization, and future-proofing. Other WordPress tools and plugins can discover and use your abilities. See [INTEGRATION-GUIDE.md § Why Use wp_register_ability()?](INTEGRATION-GUIDE.md#why-use-wp_register_ability).

### Q: Can I add more abilities?
**A:** Yes! Very easily. Define the config once, register it, and store it. The plugin automatically converts all registered abilities to Gemini tools. See examples in the code and documentation.

### Q: Does this work without the Abilities API plugin?
**A:** Yes. The plugin checks if `wp_register_ability()` exists. If not, it still works but only stores abilities locally for Gemini. Installing the Abilities API plugin enables full WordPress integration.

### Q: What models does it support?
**A:** Currently configured for `gemini-2.5-flash`. You can change to `gemini-2.5-pro` or other models by updating line 248 in the main PHP file.

### Q: Is conversation history supported?
**A:** Not yet. Currently each message is independent. This is on the roadmap for future updates.

---

## 📄 License

GPL-2.0+ - See main plugin file for full license text.

---

**Happy coding!** 🚀

For questions or issues, refer to the specific documentation files above or check the [WordPress Abilities API GitHub](https://github.com/WordPress/abilities-api) for community support.

