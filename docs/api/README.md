# Davis API

## API Version 1

### Open Endpoints

Open endpoints require no Authentication.

* [Health](v1/health.md) : `GET /api/v1/health`

### Endpoints that require Authentication

Closed endpoints require a valid `X-Davis-API-Token` to be included in the header of the request. Token needs to be configured in .env file (as a environment variable `API_KEY`) and can be generated using `php bin/console api:generate` command.

When `API_KEY` is not set, the API endpoints are disabled and will return a 500 error if accessed.

#### User related

Each endpoint displays information related to the User:

* [Get Users](v1/users/all.md) : `GET /api/v1/users`
* [Get User Details](v1/users/details.md) : `GET /api/v1/users/:user_id`

Creating, changing and deleting users is off by default. Set `API_USER_MANAGEMENT_ENABLED=true` to turn these endpoints on; when they are off they answer `404`.

* [Create User](v1/users/create.md) : `POST /api/v1/users/create`
* [Update User](v1/users/update.md) : `PATCH /api/v1/users/:user_id`
* [Delete User](v1/users/delete.md) : `DELETE /api/v1/users/:user_id`

> [!WARNING]
>
> With these endpoints on, whoever holds the API key can delete any user that is not an administrator, with all of its calendars and address books. There is no way to undo it other than restoring a backup. With app keys turned on as well, the API key can also create an app key for any of those users, and so read and change everything they have. Keep the key on the server side, and if you can, only let the service that needs it reach `/api`.

#### App keys related

Endpoints for the [app keys](../../README.md) of a user: a key lets a client or a service log in over DAV as that user, without the user's password. They need both `API_USER_MANAGEMENT_ENABLED=true` and `APP_KEYS_ENABLED=true`, and answer `404` otherwise.

A key made here is always for exactly one user who is not an administrator. Keys for all users (`*`), for several users or for an administrator can neither be created nor revoked over the API.

* [Create User App Key](v1/app_keys/create.md) : `POST /api/v1/users/:user_id/app_keys/create`
* [Revoke User App Key](v1/app_keys/revoke.md) : `POST /api/v1/users/:user_id/app_keys/:app_id/revoke`

#### Calendars related

Endpoints for viewing and modifying user calendars.

* [Show All User Calendars](v1/calendars/all.md) : `GET /api/v1/calendars/:user_id`
* [Show User Calendar Details](v1/calendars/details.md) : `GET /api/v1/calendars/:user_id/:calendar_id`
* [Create User Calendar](v1/calendars/create.md) : `POST /api/v1/calendars/:user_id/create`
* [Edit User Calendar](v1/calendars/edit.md) : `PUT /api/v1/calendars/:user_id/:calendar_id`
* [Delete User Calendar](v1/calendars/delete.md) : `DELETE /api/v1/calendars/:user_id/:calendar_id`
* [Show User Calendar Shares](v1/calendars/shares.md) : `GET /api/v1/calendars/:user_id/shares/:calendar_id`
* [Share User Calendar](v1/calendars/share_add.md) : `POST /api/v1/calendars/:user_id/share/:calendar_id/add`
* [Remove Share User Calendar](v1/calendars/share_remove.md) : `POST /api/v1/calendars/:user_id/share/:calendar_id/remove`
