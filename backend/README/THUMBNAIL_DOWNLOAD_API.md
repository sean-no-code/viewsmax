# Thumbnail Download API

This document describes the thumbnail download functionality that allows users to download their generated thumbnail files.

## Endpoint

```
GET /api/thumbnails/{id}/download
```

## Authentication

This endpoint requires authentication. Include the Bearer token in the Authorization header:

```
Authorization: Bearer {your-token}
```

## Parameters

- `id` (required): The ID of the thumbnail to download

## Response

### Success (200 OK)

When the thumbnail is successfully downloaded, the response will be the actual file with appropriate headers:

- `Content-Type`: The MIME type of the file (e.g., `image/png`, `image/jpeg`)
- `Content-Disposition`: `attachment; filename=thumbnail_{id}_{original_filename}`
- `Content-Length`: The size of the file in bytes

### Error Responses

#### 202 Accepted
```json
{
    "success": false,
    "message": "Thumbnail is still being generated. Please try again later."
}
```
Returned when the thumbnail is still processing.

#### 404 Not Found
```json
{
    "success": false,
    "message": "Thumbnail not found"
}
```
Returned when:
- The thumbnail doesn't exist
- The thumbnail belongs to another user
- The thumbnail generation failed
- The thumbnail file is not found in storage

#### 500 Internal Server Error
```json
{
    "success": false,
    "message": "Failed to download thumbnail: {error details}"
}
```
Returned when there's an unexpected server error.

## Usage Examples

### cURL
```bash
curl -H "Authorization: Bearer {your-token}" \
     -H "Accept: application/json" \
     -o thumbnail.png \
     "https://your-api.com/api/thumbnails/123/download"
```

### JavaScript (Fetch API)
```javascript
const response = await fetch('/api/thumbnails/123/download', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
    }
});

if (response.ok) {
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'thumbnail.png';
    a.click();
} else {
    const error = await response.json();
    console.error('Download failed:', error.message);
}
```

### PHP (Guzzle)
```php
$response = $client->get('/api/thumbnails/123/download', [
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json'
    ]
]);

if ($response->getStatusCode() === 200) {
    file_put_contents('thumbnail.png', $response->getBody());
} else {
    $error = json_decode($response->getBody(), true);
    echo 'Download failed: ' . $error['message'];
}
```

## Security Notes

- Users can only download their own thumbnails
- The system validates file existence before serving
- Proper MIME type detection is performed
- User-friendly filenames are generated for downloads

## File Storage

Thumbnails are stored in the `public` storage disk under the path:
```
thumbnails/{user_id}/{thumbnail_id}/{filename}
```

The download endpoint handles both relative paths and full URLs stored in the `file_location` field.
