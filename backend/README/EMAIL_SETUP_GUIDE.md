# Email Setup Guide for Brevo Integration

## Overview
This guide will help you configure and test the forgot password email functionality with Brevo (formerly Sendinblue).

## 1. Brevo Configuration

### Get Your Brevo Credentials
1. Log in to your Brevo account at https://app.brevo.com
2. Go to **Settings** → **SMTP & API**
3. Create a new SMTP key or use an existing one
4. Note down:
   - **SMTP Server**: `smtp-relay.brevo.com`
   - **Port**: `587`
   - **Username**: Your Brevo login email
   - **Password**: Your SMTP key (not your account password)

### Environment Variables
Add these to your `.env` file:

```env
# Mail Configuration
MAIL_MAILER=brevo
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=your-brevo-email@example.com
MAIL_PASSWORD=your-brevo-smtp-key
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Your App Name"

# Frontend URL for reset links
FRONTEND_URL=http://localhost:3000
```

## 2. Testing Email Functionality

### Test Command
Use the built-in test command to verify email configuration:

```bash
# Test with log driver (saves to storage/logs/laravel.log)
php artisan test:email your-email@example.com --mailer=log

# Test with Brevo
php artisan test:email your-email@example.com --mailer=brevo

# Test with default mailer
php artisan test:email your-email@example.com
```

### API Testing
Test the forgot password endpoint:

```bash
# Request password reset
curl -X POST http://localhost:8000/api/forgot-password \
  -H "Content-Type: application/json" \
  -d '{"email": "your-email@example.com"}'

# Reset password (use token from email)
curl -X POST http://localhost:8000/api/reset-password \
  -H "Content-Type: application/json" \
  -d '{
    "email": "your-email@example.com",
    "token": "token-from-email",
    "password": "newpassword",
    "password_confirmation": "newpassword"
  }'
```

## 3. Logging and Debugging

### Check Logs
All email activities are logged to `storage/logs/laravel.log`:

```bash
# View recent logs
tail -f storage/logs/laravel.log

# Search for email-related logs
grep -i "password reset\|email" storage/logs/laravel.log
```

### Log Levels
- **INFO**: Normal operations (token creation, email sending)
- **WARNING**: Validation failures, invalid tokens
- **ERROR**: Email sending failures, exceptions

### Common Issues and Solutions

#### 1. Authentication Failed
```
Error: Authentication failed
```
**Solution**: Check your Brevo credentials in `.env` file

#### 2. Connection Timeout
```
Error: Connection could not be established
```
**Solution**: 
- Verify SMTP settings
- Check firewall/network restrictions
- Try different port (465 with SSL instead of 587 with TLS)

#### 3. Email Not Received
**Check**:
- Spam/junk folder
- Brevo delivery logs in your dashboard
- Email address validity
- Sender reputation

#### 4. Template Not Found
```
Error: View [emails.password-reset] not found
```
**Solution**: Ensure the email template exists at `resources/views/emails/password-reset.blade.php`

## 4. Email Template Customization

The email template is located at `resources/views/emails/password-reset.blade.php`.

### Variables Available:
- `$token`: The reset token
- `$email`: User's email address
- `$resetUrl`: Complete reset URL with token and email

### Customization:
- Update styling in the `<style>` section
- Modify content in the HTML body
- Change the reset URL format if needed

## 5. Security Features

- **Token Expiration**: 60 minutes
- **Secure Tokens**: 64-character random strings
- **Hashed Storage**: Tokens are hashed before database storage
- **One-time Use**: Tokens are deleted after successful reset
- **Email Enumeration Protection**: Same response for valid/invalid emails

## 6. Production Considerations

### Environment Variables
Ensure all sensitive data is in environment variables, not in code.

### Rate Limiting
Consider adding rate limiting to prevent abuse:
```php
// In routes/api.php
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1'); // 5 attempts per minute
```

### Monitoring
- Monitor email delivery rates
- Set up alerts for failed email sends
- Track password reset success rates

## 7. Troubleshooting Commands

```bash
# Clear configuration cache
php artisan config:clear

# Clear all caches
php artisan cache:clear

# Check mail configuration
php artisan tinker
>>> config('mail')

# Test specific mailer
php artisan tinker
>>> Mail::mailer('brevo')->to('test@example.com')->send(new \App\Mail\PasswordResetMail('test-token', 'test@example.com'));
```

## 8. Next Steps

1. Configure your Brevo credentials
2. Test with the provided commands
3. Check logs for any issues
4. Customize the email template as needed
5. Test the complete flow from forgot password to reset

For any issues, check the logs first - they contain detailed information about what's happening during the email sending process.














