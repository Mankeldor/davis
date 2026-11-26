# Revoke User App Key

Revokes an app key of a specific user. A revoked key no longer logs in. It is not deleted: it stays
in the list of app keys with its revocation date.

Only a key whose scope is exactly this user can be revoked here. A key for all users (`*`), a key
for several users and a key of another user are answered with `App Key Not Found`, whether they
exist or not.

Revoking a key that is already revoked succeeds and returns the original revocation date.

This endpoint is only available when both `API_USER_MANAGEMENT_ENABLED` and `APP_KEYS_ENABLED` are
set to `true`.

**URL** : `/api/v1/users/:user_id/app_keys/:app_id/revoke`

**Method** : `POST`

**Auth required** : YES

**Params constraints**

```
:user_id -> "[user id as an int]",
:app_id -> "[app id of the key, as returned when it was created]",
```

**URL example**

```
/api/v1/users/5/app_keys/app_0123456789abcdef01234567/revoke
```

## Success Response

**Code** : `200 OK`

**Content examples**

```json
{
	"status": "success",
	"data": {
		"app_id": "app_0123456789abcdef01234567",
		"revoked_at": "2026-01-23T15:01:33+01:00"
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

**Condition** : If there is no such key, or if its scope is not exactly this user.

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "App Key Not Found",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the key could not be revoked.

**Code** : `500 INTERNAL SERVER ERROR`

**Content** :

```json
{
	"status": "error",
	"message": "Failed to Revoke App Key",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```
