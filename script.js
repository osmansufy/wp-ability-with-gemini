jQuery(document).ready(function($) {
    const $chatMessages = $('#chat-messages');
    const $chatForm = $('#chat-form');
    const $userInput = $('#user-input');
    const $sendBtn = $('#send-btn');

    // Function to append a message to the chat window
    function appendMessage(message, sender) {
        const $msgDiv = $('<div>').addClass('message').addClass(sender + '-message').text(message);
        $chatMessages.append($msgDiv);
        // Scroll to the bottom
        $chatMessages.scrollTop($chatMessages[0].scrollHeight);
    }

    $chatForm.on('submit', function(e) {
        e.preventDefault();
        
        const userPrompt = $userInput.val().trim();
        if (userPrompt === '') {
            return;
        }

        // 1. Display user message
        appendMessage(userPrompt, 'user');
        $userInput.val('');
        $sendBtn.prop('disabled', true).text('Thinking...');

        // 2. Send request to the WordPress AJAX endpoint
        $.ajax({
            url: GeminiChatData.ajax_url,
            type: 'POST',
            data: {
                action: 'gemini_chat',
                nonce: GeminiChatData.nonce,
                prompt: userPrompt,
            },
            success: function(response) {
                if (response.success) {
                    // 3. Display AI response
                    appendMessage(response.data.message, 'bot');
                } else {
                    // Display error message
                    appendMessage('Error: ' + response.data.message, 'bot');
                }
            },
            error: function() {
                appendMessage('A server connection error occurred.', 'bot');
            },
            complete: function() {
                // 4. Re-enable button
                $sendBtn.prop('disabled', false).text('Send');
            }
        });
    });
});