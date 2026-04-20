# Admin API Documentation
 
## Overview
The admin API provides endpoints for administrative operations in the PlaceSales system. These endpoints require authentication and admin privileges.
 
## Authentication
All admin endpoints require:
- **Authentication**: Laravel Sanctum token (`auth:sanctum` middleware)
- **Authorization**: Admin role (`admin` middleware)
 
## Base URL
/api/admin
 
## Endpoints
 
### 1. Get All Users
**GET** `/api/admin/users`
 
Retrieves all users with their associated workspaces.
 
**Response:**
```json
[
    {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "phone": "+1234567890",
        "position": "Developer",
        "department": "IT",
        "image": "profile.jpg",
        "account_status": "active",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "workspaces": [
            {
                "id": 1,
                "name": "Main Workspace",
                "type": "company",
                "created_at": "2024-01-01T00:00:00.000000Z"
            }
        ]
    }
]
```

**Fields:**
- `id`: User ID
- `name`: Full name
- `email`: Email address
- `phone`: Phone number
- `position`: Job position
- `department`: Department name
- `image`: Profile image filename
- `account_status`: Account status (active/inactive)
- `created_at`: Registration date
- `updated_at`: Last update date
- `workspaces`: Array of user's workspaces with basic info

### 2. Get All Workspaces
**GET** /api/admin/workspaces

Retrieves all workspaces with owner information and user count.

**Response:**
```json
[
    {
        "id": 1,
        "name": "Main Workspace",
        "type": "company",
        "description": "Main company workspace",
        "user_id": 1,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "owner": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
]
```

**Fields:**
- `id`: Workspace ID
- `name`: Workspace name
- `type`: Workspace type (company, personal, etc.)
- `description`: Workspace description
- `user_id`: Owner user ID
- `created_at`: Creation date
- `updated_at`: Last update date
- `owner`: Owner user information (id, name, email)

### 3. Get All System Chats
**GET** `/api/admin/chats`
 
Retrieves all system chat rooms with participants, message counts, and latest message information. Only system-type chats are returned.
 
**Response:**
```json
[
    {
        "id": 1,
        "name": "System Announcements",
        "type": "system",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "messages_count": 15,
        "latest_message": {
            "id": 45,
            "body": "System maintenance scheduled",
            "user_id": 1,
            "chat_room_id": 1,
            "created_at": "2024-01-01T12:00:00.000000Z"
        },
        "users": [
            {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com",
                "image": "profile.jpg",
                "pivot": {
                    "is_admin": true,
                    "last_read_message_id": 44,
                    "created_at": "2024-01-01T00:00:00.000000Z"
                }
            }
        ]
    }
]
```

**Fields:**
- `id`: Chat room ID
- `name`: Chat room name
- `type`: Chat room type (always "system" for admin endpoints)
- `created_at`: Creation date
- `updated_at`: Last update date
- `messages_count`: Total number of messages in the chat
- `latest_message`: Most recent message object (null if no messages)
- `users`: Array of participants with pivot data
- `pivot.is_admin`: Whether user is admin in this chat
- `pivot.last_read_message_id`: ID of last read message
- `pivot.created_at`: When user joined the chat

### 4. Get Chat Messages
**GET** `/api/admin/chats/{id}/messages`
 
Retrieves messages from a specific system chat room with pagination. Only system-type chats are accessible.
 
**Parameters:**
- `id` (path, required): Chat room ID
- `per_page` (query, optional): Number of messages per page (default: 50, max: 100)

**Response:**
```json
{
    "chat_room": {
        "id": 1,
        "name": "System Announcements",
        "type": "system",
        "users": [
            {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com",
                "image": "profile.jpg",
                "pivot": {
                    "is_admin": true,
                    "last_read_message_id": 44,
                    "created_at": "2024-01-01T00:00:00.000000Z"
                }
            }
        ]
    },
    "messages": {
        "data": [
            {
                "id": 45,
                "user_id": 1,
                "chat_room_id": 1,
                "body": "System maintenance scheduled",
                "created_at": "2024-01-01T12:00:00.000000Z",
                "updated_at": "2024-01-01T12:00:00.000000Z",
                "user": {
                    "id": 1,
                    "name": "John Doe",
                    "email": "john@example.com"
                }
            }
        ],
        "current_page": 1,
        "per_page": 50,
        "total": 15,
        "last_page": 1
    }
}
```

**Fields:**
- `chat_room`: Chat room information with participants
- `messages`: Paginated message list
- `data`: Array of message objects
- `current_page`: Current page number
- `per_page`: Messages per page
- `total`: Total message count
- `last_page`: Last page number
**Error Cases:**

**404 Not Found:** Returned when chat room doesn't exist or is not a system-type chat
```json
{
    "message": "Chat not found"
}
```

### 5. Send Message to System Chat
**POST** `/api/admin/chats/{id}/messages`
 
Sends a message to a system chat room from technical support. Only system-type chats are allowed.
 
**Parameters:**
- `id` (path, required): Chat room ID
 
**Request Body:**
```json
{
    "body": "Your message text here"
}
```

**Response:**
```json
{
    "id": 46,
    "user_id": 1,
    "chat_room_id": 1,
    "body": "Your message text here",
    "created_at": "2024-01-01T12:30:00.000000Z",
    "updated_at": "2024-01-01T12:30:00.000000Z",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    }
}
```

**Fields**:
- `id`: Message ID
- `user_id`: ID of the user who sent the message
- `chat_room_id`: ID of the chat room
- `body`: Message content
- `created_at`: Message creation time
- `updated_at`: Message update time
- `user`: User information of the sender

**Error Cases:**
**403 Forbidden - Non-system chat:**
```json
{
    "message": "Only system chats are allowed"
}
```

**404 Not Found:**
```json
{
    "message": "Chat not found"
}
```

**422 Validation Error:**
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "body": [
            "The body field is required."
        ]
    }
}
```

### Error Responses
**401 Unauthorized**
```json
{
    "message": "Unauthenticated."
}
```
**403 Forbidden**
```json
{
    "message": "This action is unauthorized."
}
```