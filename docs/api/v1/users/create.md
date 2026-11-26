# Create User

Creates a new user account, with its principal, a default calendar and a default address book.

The account has no usable password and is never an administrator: a password or an admin flag
sent in the body is ignored. Use the dashboard for those.

This endpoint is only available when `API_USER_MANAGEMENT_ENABLED` is set to `true`.

**URL** : `/api/v1/users/create`

**Method** : `POST`

**Auth required** : YES

**Request Body constraints**

```json
{
	"username": "[string: letters, digits and the characters _ . @ + ' -, max 255 chars]",
	"displayname": "[string: display name, max 255 chars]",
	"email": "[string: a valid email address, max 255 chars]"
}
```

**URL example**

```
/api/v1/users/create
```

**Body example**

```json
{
	"username": "jdoe",
	"displayname": "John Doe",
	"email": "jdoe@example.org"
}
```

## Success Response

**Code** : `200 OK`

**Content examples**

`default_calendar.id` is the id the calendar endpoints expect as `:calendar_id`.

```json
{
	"status": "success",
	"data": {
		"user_id": 5,
		"principal_id": 15,
		"uri": "principals/jdoe",
		"username": "jdoe",
		"default_calendar": {
			"id": 25,
			"uri": "default"
		}
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

**Condition** : If 'username' is missing, too long or contains a character that is not allowed.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Username",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'displayname' is missing, empty or too long.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Display Name",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'email' is missing, too long or not a valid email address.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Email",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If a user or a principal with this username already exists (the comparison ignores case).

**Code** : `409 CONFLICT`

**Content** :

```json
{
	"status": "error",
	"message": "User Already Exists",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the user could not be saved.

**Code** : `500 INTERNAL SERVER ERROR`

**Content** :

```json
{
	"status": "error",
	"message": "Failed to Create User",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```
