# Google OAuth Setup Guide

This guide explains how to set up Google OAuth for YouTube API access in the TubeMaster Backend.

## Environment Variables

Add the following environment variables to your `.env` file:

```env
# Google OAuth Configuration
GOOGLE_CLIENT_ID=your_google_client_id_here
GOOGLE_CLIENT_SECRET=your_google_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost:3000/auth/callback
```

## Google Cloud Console Setup

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the YouTube Data API v3
4. Go to "Credentials" in the left sidebar
5. Click "Create Credentials" > "OAuth 2.0 Client IDs"
6. Choose "Web application" as the application type
7. Add your redirect URI (e.g., `http://localhost:3000/auth/callback`)
8. Copy the Client ID and Client Secret to your `.env` file

## API Endpoints

### YouTube OAuth Endpoints

-   `POST /api/auth/youtube/exchange` - Exchange authorization code for tokens
-   `POST /api/auth/youtube/refresh` - Refresh access token using refresh token
-   `GET /api/auth/youtube/status` - Check current OAuth status

### Privacy Consent Endpoints

-   `GET /api/user/consent/check` - Check if user has consented to privacy policy
-   `POST /api/user/consent/record` - Record user's privacy consent
-   `POST /api/user/consent/withdraw` - Withdraw privacy consent (GDPR compliance)

## Database Migration

Run the migration to add the required fields to the users table:

```bash
php artisan migrate
```

## Security Notes

-   The `GOOGLE_CLIENT_SECRET` is stored securely server-side
-   OAuth tokens are stored encrypted in the database
-   Privacy consent is tracked with IP address and user agent for compliance
-   Users can withdraw consent at any time (GDPR compliance)

## Frontend Integration

The frontend should:

1. Redirect users to Google OAuth consent screen
2. Handle the authorization code callback
3. Send the code to `/api/auth/youtube/exchange` with the redirect URI
4. Store the returned access token for API calls
5. Use `/api/auth/youtube/refresh` when tokens expire
6. Check consent status before making YouTube API calls
