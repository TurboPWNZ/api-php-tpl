# JWT Authorization Implementation

## Overview
This project now includes JWT (JSON Web Token) based authentication using `firebase/php-jwt` library.

## Installation
The `firebase/php-jwt` package has been installed via Composer.

## Files Created/Modified

### New Files
1. **`src/JwtHelper.php`** - Core JWT token generation and validation
2. **`src/Middleware.php`** - Authentication middleware for protected routes
3. **`src/db/Account.php`** - Added refresh token methods

### Modified Files
1. **`src/config/config.php`** - Added JWT configuration
2. **`src/app/controllers/AuthController.php`** - Updated login to issue JWT tokens
3. **`src/routes/api.php`** - Added protected `/user/profile` route example
4. **`index.php`** - Added JWT initialization
5. **`migrations/m2026_07_28_000003_add_jwt_fields_to_account.php`** - Added database fields

## Configuration

Edit `src/config/config.php` and set a secure JWT secret:

```php
'jwt' => [
    'secret' => 'your-super-secret-jwt-key-change-in-production',
    'issuer' => 'old-mmorpg-api',
    'audience' => 'old-mmorpg-users',
    'ttl' => 3600, // 1 hour
]
```

## API Endpoints

### Public Endpoints (No Auth Required)
- `POST /auth/register` - Register new user
- `POST /auth/login` - Login and receive JWT token
- `GET /auth/verify-email` - Verify email with token

### Protected Endpoints (Bearer Token Required)
- `GET /user/profile` - Get current user profile (requires `Authorization: Bearer TOKEN`)

## Usage Examples

### 1. Register
```bash
curl -X POST http://api.old-mmorpg.com/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'
```

### 2. Login
```bash
curl -X POST http://api.old-mmorpg.com/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'
```

**Response:**
```json
{
  "success": true,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "balance": 0
  }
}
```

### 3. Access Protected Route
```bash
curl -X GET http://api.old-mmorpg.com/user/profile \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

**Response:**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "email": "user@example.com",
    "role": "user"
  }
}
```

## Database Migration

Run migration to add JWT-related fields to the `account` table:

```bash
php artisan migrate
```

This adds:
- `refresh_token` - for long-lived sessions
- `refresh_token_expires_at` - token expiration timestamp

## JWT Token Structure

```json
{
  "iss": "old-mmorpg-api",
  "aud": "old-mmorpg-users",
  "iat": 1689123456,
  "exp": 1689127056,
  "user_id": 1,
  "email": "user@example.com",
  "role": "user"
}
```

## Security Notes

1. **Change the JWT secret** in production - use a long random string
2. Tokens expire after 1 hour by default
3. Refresh tokens are stored in the database and expire after 7 days
4. Always validate tokens on protected endpoints
5. Use HTTPS in production to protect tokens in transit

## Adding More Protected Routes

In `src/routes/api.php`, use the auth middleware:

```php
$r->get('/protected-endpoint', function(\Symfony\Component\HttpFoundation\Request $request) {
    $authResult = \Api\Middleware::auth($request);
    if ($authResult !== true) {
        return $authResult;
    }
    
    $user = $request->attributes->get('user');
    // Your protected code here
});
```

## Logout (Invalidate Token)

To invalidate refresh tokens (logout from all devices):

```php
$account = new \Api\db\Account();
$account->invalidateRefreshTokens($userId);
```
