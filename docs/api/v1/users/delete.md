# Delete User

Deletes a user account and everything that belongs to it. This cannot be undone.

The user, its principals, the calendars it owns (with their events and every instance shared with
other users), its access to calendars shared with it, its calendar subscriptions, scheduling
objects, address books and stored properties are deleted. App keys that were only for this user are
revoked, and the user is taken out of the keys that list several users.

An administrator cannot be deleted over the API. Use the dashboard for that.

This endpoint is only available when `API_USER_MANAGEMENT_ENABLED` is set to `true`.

**URL** : `/api/v1/users/:user_id`

**Method** : `DELETE`

**Auth required** : YES

**Params constraints**

```
:user_id -> "[user id as an int]",
```

**URL example**

```
/api/v1/users/5
```

## Success Response

**Code** : `200 OK`

**Content examples**

```json
{
	"status": "success",
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

**Condition** : If user is not found (this is also the answer when the same user is deleted a second time).

**Code** : `404 NOT FOUND`

**Content** :

```json
{
	"status": "error",
	"message": "User Not Found",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the user is an administrator.

**Code** : `403 FORBIDDEN`

**Content** :

```json
{
	"status": "error",
	"message": "Admin Users Cannot Be Deleted Over the API",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```

**Condition** : If the user could not be deleted.

**Code** : `500 INTERNAL SERVER ERROR`

**Content** :

```json
{
	"status": "error",
	"message": "Failed to Delete User",
	"timestamp": "2026-01-23T15:01:33+01:00"
}
```
