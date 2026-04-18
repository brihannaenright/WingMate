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
                    <button id="headerReportBtn" class="report-btn" data-bs-toggle="modal" data-bs-target="#reportModal">
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
                    <textarea id="messageInput" class="message-input" placeholder="Type a message..." rows="1" autocomplete="off"></textarea>
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
                        <!-- General error message -->
                        <div id="reportGeneralError" class="alert alert-wingmate d-none" role="alert"></div>

                        <!-- Flagged message display -->
                        <div id="reportFlaggedMessage" class="alert alert-wingmate d-none mb-3" role="alert">
                            <strong>Reporting message:</strong>
                            <p id="flaggedMessageContent" style="margin-top: 8px; margin-bottom: 0;"></p>
                        </div>

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
                            <div id="reportReasonError" class="field-error d-none"><span class="error-icon">!</span><span id="reportReasonErrorText"></span></div>
                        </div>

                        <!-- Other reason input (hidden by default) -->
                        <div class="mb-3 d-none" id="otherReasonWrapper">
                            <label class="form-label">Specify Reason</label>
                            <input type="text" id="customReason" class="form-control" placeholder="Enter reason">
                            <div id="customReasonError" class="field-error d-none"><span class="error-icon">!</span><span id="customReasonErrorText"></span></div>
                        </div>

                        <!-- Details -->
                        <div class="mb-2">
                            <label class="form-label">Details (max 50 words)</label>
                            <textarea id="reportDetails" class="form-control" rows="4"
                                placeholder="Describe the issue..."></textarea>
                            <small class="text-muted">
                                <span id="wordCount">0</span>/50 words
                            </small>
                            <div id="reportDetailsError" class="field-error d-none"><span class="error-icon">!</span><span id="reportDetailsErrorText"></span></div>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="submit">Submit Report</button>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <!-- Settings Dialog -->
    <div class="modal fade" id="settingsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- General error/success message -->
                    <div id="settingsGeneralMessage" class="alert alert-wingmate d-none" role="alert"></div>

                    <!-- Loading state -->
                    <div id="settingsLoading" class="text-center d-none">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <!-- Settings content -->
                    <div id="settingsContent" class="d-none">
                        <p class="text-muted mb-3" id="settingsManageText">Manage your friendship</p>
                        
                        <!-- Friend/Match Management Buttons -->
                        <div id="friendMatchActions" class="d-none">
                            <!-- Remove Friend / Unmatch Button (context-aware) -->
                            <button type="button" id="removeFriendBtn" class="btn remove-button w-100 mb-2 d-none">
                                Remove as Friend
                            </button>
                            <button type="button" id="unmatchBtn" class="btn remove-button w-100 mb-2 d-none">
                                Unmatch
                            </button>
                            <small class="text-muted d-block mb-3" id="removeFriendText"></small>

                            <button type="button" id="blockUserBtn" class="btn block-button w-100">
                                Block User
                            </button>
                            <small class="text-muted d-block">They won't be able to request your friendship or view your profile</small>
                        </div>

                        <!-- Group Management Buttons -->
                        <div id="groupActions" class="d-none">
                            <button type="button" id="leaveGroupBtn" class="btn remove-button w-100 mb-2">
                                Leave Group
                            </button>
                            <small class="text-muted d-block mb-3">You will no longer be part of this group</small>

                            <button type="button" id="deleteGroupBtn" class="btn remove-button w-100 mb-2 d-none">
                                Delete Group
                            </button>
                            <small class="text-muted d-block mb-3 d-none" id="deleteGroupText">This will permanently delete the group for everyone</small>

                            <button type="button" id="kickUsersBtn" class="btn remove-button w-100 d-none">
                                Kick Users
                            </button>
                            <small class="text-muted d-block d-none" id="kickUsersText">Remove members from the group</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-4 pb-0">
                    <p id="confirmationText" class="text-center"></p>
                </div>
                <div class="modal-footer justify-content-center border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmationConfirmBtn" class="btn remove-button">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999;"></div>

    <!-- Kick Users Modal -->
    <div class="modal fade" id="kickUsersModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Kick Users from Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="kickError" class="alert alert-wingmate d-none" role="alert"></div>
                    <div id="kickLoading" class="text-center d-none">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="kickMembersContainer" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                        <!-- Members list will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmKickBtn" class="btn remove-button">Remove</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize modal once
let confirmationModal = null;

document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.getElementById('confirmationModal');
    if (modalElement) {
        confirmationModal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
        });
    }
});

// Helper function to show confirmation modal
function showConfirmation(message, onConfirm, onCancel = null) {
    if (!confirmationModal) {
        console.error('Confirmation modal not initialized');
        return;
    }
    
    document.getElementById('confirmationText').textContent = message;
    
    const confirmBtn = document.getElementById('confirmationConfirmBtn');
    const cancelBtn = document.querySelector('#confirmationModal .btn-secondary');
    
    // Remove any existing listeners by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    const newCancelBtn = cancelBtn.cloneNode(true);
    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
    
    // Add new confirm listener
    newConfirmBtn.addEventListener('click', function() {
        confirmationModal.hide();
        if (onConfirm) {
            setTimeout(onConfirm, 150);
        }
    });
    
    // Add new cancel listener
    newCancelBtn.addEventListener('click', function() {
        if (onCancel) {
            onCancel();
        }
    });
    
    confirmationModal.show();
}

// Helper function to show toast notifications
function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;
    
    container.appendChild(toast);
    
    if (duration > 0) {
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease-out forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
}

const ChatManager = {
    currentUserId: null,
    currentChatId: null,
    messageForm: null,
    messageInput: null,
    emptyState: null,
    chatContent: null,
    messagePoller: null,
    currentFriendId: null,
    currentMatchId: null,
    currentGroupId: null,
    chatType: 'direct', // 'direct', 'group', or 'match'
    lastMessageCount: 0,
    selectedMessageId: null,
    contextType: 'friends', // 'friends' or 'matches'
    isCreator: false, // For group chats

    // Initialises chat manager with current user ID and sets up event listeners
    init: function(userId, contextType = 'friends') {
        this.currentUserId = userId;
        this.contextType = contextType;
        this.messageForm = document.getElementById('messageForm');
        this.messageInput = document.getElementById('messageInput');
        this.emptyState = document.getElementById('emptyState');
        this.chatContent = document.getElementById('chatContent');

        if (this.messageForm) {
            // Handle enter key to send message (Shift+Enter for new lines)
            this.messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.handleSendMessage(e);
                }
            });
            
            // Handle send button click
            const sendBtn = this.messageForm.querySelector('.btn-send');
            if (sendBtn) {
                sendBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.handleSendMessage(e);
                });
            }
        }
    },

    loadGroupChat: function(groupId, groupName, profilePictureUrl = null, onLoadComplete = null) {
        // Show loading state
        this.emptyState.classList.add('d-none');
        this.chatContent.classList.remove('d-none');
        this.currentGroupId = groupId;
        this.currentFriendId = null;
        this.currentMatchId = null;
        this.chatType = 'group';

        document.getElementById('chatFriendName').textContent = 'Loading...';
        
        // Hide profile picture and report button for groups
        document.querySelector('.chat-header .profile-image-wrapper').classList.add('d-none');
        document.getElementById('headerReportBtn').classList.add('d-none');

        const messagesContainer = document.getElementById('messagesContainer');
        messagesContainer.innerHTML = '<p class="loading">Loading messages...</p>';

        // Stop any existing polling before switching chats
        if (this.messagePoller) {
            clearInterval(this.messagePoller);
            this.messagePoller = null;
        }

        fetch(`/features/chats/chat-api.php?action=get_group_chat&group_id=${groupId}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    messagesContainer.innerHTML = '<p>Error loading chat</p>';
                    if (onLoadComplete) onLoadComplete(false);
                    return;
                }

                this.currentChatId = data.chat_id;
                this.isCreator = data.is_creator;
                document.getElementById('chatFriendName').textContent = groupName;

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

    loadChat: function(friendId, friendName, profilePictureUrl = null, onLoadComplete = null, matchId = null) {
        // Show loading state
        this.emptyState.classList.add('d-none');
        this.chatContent.classList.remove('d-none');
        this.currentFriendId = friendId;
        this.currentMatchId = matchId;
        this.currentGroupId = null;
        this.chatType = matchId ? 'match' : 'direct';
        this.isCreator = false;

        document.getElementById('chatFriendName').textContent = 'Loading...';
        
        // Show profile picture and report button for direct chats/matches
        document.querySelector('.chat-header .profile-image-wrapper').classList.remove('d-none');
        document.getElementById('headerReportBtn').classList.remove('d-none');

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

            if (!data.messages) {
                messagesContainer.innerHTML = '<p class="no-messages">Error loading messages</p>';
                return;
            }

            messagesContainer.innerHTML = '';

            if (data.messages.length === 0) {
                messagesContainer.innerHTML = '<p class="no-messages">No messages yet. Start the conversation!</p>';
                return;
            }

            data.messages.forEach(msg => {
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${msg.sender_id == this.currentUserId ? 'sent' : 'received'}`;

                let senderName = '';
                if (this.chatType === 'group' && msg.sender_id != this.currentUserId) {
                    senderName = `<div class="message-sender-name">${this.escapeHtml(msg.first_name + ' ' + msg.last_name)}</div>`;
                }

                let reportButton = '';
                if (msg.sender_id != this.currentUserId) {
                    reportButton = `<button type="button" class="message-report-btn" data-message-id="${msg.message_id}" data-message-content="${this.escapeHtml(msg.content)}" title="Report this message">
                        <img src="/assets/images/flag-icon.svg" alt="Report">
                    </button>`;
                }

                // Determine receipt status for sent messages
                let receiptStatus = '';
                let timeClass = 'message-time';
                if (msg.sender_id == this.currentUserId) {
                    if (msg.read_at) {
                        receiptStatus = ' • Read';
                        timeClass = 'message-time message-read';
                    } else if (msg.delivered_at) {
                        receiptStatus = ' • Delivered';
                    }
                }

                messageDiv.innerHTML = `
                    ${senderName}
                    <div class="message-wrapper">
                        <div class="message-content">${this.escapeHtml(msg.content)}</div>
                    </div>
                    <div class="message-footer">
                        <span class="${timeClass}">${this.formatTime(msg.sent_at)}${receiptStatus}</span>
                        ${reportButton}
                    </div>
                `;

                messagesContainer.appendChild(messageDiv);
            });

            // Mark all unread received messages as read during polling
            const unreadMessageIds = [];
            data.messages.forEach(msg => {
                if (msg.sender_id != this.currentUserId && msg.read_at === null) {
                    unreadMessageIds.push(msg.message_id);
                }
            });
            
            if (unreadMessageIds.length > 0) {
                this.markMessagesAsRead(unreadMessageIds);
            }

            // Add event listeners to report buttons
            document.querySelectorAll('.message-report-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const messageId = btn.getAttribute('data-message-id');
                    const messageContent = btn.getAttribute('data-message-content');
                    ChatManager.openReportWithMessage(messageId, messageContent);
                });
            });

            // Scroll to bottom to show newest messages
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        })
        .catch(error => console.error('Error loading messages:', error));
    },

    handleSendMessage: function(e) {
        e.preventDefault();
        
        if (!this.currentChatId || this.messageInput.value.trim() === '') return;

        const content = this.messageInput.value.trim();
        
        // Clone and replace textarea to clear browser form history
        const newTextarea = this.messageInput.cloneNode(false);
        newTextarea.id = 'messageInput';
        newTextarea.className = 'message-input';
        newTextarea.setAttribute('placeholder', 'Type a message...');
        newTextarea.setAttribute('rows', '1');
        newTextarea.setAttribute('autocomplete', 'off');
        this.messageInput.parentNode.replaceChild(newTextarea, this.messageInput);
        this.messageInput = newTextarea;
        
        // Reattach event listeners to new textarea
        this.messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.handleSendMessage(e);
            }
        });

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
                showToast('Error: ' + (data.error || 'Failed to send message'), 'error');
                // Restore message only on error
                this.messageInput.value = content;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            this.messageInput.value = content; // Restore message only on error
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
    },

    openReportWithMessage: function(messageId, messageContent) {
        this.selectedMessageId = messageId;
        
        // Show the flagged message in the modal
        const flaggedMessageDiv = document.getElementById('reportFlaggedMessage');
        if (flaggedMessageDiv) {
            flaggedMessageDiv.classList.remove('d-none');
            document.getElementById('flaggedMessageContent').textContent = messageContent;
        }

        // Open the report modal
        const modal = new bootstrap.Modal(document.getElementById('reportModal'));
        modal.show();
    },

    markMessageAsRead: function(messageId) {
        const formData = new FormData();
        formData.append('action', 'mark_message_read');
        formData.append('message_id', messageId);

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .catch(error => console.error('Error marking message as read:', error));
    },

    markMessagesAsRead: function(messageIds) {
        if (messageIds.length === 0) return;

        // Mark all messages as read - next polling cycle will refresh the display
        messageIds.forEach(messageId => {
            const formData = new FormData();
            formData.append('action', 'mark_message_read');
            formData.append('message_id', messageId);

            fetch('/features/chats/chat-api.php', {
                method: 'POST',
                body: formData
            })
            .catch(error => console.error('Error marking message as read:', error));
        });
    }
};

document.addEventListener('DOMContentLoaded', function () {

    const reasonSelect = document.getElementById('reportReason');
    const otherWrapper = document.getElementById('otherReasonWrapper');
    const customReason = document.getElementById('customReason');
    const details = document.getElementById('reportDetails');
    const wordCount = document.getElementById('wordCount');
    const form = document.getElementById('reportForm');
    const reportModal = document.getElementById('reportModal');
    const headerReportBtn = document.getElementById('headerReportBtn');

    // Clear selected message when header report button is clicked
    if (headerReportBtn) {
        headerReportBtn.addEventListener('click', function() {
            ChatManager.selectedMessageId = null;
        });
    }

    // Function to clear all errors
    function clearReportErrors() {
        document.getElementById('reportGeneralError').classList.add('d-none');
        document.getElementById('reportGeneralError').textContent = '';
        
        document.getElementById('reportReasonError').classList.add('d-none');
        document.getElementById('customReasonError').classList.add('d-none');
        document.getElementById('reportDetailsError').classList.add('d-none');
    }

    // Function to display error message
    function showError(elementId, message) {
        const errorElement = document.getElementById(elementId);
        if (errorElement) {
            errorElement.classList.remove('d-none');
            const textElement = errorElement.querySelector('span:last-child');
            if (textElement) {
                textElement.textContent = message;
            }
        }
    }

    // Function to display general error
    function showGeneralError(message) {
        const errorElement = document.getElementById('reportGeneralError');
        errorElement.textContent = message;
        errorElement.classList.remove('d-none');
    }

    // Clear errors when modal opens
    reportModal.addEventListener('show.bs.modal', function () {
        clearReportErrors();
        
        // If no specific message is selected (opening from header button), hide flagged message display
        if (!ChatManager.selectedMessageId) {
            document.getElementById('reportFlaggedMessage').classList.add('d-none');
            document.getElementById('flaggedMessageContent').textContent = '';
        }
    });

    // Reset selected message when modal closes
    reportModal.addEventListener('hide.bs.modal', function () {
        ChatManager.selectedMessageId = null;
    });

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
        // Clear reason error when user selects
        document.getElementById('reportReasonError').classList.add('d-none');
    });

    // Word counter (max 50 words)
    details.addEventListener('input', function () {
        let words = this.value.trim().split(/\s+/).filter(w => w.length > 0);

        if (words.length > 50) {
            this.value = words.slice(0, 50).join(' ');
            words = words.slice(0, 50);
        }

        wordCount.textContent = words.length;
        // Clear details error when user types
        document.getElementById('reportDetailsError').classList.add('d-none');
    });

    // Submit report
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        
        clearReportErrors();

        const reporterId = ChatManager.currentUserId;
        const reportedId = ChatManager.currentFriendId;

        if (!reportedId) {
            showGeneralError('No user selected');
            return;
        }

        const reasonValue = reasonSelect.value === 'other'
            ? customReason.value.trim()
            : reasonSelect.value;

        if (!reasonValue) {
            showError('reportReasonError', 'Please select a reason');
            return;
        }

        const detailsValue = details.value.trim();
        if (!detailsValue) {
            showError('reportDetailsError', 'Please provide details for your report');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'report_user');
        formData.append('reporter_id', reporterId);
        formData.append('reported_id', reportedId);
        formData.append('reason', reasonValue);
        formData.append('details', detailsValue);
        
        if (ChatManager.selectedMessageId) {
            formData.append('message_id', ChatManager.selectedMessageId);
        }

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showGeneralError('Report submitted successfully');
                
                // Reset form
                form.reset();
                wordCount.textContent = '0';
                otherWrapper.classList.add('d-none');
                
                // Reset selected message
                ChatManager.selectedMessageId = null;
                document.getElementById('reportFlaggedMessage').classList.add('d-none');
                document.getElementById('flaggedMessageContent').textContent = '';

                // Close modal after a short delay
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(reportModal);
                    modal.hide();
                }, 1500);
            } else {
                showGeneralError(data.error || 'Failed to submit report');
            }
        })
        .catch(err => {
            console.error(err);
            showGeneralError('Error submitting report. Please try again.');
        });
    });

    // Settings Modal Handlers
    const settingsModal = document.getElementById('settingsModal');
    const removeFriendBtn = document.getElementById('removeFriendBtn');
    const unmatchBtn = document.getElementById('unmatchBtn');
    const leaveGroupBtn = document.getElementById('leaveGroupBtn');
    const deleteGroupBtn = document.getElementById('deleteGroupBtn');
    const kickUsersBtn = document.getElementById('kickUsersBtn');
    const blockUserBtn = document.getElementById('blockUserBtn');
    const settingsGeneralMessage = document.getElementById('settingsGeneralMessage');
    const settingsLoading = document.getElementById('settingsLoading');
    const settingsContent = document.getElementById('settingsContent');
    const settingsManageText = document.getElementById('settingsManageText');
    const removeFriendText = document.getElementById('removeFriendText');
    const friendMatchActions = document.getElementById('friendMatchActions');
    const groupActions = document.getElementById('groupActions');
    const deleteGroupText = document.getElementById('deleteGroupText');
    const kickUsersText = document.getElementById('kickUsersText');

    function showSettingsMessage(message, isError = true) {
        settingsGeneralMessage.textContent = message;
        settingsGeneralMessage.classList.remove('d-none');
        if (isError) {
            settingsGeneralMessage.classList.add('alert-danger');
            settingsGeneralMessage.classList.remove('alert-success');
        } else {
            settingsGeneralMessage.classList.add('alert-success');
            settingsGeneralMessage.classList.remove('alert-danger');
        }
    }

    function showSettingsLoading(show) {
        if (show) {
            settingsLoading.classList.remove('d-none');
            settingsContent.classList.add('d-none');
        } else {
            settingsLoading.classList.add('d-none');
            settingsContent.classList.remove('d-none');
        }
    }

    // Reset settings modal when opening
    settingsModal.addEventListener('show.bs.modal', function () {
        settingsGeneralMessage.classList.add('d-none');
        showSettingsLoading(false);

        if (ChatManager.chatType === 'group') {
            // Show group-specific options
            friendMatchActions.classList.add('d-none');
            groupActions.classList.remove('d-none');
            settingsManageText.textContent = 'Manage group';
            headerReportBtn.classList.add('d-none');

            if (ChatManager.isCreator) {
                deleteGroupBtn.classList.remove('d-none');
                deleteGroupText.classList.remove('d-none');
                kickUsersBtn.classList.remove('d-none');
                kickUsersText.classList.remove('d-none');
            } else {
                deleteGroupBtn.classList.add('d-none');
                deleteGroupText.classList.add('d-none');
                kickUsersBtn.classList.add('d-none');
                kickUsersText.classList.add('d-none');
            }
        } else {
            // Show friend/match options
            groupActions.classList.add('d-none');
            friendMatchActions.classList.remove('d-none');
            headerReportBtn.classList.remove('d-none');

            if (ChatManager.contextType === 'matches') {
                removeFriendBtn.classList.add('d-none');
                unmatchBtn.classList.remove('d-none');
                settingsManageText.textContent = 'Manage your match';
                removeFriendText.textContent = 'You will no longer see this match';
            } else {
                removeFriendBtn.classList.remove('d-none');
                unmatchBtn.classList.add('d-none');
                settingsManageText.textContent = 'Manage your friendship';
                removeFriendText.textContent = 'You will no longer be friends with this user';
            }
        }
    });

    // Remove friend
    removeFriendBtn.addEventListener('click', function () {
        if (!ChatManager.currentFriendId) {
            showSettingsMessage('No user selected');
            return;
        }

        showSettingsLoading(true);

        const formData = new FormData();
        formData.append('action', 'remove_friend');
        formData.append('user_id', ChatManager.currentUserId);
        formData.append('friend_id', ChatManager.currentFriendId);

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSettingsMessage('Friend removed successfully', false);
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(settingsModal);
                    modal.hide();
                    // Reload friends list or navigate away
                    window.location.reload();
                }, 1500);
            } else {
                showSettingsMessage(data.error || 'Failed to remove friend', true);
                showSettingsLoading(false);
            }
        })
        .catch(err => {
            console.error(err);
            showSettingsMessage('Error removing friend', true);
            showSettingsLoading(false);
        });
    });

    // Unmatch (for matches context)
    unmatchBtn.addEventListener('click', function () {
        if (!ChatManager.currentMatchId) {
            showSettingsMessage('No match selected');
            return;
        }

        showSettingsLoading(true);

        const formData = new FormData();
        formData.append('action', 'unmatch');
        formData.append('match_id', ChatManager.currentMatchId);

        fetch('/features/matches/match-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSettingsMessage('Match removed successfully', false);
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(settingsModal);
                    modal.hide();
                    // Reload matches list
                    window.location.reload();
                }, 1500);
            } else {
                showSettingsMessage(data.error || 'Failed to unmatch', true);
                showSettingsLoading(false);
            }
        })
        .catch(err => {
            console.error(err);
            showSettingsMessage('Error unmatching', true);
            showSettingsLoading(false);
        });
    });

    // Block user
    blockUserBtn.addEventListener('click', function () {
        if (!ChatManager.currentFriendId) {
            showSettingsMessage('No user selected');
            return;
        }

        showSettingsLoading(true);

        const formData = new FormData();
        formData.append('action', 'block_user');
        formData.append('user_id', ChatManager.currentUserId);
        formData.append('blocked_id', ChatManager.currentFriendId);

        fetch('/features/chats/chat-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSettingsMessage('User blocked successfully', false);
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(settingsModal);
                    modal.hide();
                    // Reload friends list or navigate away
                    window.location.reload();
                }, 1500);
            } else {
                showSettingsMessage(data.error || 'Failed to block user', true);
                showSettingsLoading(false);
            }
        })
        .catch(err => {
            console.error(err);
            showSettingsMessage('Error blocking user', true);
            showSettingsLoading(false);
        });
    });

    // Leave group
    leaveGroupBtn.addEventListener('click', function () {
        if (!ChatManager.currentGroupId) {
            showSettingsMessage('No group selected');
            return;
        }

        showConfirmation('Are you sure you want to leave this group?', function () {
            showSettingsLoading(true);

            const formData = new FormData();
            formData.append('action', 'leave_group');
            formData.append('group_id', ChatManager.currentGroupId);

            fetch('/features/chats/chat-api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showSettingsMessage('Left group successfully', false);
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(settingsModal);
                        modal.hide();
                        window.location.reload();
                    }, 1500);
                } else {
                    showSettingsMessage(data.error || 'Failed to leave group', true);
                    showSettingsLoading(false);
                }
            })
            .catch(err => {
                console.error(err);
                showSettingsMessage('Error leaving group', true);
                showSettingsLoading(false);
            });
        });
    });

    // Delete group (creator only)
    deleteGroupBtn.addEventListener('click', function () {
        if (!ChatManager.currentGroupId) {
            showSettingsMessage('No group selected');
            return;
        }

        showConfirmation('Are you sure you want to delete this group? This cannot be undone.', function () {
            showSettingsLoading(true);

            const formData = new FormData();
            formData.append('action', 'delete_group');
            formData.append('group_id', ChatManager.currentGroupId);

            fetch('/features/chats/chat-api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error('Network response was not ok: ' + res.status);
                }
                return res.json();
            })
            .then(data => {
                console.log('Delete group response:', data);
                if (data.success) {
                    showSettingsMessage('Group deleted successfully', false);
                    setTimeout(() => {
                        const modal = bootstrap.Modal.getInstance(settingsModal);
                        if (modal) {
                            modal.hide();
                        }
                        window.location.reload();
                    }, 1500);
                } else {
                    showSettingsMessage(data.error || 'Failed to delete group', true);
                    showSettingsLoading(false);
                }
            })
            .catch(err => {
                console.error('Error deleting group:', err);
                showSettingsMessage('Error: ' + err.message, true);
                showSettingsLoading(false);
            });
        });
    });

    // Kick users button
    kickUsersBtn.addEventListener('click', function () {
        if (!ChatManager.currentGroupId) {
            showSettingsMessage('No group selected');
            return;
        }

        // Close settings modal and open kick users modal
        const settingsModalInstance = bootstrap.Modal.getInstance(settingsModal);
        settingsModalInstance.hide();

        // Show loading and fetch members
        const kickModal = new bootstrap.Modal(document.getElementById('kickUsersModal'));
        const kickMembersContainer = document.getElementById('kickMembersContainer');
        const kickLoading = document.getElementById('kickLoading');
        const kickError = document.getElementById('kickError');

        kickLoading.classList.remove('d-none');
        kickMembersContainer.innerHTML = '';
        kickError.classList.add('d-none');

        fetch(`/features/chats/chat-api.php?action=get_group_members&group_id=${ChatManager.currentGroupId}`)
            .then(res => res.json())
            .then(data => {
                kickLoading.classList.add('d-none');

                if (data.error) {
                    kickError.textContent = data.error;
                    kickError.classList.remove('d-none');
                    return;
                }

                if (!data.members || data.members.length === 0) {
                    kickMembersContainer.innerHTML = '<p class="text-muted">No members to remove</p>';
                    return;
                }

                // Render member checkboxes (exclude current user)
                kickMembersContainer.innerHTML = '';
                data.members.forEach(member => {
                    if (member.user_id !== ChatManager.currentUserId) {
                        const memberDiv = document.createElement('div');
                        memberDiv.className = 'form-check mb-2';
                        memberDiv.innerHTML = `
                            <input class="form-check-input member-kick-checkbox" type="checkbox" value="${member.user_id}" id="member${member.user_id}">
                            <label class="form-check-label" for="member${member.user_id}">
                                ${ChatManager.escapeHtml(member.first_name + ' ' + member.last_name)}
                            </label>
                        `;
                        kickMembersContainer.appendChild(memberDiv);
                    }
                });
            })
            .catch(err => {
                console.error(err);
                kickLoading.classList.add('d-none');
                kickError.textContent = 'Error loading members';
                kickError.classList.remove('d-none');
            });

        kickModal.show();
    });

    // Confirm kick users
    document.getElementById('confirmKickBtn').addEventListener('click', function () {
        const selectedMembers = Array.from(document.querySelectorAll('.member-kick-checkbox:checked')).map(cb => parseInt(cb.value));

        if (selectedMembers.length === 0) {
            showToast('Please select at least one member to remove', 'error');
            return;
        }

        showConfirmation('Are you sure you want to remove the selected members?', function () {
            const kickError = document.getElementById('kickError');
            kickError.classList.add('d-none');

            // Kick each selected member
            Promise.all(selectedMembers.map(memberId => {
                const formData = new FormData();
                formData.append('action', 'kick_user');
                formData.append('group_id', ChatManager.currentGroupId);
                formData.append('member_id', memberId);

                return fetch('/features/chats/chat-api.php', {
                    method: 'POST',
                    body: formData
                }).then(res => res.json());
            }))
            .then(results => {
                const hasError = results.some(r => !r.success);
                if (hasError) {
                    kickError.textContent = 'Failed to remove some members';
                    kickError.classList.remove('d-none');
                } else {
                    const kickModal = bootstrap.Modal.getInstance(document.getElementById('kickUsersModal'));
                    kickModal.hide();
                    showToast('Members removed successfully', 'success');
                    window.location.reload();
                }
            })
            .catch(err => {
                console.error(err);
                kickError.textContent = 'Error removing members';
                kickError.classList.remove('d-none');
            });
        });
    });

});
</script>

<?php
}
?>
