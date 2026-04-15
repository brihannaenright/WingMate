# Friends Feature

The friends feature provides messaging, reporting, and blocking functionality for friend relationships.

## Files

### friends.php

Main page that displays a sidebar with friend contacts and integrates the shared chat interface.

**Key features:**

- Displays list of accepted friends sorted alphabetically
- Search functionality to find and add new friends
- Friend request inbox to accept/reject requests
- Click on a friend to open chat conversation
- Uses the same chat-ui.php component as the matches page
- Sends 'friends' context to ChatManager for context-aware settings

**Database queries:**

- Fetches accepted friendships from `Friendship` table
- Fetches pending friend requests
- Supports searching for users not yet friends

### chat-api.php (shared)

Backend API for chat operations including reporting and blocking.

**Actions:**

- `get_chat_id`: Get or create direct chat between two users
- `get_messages`: Fetch messages for a chat
- `send_message`: Send a message
- `report_user`: Report a user with optional message
- `remove_friend`: Delete a friendship (refactored)
- `block_user`: Block a user and remove friendship

### chats-sidebar.css

Styling for the friends sidebar list.

**Features:**

- Pink (#C30E59) background with white chat list cards
- Profile picture circles and name display
- Hover states for interactivity
- Responsive design for mobile

## Refactoring for Reusability

### Changes Made to Support Multiple Contexts

The chat-ui.php component was refactored to support both friends and matches contexts while maintaining backward compatibility.

#### ChatManager Enhancements

- Added `contextType` property: 'friends' or 'matches'
- Added `currentMatchId` property: tracks match ID when in matches context
- Updated `init(userId, contextType)`: now accepts optional context parameter
- Updated `loadChat(friendId, name, pic, callback, matchId)`: now accepts optional matchId

#### Settings Modal Refactoring

**Before:** Only showed "Remove as Friend" button
**After:** Dynamically shows context-appropriate buttons

- In 'friends' context: Shows "Remove as Friend"
- In 'matches' context: Shows "Unmatch"
- Management text updates to "Manage your friendship" or "Manage your match"
- Button descriptions change based on context

#### Implementation Details

```javascript
// Show/hide buttons based on context
if (ChatManager.contextType === "matches") {
  removeFriendBtn.classList.add("d-none");
  unmatchBtn.classList.remove("d-none");
} else {
  removeFriendBtn.classList.remove("d-none");
  unmatchBtn.classList.add("d-none");
}
```

### File Structure

```
friends/
├── friends.php              (Friend list & chat interface)
├── chats-sidebar.css        (Sidebar styling)
└── README.md               (This file)

matches/
├── matches.php              (Match list & chat interface)
├── match-api.php            (Match-specific API)
├── matches-sidebar.css      (Sidebar styling)
└── README.md

chats/ (Shared component)
├── chat-ui.php              (Refactored for both contexts)
├── chat-api.php             (Shared API for friends & matches)
└── chats.css               (Message styling)
```

## Component Interaction Flow

### Friends Page

1. User visits /features/friends/friends.php
2. PHP queries Friendship table and User_Profile for accepted friends
3. JavaScript initializes: `ChatManager.init(userId, 'friends')`
4. User clicks friend card
5. JavaScript calls: `ChatManager.loadChat(friendId, name, pic)`
6. Chat-ui loads messages
7. User opens settings modal
8. Modal shows "Remove as Friend" button (context = 'friends')

### Matches Page

1. User visits /features/matches/matches.php
2. PHP queries Matches table and User_Profile for matches
3. JavaScript initializes: `ChatManager.init(userId, 'matches')`
4. User clicks match card
5. JavaScript calls: `ChatManager.loadChat(userId, name, pic, null, matchId)`
6. Chat-ui loads messages
7. User opens settings modal
8. Modal shows "Unmatch" button (context = 'matches')

## Report & Block Functionality

Both friends and matches share the same reporting and blocking system via chat-api.php:

- **Report User**: Any chat participant can report harassment, spam, inappropriate content, etc.
- **Block User**: Removes friendship/match and prevents future contact
- **Flag Message**: When reporting, users can flag a specific message as evidence

## Database Schema

### Friendship Table

```sql
CREATE TABLE Friendship (
    friendship_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id),
    FOREIGN KEY (friend_id) REFERENCES Users(user_id)
);
```

### Matches Table (referenced by matches feature)

```sql
CREATE TABLE Matches (
    match_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id_1 INT NOT NULL,
    user_id_2 INT NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id_1) REFERENCES Users(user_id),
    FOREIGN KEY (user_id_2) REFERENCES Users(user_id)
);
```

## Usage Example

Initialize ChatManager in friends context:

```javascript
ChatManager.init(<?php echo $current_user_id; ?>, 'friends');
ChatManager.loadChat(friendId, friendName, profilePic);
```

Initialize ChatManager in matches context:

```javascript
ChatManager.init(<?php echo $current_user_id; ?>, 'matches');
ChatManager.loadChat(matchUserId, matchName, profilePic, null, matchId);
```

## Future Enhancements

- Friend suggestion algorithm based on interests/location
- Mutual friends indicator
- Friend activity status (online/offline/away)
- Friend list categories/groups
- Friendship anniversary notifications
