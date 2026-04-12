<?php
/**
 * Chat UI Component
 * Include this file in any page where you want to display the chat interface
 * 
 * Requirements:
 * - Must be included after nav-header.php
 * - Requires currentUserId to be available in JavaScript (set before including this file)
 * - Requires Bootstrap 5 to be available
 */

// Only include if not already included
if (!defined('CHAT_UI_INCLUDED')) {
    define('CHAT_UI_INCLUDED', true);
?>
<link rel="stylesheet" href="/features/chats/chats.css">

<div id="chatContainer" class="chat-container">
    <div id="emptyState" class="chat-empty-state">
        <div class="empty-state-content d-flex flex-column align-items-center justify-content-center">
            <img src="/assets/images/chat-bubble.svg" alt="message-bubble" class="empty-state-icon">
            <h1>Select a conversation</h1>
            <p>Click on a contact to start messaging</p>
        </div>
    </div>
    <div id="chatContent" class="chat-content" style="display: none;">
        <div class="chat-header">
            <h3 id="chatFriendName"></h3>
        </div>
        <div id="messagesContainer" class="messages-container">
            <!-- Messages will be loaded here -->
        </div>
        <div class="chat-input-area">
            <form id="messageForm" class="message-form">
                <textarea id="messageInput" class="message-input" placeholder="Type a message..." rows="1"></textarea>
                <button type="submit" class="btn-send">Send</button>
            </form>
        </div>
    </div>
</div>

<script>
const ChatManager = {
    currentUserId: null,
    currentChatId: null,
    messageForm: null,
    messageInput: null,
    emptyState: null,
    chatContent: null,

    init: function(userId) {
        this.currentUserId = userId;
        this.messageForm = document.getElementById('messageForm');
        this.messageInput = document.getElementById('messageInput');
        this.emptyState = document.getElementById('emptyState');
        this.chatContent = document.getElementById('chatContent');

        if (this.messageForm) {
            this.messageForm.addEventListener('submit', (e) => this.handleSendMessage(e));
        }
    },

    loadChat: function(friendId, friendName, onLoadComplete = null) {
        // Show loading state
        this.emptyState.style.display = 'none';
        this.chatContent.style.display = 'flex';
        document.getElementById('chatFriendName').textContent = 'Loading...';
        document.getElementById('messagesContainer').innerHTML = '<p>Loading messages...</p>';

        // Get chat ID
        fetch(`/features/chats/chat-api.php?action=get_chat_id&friend_id=${friendId}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('messagesContainer').innerHTML = '<p>Error loading chat</p>';
                    if (onLoadComplete) onLoadComplete(false);
                    return;
                }
                this.currentChatId = data.chat_id;
                document.getElementById('chatFriendName').textContent = friendName;
                this.loadMessages();
                if (onLoadComplete) onLoadComplete(true);
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('messagesContainer').innerHTML = '<p>Error loading chat</p>';
                if (onLoadComplete) onLoadComplete(false);
            });
    },

    loadMessages: function() {
        if (!this.currentChatId) return;

        fetch(`/features/chats/chat-api.php?action=get_messages&chat_id=${this.currentChatId}`)
            .then(response => response.json())
            .then(data => {
                const messagesContainer = document.getElementById('messagesContainer');
                messagesContainer.innerHTML = '';

                if (data.messages.length === 0) {
                    messagesContainer.innerHTML = '<p class="no-messages">No messages yet. Start the conversation!</p>';
                    return;
                }

                data.messages.forEach(msg => {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${msg.sender_id == this.currentUserId ? 'sent' : 'received'}`;
                    messageDiv.innerHTML = `
                        <div class="message-content">${this.escapeHtml(msg.content)}</div>
                        <span class="message-time">${this.formatTime(msg.sent_at)}</span>
                    `;
                    messagesContainer.appendChild(messageDiv);
                });

                // Scroll to bottom
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            })
            .catch(error => console.error('Error loading messages:', error));
    },

    handleSendMessage: function(e) {
        e.preventDefault();
        
        if (!this.currentChatId || this.messageInput.value.trim() === '') return;

        const content = this.messageInput.value.trim();
        this.messageInput.value = '';

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('chat_id', this.currentChatId);
        formData.append('content', content);

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.loadMessages();
            } else {
                alert('Error: ' + (data.error || 'Failed to send message'));
                this.messageInput.value = content; // Restore message
            }
        })
        .catch(error => {
            console.error('Error:', error);
            this.messageInput.value = content; // Restore message
        });
    },

    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    formatTime: function(timestamp) {
        const date = new Date(timestamp);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        if (date.toDateString() === today.toDateString()) {
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Yesterday ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        } else {
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
    }
};
</script>

<?php
}
?>
