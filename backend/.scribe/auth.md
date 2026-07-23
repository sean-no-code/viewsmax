# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer vmx_{YOUR_API_KEY}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Authenticate with your ViewsMax API key (`vmx_...`) as a Bearer token: `Authorization: Bearer vmx_...`. Create or rotate it in the ViewsMax app under Settings → AI Assistant Access. Read-only keys can only call GET endpoints, and API keys are limited to the posts/offers/tracking/social/connections surface. Session tokens from `POST /api/login` also work and have full account access.
