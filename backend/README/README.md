# TubeMaster Backend Documentation

Welcome to the TubeMaster Backend API documentation. This Laravel-based backend provides comprehensive thumbnail generation and management services for video content creators.

## 📋 Table of Contents

- [Thumbnail Services](#thumbnail-services)
  - [Service Overview](#service-overview)
  - [Configuration](#configuration)
  - [API Key Setup](#api-key-setup)
  - [Adding New Services](#adding-new-services)
- [Thumbnail Download API](#thumbnail-download-api)
  - [Endpoint Details](#endpoint-details)
  - [Authentication](#authentication)
  - [Response Codes](#response-codes)
  - [Usage Examples](#usage-examples)
  - [Security Notes](#security-notes)
- [Email Setup Guide](#email-setup-guide)
  - [Brevo Integration](#brevo-integration)
  - [Configuration Steps](#configuration-steps)
  - [Testing Email Functionality](#testing-email-functionality)
- [Google OAuth Setup](#google-oauth-setup)
  - [Environment Configuration](#environment-configuration)
  - [YouTube API Access](#youtube-api-access)
  - [OAuth Flow Implementation](#oauth-flow-implementation)

---

## Thumbnail Services

### Service Overview

The TubeMaster Backend supports multiple thumbnail generation services that can be easily swapped based on your needs and preferences.

**Current Status:**
- ✅ **OpenAI (DALL-E)**: Fully functional
- ✅ **Google Gemini**: Fully functional via Google AI Studio

### Configuration

You can switch between services using three different methods:

1. **Environment Variable (Recommended)**
2. **Configuration File**
3. **Direct Binding (Advanced)**

### API Key Setup

Detailed setup instructions for both OpenAI and Google Gemini services.

### Adding New Services

Step-by-step guide for integrating additional thumbnail generation services.

**[📖 Read Full Thumbnail Services Documentation](./THUMBNAIL_SERVICES.md)**

---

## Thumbnail Download API

### Endpoint Details

```
GET /api/thumbnails/{id}/download
```

### Authentication

All download endpoints require Bearer token authentication.

### Response Codes

- **200 OK**: Successful file download
- **202 Accepted**: Thumbnail still processing
- **404 Not Found**: Thumbnail not found or access denied
- **500 Internal Server Error**: Server error

### Usage Examples

Complete examples in cURL, JavaScript, and PHP for downloading thumbnails.

### Security Notes

- User isolation (users can only download their own thumbnails)
- File validation and MIME type detection
- Secure file storage structure

**[📖 Read Full Download API Documentation](./THUMBNAIL_DOWNLOAD_API.md)**

---

## Email Setup Guide

### Brevo Integration

The TubeMaster Backend integrates with Brevo (formerly Sendinblue) for reliable email delivery, particularly for password reset functionality.

### Configuration Steps

Step-by-step guide to configure Brevo SMTP settings and API credentials.

### Testing Email Functionality

Instructions for testing the forgot password email flow and troubleshooting common issues.

**[📖 Read Full Email Setup Guide](./EMAIL_SETUP_GUIDE.md)**

---

## Google OAuth Setup

### Environment Configuration

Complete guide for setting up Google OAuth credentials and environment variables.

### YouTube API Access

Instructions for enabling YouTube API access and configuring OAuth scopes.

### OAuth Flow Implementation

Details about the OAuth flow implementation and token management.

**[📖 Read Full Google OAuth Setup Guide](./GOOGLE_OAUTH_SETUP.md)**

---

## Quick Start

### 1. Generate a Thumbnail

```bash
curl -X POST "https://your-api.com/api/thumbnails" \
  -H "Authorization: Bearer {your-token}" \
  -H "Content-Type: application/json" \
  -d '{"description": "A video about cooking pasta"}'
```

### 2. Check Thumbnail Status

```bash
curl -X GET "https://your-api.com/api/thumbnails/{id}/status" \
  -H "Authorization: Bearer {your-token}"
```

### 3. Download Thumbnail

```bash
curl -X GET "https://your-api.com/api/thumbnails/{id}/download" \
  -H "Authorization: Bearer {your-token}" \
  -o thumbnail.png
```

## 🔧 Technical Details

- **Framework**: Laravel 11
- **Storage**: Public disk with organized folder structure
- **Authentication**: Laravel Sanctum
- **Queue Processing**: Asynchronous thumbnail generation
- **File Formats**: PNG, JPEG (auto-detected)

## 📁 File Structure

```
thumbnails/
├── {user_id}/
│   └── {thumbnail_id}/
│       └── {filename}.{ext}
```

## 🛡️ Security Features

- User-based access control
- File existence validation
- Proper MIME type detection
- Secure API key management
- Comprehensive error handling

## 📞 Support

For technical support or questions about the API, please refer to the detailed documentation linked above or contact the development team.

---

*Last updated: September 2024*
