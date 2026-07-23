# Thumbnail Services

This project now supports multiple thumbnail generation services that can be easily swapped.

## Current Status

- ✅ **OpenAI (DALL-E)**: Fully functional
- ✅ **Google Gemini**: Fully functional via Google AI Studio (gemini-2.5-flash-image-preview)

## Available Services

### 1. OpenAI (DALL-E)
- **Service Class**: `OpenAIService`
- **API**: DALL-E 3
- **Configuration**: Set in `config/services.php` under `openai`
- **Environment Variables**:
  - `OPENAI_API_KEY`
  - `OPENAI_IMAGE_API_URL` (optional, defaults to DALL-E API)

### 2. Google Gemini (Image Generation)
- **Service Class**: `GeminiService`
- **API**: Gemini 2.5 Flash Image Preview via Google AI Studio
- **Configuration**: Set in `config/services.php` under `gemini`
- **Environment Variables**:
  - `GEMINI_API_KEY` (Google AI Studio API key)
  - `GEMINI_API_URL` (optional, defaults to Google AI Studio API)
  - `GEMINI_MODEL` (optional, defaults to gemini-2.5-flash-image-preview)
- **Features**: High-quality image generation with safety filters

## How to Switch Services

### Method 1: Environment Variable (Recommended)
Set the `THUMBNAIL_SERVICE` environment variable in your `.env` file:

```env
# Use OpenAI (default)
THUMBNAIL_SERVICE=openai

# Use Google Imagen
THUMBNAIL_SERVICE=gemini
```

### Method 2: Configuration File
Update `config/services.php`:

```php
'thumbnail' => [
    'default_service' => 'gemini', // Change from 'openai' to 'gemini' (Imagen)
],
```

### Method 3: Direct Binding (Advanced)
In `app/Providers/ThumbnailServiceProvider.php`, modify the binding:

```php
$this->app->bind(ThumbnailServiceInterface::class, function ($app) {
    return new GeminiService(); // Force Imagen
    // or
    return new OpenAIService(); // Force OpenAI
});
```

## Usage

The `ThumbnailHelper` class automatically uses the configured service:

```php
use App\Services\ThumbnailHelper;

// This will use whatever service is configured
$thumbnailHelper = app(ThumbnailHelper::class);
$imagePaths = $thumbnailHelper->generateThumbnails($description, $userId, $thumbnailId);
```

## Service Interface

All thumbnail services implement the `ThumbnailServiceInterface`:

```php
interface ThumbnailServiceInterface
{
    public function generateThumbnailImage(string $description): string;
}
```

## Adding New Services

To add a new thumbnail service:

1. Create a new service class that implements `ThumbnailServiceInterface`
2. Add configuration to `config/services.php`
3. Update `ThumbnailServiceProvider` to include the new service
4. Set the service as default via environment variable or configuration

## Error Handling

Both services include comprehensive error handling and logging. Check the Laravel logs for detailed error information if thumbnail generation fails.

## API Key Setup

### OpenAI
1. Get API key from [OpenAI Platform](https://platform.openai.com/)
2. Add to `.env`: `OPENAI_API_KEY=your-key-here`

### Google Gemini (AI Studio)
1. Go to [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Sign in with your Google account
3. Create a new API key
4. Add to `.env`:
   ```
   GEMINI_API_KEY=your-api-key-here
   GEMINI_MODEL=gemini-2.5-flash-image-preview
   ```
