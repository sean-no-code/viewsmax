# Slack Error Logging Configuration

This document explains how to configure and use Slack error logging in the ViewsMax backend.

## Configuration

The logging configuration has been updated to support sending error logs to both file and Slack. The following channels are available:

### Error Logging Channels

1. **`error_stack`** - Sends errors to both file and Slack
2. **`error_file`** - Sends errors only to file (`storage/logs/error.log`)
3. **`error_slack`** - Sends errors only to Slack

### Environment Variables

Since this application runs in Docker containers, you need to set environment variables in your `.env` file:

```env
# Slack Webhook URL (required)
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK

# Optional Slack configuration
LOG_SLACK_USERNAME=ViewsMax Error Bot
LOG_SLACK_EMOJI=:warning:
LOG_SLACK_LEVEL=error
```

### Docker Configuration

The Docker Compose file has been updated to include Slack environment variables for all services:
- Main application (`app`)
- Queue workers (`queue-worker-1` through `queue-worker-4`)

After updating your `.env` file, restart your Docker containers:

```bash
docker compose down
docker compose up -d
```

## Usage

### Method 1: Using Default Log::error() (Recommended)

```php
use Illuminate\Support\Facades\Log;

// This will automatically send to both file and Slack
Log::error('Something went wrong!', [
    'user_id' => 123,
    'action' => 'video_processing',
    'error_details' => $exception->getMessage()
]);
```

### Method 2: Using the Error Stack Channel Explicitly

```php
use Illuminate\Support\Facades\Log;

// This will also send to both file and Slack
Log::channel('error_stack')->error('Something went wrong!', [
    'user_id' => 123,
    'action' => 'video_processing',
    'error_details' => $exception->getMessage()
]);
```

### Method 3: Using Laravel's Default Error Handling

To automatically send all errors to Slack, you can modify your exception handler:

```php
// In app/Exceptions/Handler.php
public function report(Throwable $exception)
{
    if ($this->shouldReport($exception)) {
        Log::channel('error_stack')->error($exception->getMessage(), [
            'exception' => $exception,
            'url' => request()->url(),
            'method' => request()->method(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
    
    parent::report($exception);
}
```

### Method 4: Using Different Channels Separately

```php
// Send only to file
Log::channel('error_file')->error('File-only error log');

// Send only to Slack
Log::channel('error_slack')->error('Slack-only error notification');
```

## Setting Up Slack Webhook

1. Go to your Slack workspace
2. Create a new app or use an existing one
3. Go to "Incoming Webhooks" in the app settings
4. Create a new webhook for your desired channel
5. Copy the webhook URL and add it to your `.env` file as `LOG_SLACK_WEBHOOK_URL`

## What Gets Logged

The error logging system captures the following types of errors:

### 422 Validation Errors
- **When**: Form validation fails (missing required fields, invalid data types, etc.)
- **What's logged**:
  - URL and HTTP method
  - User ID (if authenticated)
  - Validation error details
  - Input data (excluding sensitive fields like passwords)
  - IP address and user agent
  - Exception details

### 500 Server Errors
- **When**: Unexpected server errors occur
- **What's logged**:
  - URL and HTTP method
  - User ID (if authenticated)
  - Exception message, code, file, and line
  - Stack trace (in debug mode)
  - IP address and user agent

### Custom Error Logging
You can also manually log errors using:

```php
Log::channel('error_stack')->error('Custom error message', [
    'user_id' => auth()->id(),
    'additional_data' => 'any context you want to include'
]);
```

## Testing

You can test the configuration by running:

```bash
# Send a test error through the configured channel
docker compose exec app php artisan tinker --execute="Log::channel('error_stack')->error('Test error message from ViewsMax');"
```

If the webhook is configured correctly the message appears in the target
Slack channel within a few seconds.

## Troubleshooting

### Issue: Slack messages not being sent

1. **Check environment variables**: Make sure `LOG_SLACK_WEBHOOK_URL` is set in your `.env` file
2. **Restart containers**: After updating `.env`, restart Docker containers
3. **Check webhook URL**: Verify the Slack webhook URL is correct and active
4. **Test webhook directly**: use the curl snippet under "Testing Commands" below

### Issue: Errors only going to file, not Slack

1. **Check log level**: Ensure the error level is `error` or above
2. **Verify channel configuration**: Make sure you're using `error_stack` or `error_slack` channel
3. **Check Docker logs**: Look for any Slack-related errors in container logs

### Testing Commands

```bash
# Check if environment variables are loaded
docker compose exec app env | grep LOG_SLACK

# Test Slack webhook directly
docker compose exec app php -r "
\$url = getenv('LOG_SLACK_WEBHOOK_URL');
if (\$url) {
    \$data = json_encode(['text' => 'Test from Docker - ' . date('Y-m-d H:i:s')]);
    \$ch = curl_init();
    curl_setopt(\$ch, CURLOPT_URL, \$url);
    curl_setopt(\$ch, CURLOPT_POST, 1);
    curl_setopt(\$ch, CURLOPT_POSTFIELDS, \$data);
    curl_setopt(\$ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
    \$result = curl_exec(\$ch);
    \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
    curl_close(\$ch);
    echo 'HTTP Code: ' . \$httpCode . PHP_EOL;
    echo 'Response: ' . \$result . PHP_EOL;
} else {
    echo 'LOG_SLACK_WEBHOOK_URL not set' . PHP_EOL;
}
"
```
