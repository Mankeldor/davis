# Create User App Key

Creates an app key that can log in over DAV as one specific user, without that user's password.

The scope of the key is always exactly the user in the URL: a scope sent in the body is ignored, and
a key for all users (`*`) or for an administrator cannot be created over the API. Use the dashboard
or the `davis:app-key:create` command for those.

The secret is only returned in this response. Davis stores a hash of it and cannot show it again.

This endpoint is only available when both `API_USER_MANAGEMENT_ENABLED` and `APP_KEYS_ENABLED` are
set to `true`.

**URL** : `/api/v1/users/:user_id/app_keys/create`

**Method** : `POST`

**Auth required** : YES

**Params constraints**

```
:user_id -> "[user id as an int]",
```

**Request Body constraints**

```json
{
	"name": "[string: a name for the key, max 255 chars]",
	"expires_at": "[string: ISO 8601 date and time in the future, optional. Without it the key does not expire]"
}
```

**URL example**

```
/api/v1/users/5/app_keys/create
```

**Body example**

```json
{
	"name": "Phone",
	"expires_at": "2027-01-23T00:00:00+01:00"
}
```

## Success Response

**Code** : `200 OK`

**Content examples**

The response carries a `Cache-Control: no-store` header. `basic_username` is the username a DAV
client sends, with the secret as its password.

```json
{
	"status": "success",
	"data": {
		"app_id": "app_0123456789abcdef01234567",
		"secret": "[64 hexadecimal characters]",
		"name": "Phone",
		"username": "jdoe",
		"basic_username": "jdoe*app_0123456789abcdef01234567",
		"expires_at": "2027-01-23T00:00:00+01:00"
	},
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

## Error Response

**Condition** : If 'X-Davis-API-Token' is not present or mismatched in headers.

**Code** : `401 UNAUTHORIZED`

**Content** :

```json
{
	"status": "error",
	"message": "Missing X-Davis-API-Token header",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

or

```json
{
	"status": "error",
	"message": "Invalid X-Davis-API-Token header",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If `API_USER_MANAGEMENT_ENABLED` is not `true`.

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "User management over the API is disabled",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If `APP_KEYS_ENABLED` is not `true`.

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "App keys are disabled",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If user is not found.

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "User Not Found",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the user has no principal.

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "Principal Not Found",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the user is an administrator.

**Code** : `403 FORBIDDEN`

**Content** :

```json
{
	"status": "error",
	"message": "App Keys for Admin Users Cannot Be Created Over the API",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the username of the user is `*`, which a scope reads as "all users".

**Code** : `403 FORBIDDEN`

**Content** :

```json
{
	"status": "error",
	"message": "App Keys for This Username Cannot Be Created Over the API",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If request body contains invalid JSON.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid JSON",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'name' is missing, empty or too long.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Name",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'expires_at' is not an ISO 8601 date and time, or is not in the future.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Expiry Date",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the key could not be saved.

**Code** : `500 INTERNAL SERVER ERROR`

**Content** :

```json
{
	"status": "error",
	"message": "Failed to Create App Key",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```
