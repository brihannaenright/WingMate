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

<div class="chat d-flex flex-column">
    <div id="emptyState" class="chat-empty-state">
        <div class="empty-state-content d-flex flex-column align-items-center justify-content-center">
            <p>Select a conversation</p>
            <p>Click on a contact to start messaging</p>
        </div>
    </div>
    <div id="chatContent" class="chat-content d-none flex-column">
        <div class="chat-container d-flex flex-column">
            <div class="chat-header d-flex flex-row">
                <div class="header-info d-flex flex-row gap-3">
                    <div class="profile-image-wrapper">
                        <img id="chatProfilePicture" class="profile-pic-header" src="" alt="Profile">
                    </div>
                    <h3 id="chatFriendName"></h3>
                </div>
                <div class="report-settings gap-3">
                    <button class="report-btn" data-bs-toggle="modal" data-bs-target="#reportModal">
                            <img src="/assets/images/flag-icon.svg" alt="Report" title="Report this user">
                    </button>
                    <button class="settings-btn" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <img src="/assets/images/settings-icon.svg" alt="Settings" title="Settings">
                    </button>
                </div>
            </div>
            <div id="messagesContainer" class="messages-container d-flex flex-column">
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

    <!-- Report Dialog -->
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <form id="reportForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Report User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <!-- Reason dropdown -->
                        <div class="mb-3">
                            <label class="form-label">Reason</label>
                            <select id="reportReason" class="form-select" required>
                                <option value="">Select a reason</option>
                                <option value="harassment">Harassment</option>
                                <option value="spam">Spam</option>
                                <option value="inappropriate_content">Inappropriate Content</option>
                                <option value="impersonation">Impersonation</option>
                                <option value="scam_fraud">Scam / Fraud</option>
                                <option value="hate_speech">Hate Speech</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <!-- Other reason input (hidden by default) -->
                        <div class="mb-3 d-none" id="otherReasonWrapper">
                            <label class="form-label">Specify Reason</label>
                            <input type="text" id="customReason" class="form-control" placeholder="Enter reason">
                        </div>

                        <!-- Details -->
                        <div class="mb-2">
                            <label class="form-label">Details (max 200 words)</label>
                            <textarea id="reportDetails" class="form-control" rows="4"
                                placeholder="Describe the issue..."></textarea>
                            <small class="text-muted">
                                <span id="wordCount">0</span>/200 words
                            </small>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="submit">Submit Report</button>
                    </div>

                </form>

            </div>
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
    messagePoller: null,
    currentFriendId: null,
    lastMessageCount: 0,

    // Initialises chat manager with current user ID and sets up event listeners
    init: function(userId) {
        this.currentUserId = userId;
        this.messageForm = document.getElementById('messageForm');
        this.messageInput = document.getElementById('messageInput');
        this.emptyState = document.getElementById('emptyState');
        this.chatContent = document.getElementById('chatContent');

        if (this.messageForm) {
            // Handle message form submission
            this.messageForm.addEventListener('submit', (e) => this.handleSendMessage(e));
        }
    },

    loadChat: function(friendId, friendName, profilePictureUrl = null, onLoadComplete = null) {
        // Show loading state
        this.emptyState.classList.add('d-none');
        this.chatContent.classList.remove('d-none');
        this.currentFriendId = friendId;

        document.getElementById('chatFriendName').textContent = 'Loading...';

        if (profilePictureUrl) {
            document.getElementById('chatProfilePicture').src = profilePictureUrl;
        }

        const messagesContainer = document.getElementById('messagesContainer');
        messagesContainer.innerHTML = '<p class="loading">Loading messages...</p>';

        // Stop any existing polling before switching chats
        if (this.messagePoller) {
            clearInterval(this.messagePoller);
            this.messagePoller = null;
        }

        fetch(`/features/chats/chat-api.php?action=get_chat_id&friend_id=${friendId}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    messagesContainer.innerHTML = '<p>Error loading chat</p>';
                    if (onLoadComplete) onLoadComplete(false);
                    return;
                }

                this.currentChatId = data.chat_id;
                document.getElementById('chatFriendName').textContent = friendName;

                if (!profilePictureUrl && data.profile_picture) {
                    document.getElementById('chatProfilePicture').src = data.profile_picture;
                }

                // Load messages immediately
                this.loadMessages();

                //POLLING (only when tab is active)
                this.messagePoller = setInterval(() => {
                    if (!this.currentChatId) return;

                    // Only poll if user is actively viewing the tab
                    if (document.hidden) return;

                    this.loadMessages();
                }, 3000);

                if (onLoadComplete) onLoadComplete(true);
            })
            .catch(error => {
                console.error('Error:', error);
                messagesContainer.innerHTML = '<p>Error loading chat</p>';
                if (onLoadComplete) onLoadComplete(false);
            });
    },

    loadMessages: function() {
    if (!this.currentChatId) return;

    fetch(`/features/chats/chat-api.php?action=get_messages&chat_id=${this.currentChatId}`)
        .then(response => response.json())
        .then(data => {
            const messagesContainer = document.getElementById('messagesContainer');

            if (!data.messages) return;

            // Optional optimization: avoid full redraw if nothing changed
            if (this.lastMessageCount === data.messages.length) {
                return;
            }

            this.lastMessageCount = data.messages.length;

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

            // Auto-scroll only if user is near bottom (prevents scroll fighting)
            const isNearBottom =
                messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;

            if (isNearBottom) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
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
        const date = new Date(timestamp + 'Z');
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

document.addEventListener('DOMContentLoaded', function () {

    const reasonSelect = document.getElementById('reportReason');
    const otherWrapper = document.getElementById('otherReasonWrapper');
    const customReason = document.getElementById('customReason');
    const details = document.getElementById('reportDetails');
    const wordCount = document.getElementById('wordCount');
    const form = document.getElementById('reportForm');

    // Show/hide "Other"
    reasonSelect.addEventListener('change', function () {
        if (this.value === 'other') {
            otherWrapper.classList.remove('d-none');
            customReason.setAttribute('required', 'required');
        } else {
            otherWrapper.classList.add('d-none');
            customReason.removeAttribute('required');
            customReason.value = '';
        }
    });

    // Word counter (max 200 words)
    details.addEventListener('input', function () {
        let words = this.value.trim().split(/\s+/).filter(w => w.length > 0);

        if (words.length > 200) {
            this.value = words.slice(0, 200).join(' ');
            words = words.slice(0, 200);
        }

        wordCount.textContent = words.length;
    });

    // Submit report
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const reporterId = ChatManager.currentUserId;
        const reportedId = ChatManager.currentFriendId;

        if (!reportedId) {
            alert('No user selected');
            return;
        }

        const reasonValue = reasonSelect.value === 'other'
            ? customReason.value.trim()
            : reasonSelect.value;

        if (!reasonValue) {
            alert('Please select a reason');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'report_user');
        formData.append('reporter_id', reporterId);
        formData.append('reported_id', reportedId);
        formData.append('reason', reasonValue);
        formData.append('details', details.value.trim());

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Report submitted successfully');
                form.reset();
                wordCount.textContent = '0';
                otherWrapper.classList.add('d-none');

                const modal = bootstrap.Modal.getInstance(document.getElementById('reportModal'));
                modal.hide();
            } else {
                alert(data.error || 'Failed to submit report');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error submitting report');
        });
    });

});
</script>

<?php
}
?>
