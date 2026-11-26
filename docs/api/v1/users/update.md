# Update User

Changes the display name and the email address of a user. Only the fields that are sent change:
send one of them to leave the other as it is.

Nothing else can be changed here. The username is fixed, because the principal and the URL of every
calendar and address book are built from it; a username, a password or an admin flag sent in the
body is ignored. An administrator cannot be changed over the API. Use the dashboard for those.

The email address is the one sabre matches against the organizer and the attendees of an event.
Events that are already stored are not rewritten: where they name the old address, they keep it.

An email address can only be given to one user over the API: an address that another user has is
refused. That is a rule of the API alone. The dashboard does not check it, users who already share
an address stay as they are, and sending a user the address it already has is never an error.

This endpoint is only available when `API_USER_MANAGEMENT_ENABLED` is set to `true`.

**URL** : `/api/v1/users/:user_id`

**Method** : `PATCH`

**Auth required** : YES

**Params constraints**

```
:user_id -> "[user id as an int]",
```

**Request Body constraints**

At least one of the two fields is required.

```json
{
	"displayname": "[string: display name, max 255 chars, optional]",
	"email": "[string: a valid email address, max 255 chars, optional]"
}
```

**URL example**

```
/api/v1/users/5
```

**Body example**

```json
{
	"displayname": "Jane Doe",
	"email": "jane.doe@example.org"
}
```

## Success Response

**Code** : `200 OK`

**Content examples**

The user as it is after the change.

```json
{
	"status": "success",
	"data": {
		"user_id": 5,
		"principal_id": 15,
		"uri": "principals/jdoe",
		"username": "jdoe",
		"displayname": "Jane Doe",
		"email": "jane.doe@example.org"
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
	"message": "Admin Users Cannot Be Changed Over the API",
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

**Condition** : If the body has neither 'displayname' nor 'email'.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Nothing to Update",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'displayname' is empty or too long.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Display Name",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If 'email' is empty, too long or not a valid email address.

**Code** : `400 BAD REQUEST`

**Content** :

```json
{
	"status": "error",
	"message": "Invalid Email",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If another user already has this email address (the comparison ignores case). Nothing is changed then, the display name included.

**Code** : `409 CONFLICT`

**Content** :

```json
{
	"status": "error",
	"message": "Email Already In Use",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the change could not be saved.

**Code** : `500 INTERNAL SERVER ERROR`

**Content** :

```json
{
	"status": "error",
	"message": "Failed to Update User",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```
