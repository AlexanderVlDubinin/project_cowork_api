# Coworking Space Booking API 🚀

RESTful API based on **Symfony 7** and **PHP 8.4** for automated booking of workplaces (tables) and meeting rooms in a coworking space.
The project is fully containerized using **Docker & Docker Compose**, uses **PostgreSQL 16** with native support for **UUIDv7**, asynchronous queues **Symfony Messenger** and the **Mailpit** tool for debugging mail notifications. The architecture is 100% covered by tests for **PHPUnit 13**.
The system is designed using modern architectural patterns (DTO, Outbox, Service Registry), is protected from overbooking at the database and business logic levels, supports asynchronous queue processing via Symfony Messenger, and isolates financial transactions to ensure idempotence.

---

## 🛠 Technology Stack & Infrastructure

* **Backend:** PHP 8.4 / Symfony 7.4
* **Database:** PostgreSQL 16 (UUIDv7 primary keys, indexes, JSONB gateway logging)
* **Asynchronous Layer:** Symfony Messenger (Transport: `doctrine://default?auto_setup=0`)
* **Email Debugging:** Mailpit (built-in SMTP server with Web interface)
* **Environment:** Docker & Docker Compose (containers: `symfony_nginx`, `symfony_php`, `symfony_postgres`, `symfony_mailpit`)
* **Authentication:** JWT (JSON Web Tokens) via `lexik/jwt-authentication-bundle`
* **Testing:** PHPUnit 13

---

## 📐 Business rules & System logic

1. **Coworking mode of operation:** The booking service and resources are available strictly on weekdays (Mon-Fri) from 08:00 to 20:00. Requests outside this interval are rejected by the validator.
2. **Overbooking protection (Race Conditions):** Time interval intersection control is encapsulated in the `BookingManager`. At the DBMS level, a composite index is deployed for the fields `(resource_id, started_at, ended_at)` to block parallel overlaps in time.
3. **Idempotence of payments:** The `payment_transactions` table acts as a buffer. Repeated webhooks from the bank are processed without changing the entities, returning the status `already_processed'.
4. **Life Cycle Automation:** Monitoring of payment timeouts (15 min), No-Show customers (10 min) and lease completion (Completion) is implemented asynchronously via `Symfony Messenger (DelayStamp)`.

---

## 📈 Implemented functionality by stages

### 🔐 Stage 1: Authorization and Roles
- Implemented a hierarchy of roles: `ROLE_USER` < `ROLE_ADMIN` < `ROLE_SUPER_ADMIN`.
- JWT authentication is configured. The API protected zones and the public endpoint of webhooks are separated.
- A closed endpoint of user browsing for the administrator with pagination has been developed.

### 📦 Stage 2: Resource Catalog
- The `Resource` entity has been created with strict typing via Enum `ResourceType` (`desk`, `meeting_room`).
- A DTO layer has been implemented for creating, editing, and complex filtering of resources.
- The admin sees all the objects; the client sees only the active ones. Page-by-page pagination is integrated.

### ⏳ Stage 3: Booking and Validation
- The `bookings` table has been created with a strict lifecycle Enum `BookingStatus` (`pending`, `expired`, `failed`, `confirmed`, `cancelled`, `checked_in`, `completed`, `no_show`).
- A custom Symfony validator has been written to prevent dates and bookings from overlapping in the past.
- DTOs have been developed for incoming booking parameters and end-to-end list filters.

### 🧪 Stage 4: Testing the Kernel (PHPUnit 13)
- Unit tests for checking intersection mathematics in the `BookingManager`.
- Unit tests for resource availability during coworking business hours (weekdays from 08:00 to 20:00).
- Integration test for race status and return of the `409 Conflict` status.
- Full integration coverage of CRUD resources for the admin and lists for the client.
- Integration tests for Booking (lists, creation, intersections, invalid parameters).

### 📨 Stage 5: Asynchronous Architecture (Messenger & Mailpit)
- Isolated background sending of notification emails via the message bus.
- Built-in **Mailpit** for local interception and viewing of all sent emails in a beautiful web interface.
- Automatic cancellation of `pending` bookings that are not paid on time (transfer to the `expired` status).
- Asynchronous alerts for manual cancellation of bookings (`cancelled`).

### 💳 Stage 6: Two-Step Payment, Webhooks and Check-In
- The endpoint of the initiation of the `/api/bookings/{id}/pay` payment session with transaction fixation and token generation.
- Endpoint of the webhook `/api/webhooks/payment` with validation of fraudulent requests (comparison of the transmitted `amount` and `type`).
- Automatic notification of successful booking (status `confirmed`). Converting the status to `failed` for invalid events/amounts.
- The `check_in` system (QR code scanning), taking into account a 5-minute technical break (`bookingTechBreak`).
- A worker for automatic cancellation of a reservation in case of no-show within 10 minutes after the start (`no_show`).
- Automatic notification of booking completion (status `completed`).
- Testing via **time manipulation** (`now - 11 minutes`) to instantly check background processes.

### ➕ Additionally
- **Data fixtures:** Auto-complete the database with the history of completed bookings for the past week and future upcoming bookings for 2 weeks ahead. The following users are created by fixtures: Super User (`ROLE_SUPER_ADMIN`, Email: `superadmin@example.com`, Password: `superpass123!`); Admin (`ROLE_ADMIN`, Email: `admin1@example.com` - `admin4@example.com`, Password: `admin123456`), Regular user (`ROLE_USER`, Email: random - see the endpoint `Users List`, Password: `qwerty123456`).
- **Pagination:** Page-by-page output (`page`, `limit`) is integrated into absolutely all API lists.

---

## 🚀 Quick launch in Docker

1. Clone the repository:
   ```bash
   git clone https://github.com/AlexanderVlDubinin/project_cowork_api.git
   ```
2. Assemble the containers:
   ```bash
   docker-compose up -d --build
   ```
3. Run migrations and fill the database with fixtures:
   ```bash
   docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
   docker-compose exec php bin/console doctrine:fixtures:load --no-interaction
   ```
4. Run the tests (PHPUnit 13):
   ```bash
   docker-compose exec php bin/phpunit
   ```
5. Viewing emails (Mailpit Web UI): Open it in a browser `http://localhost:8025`

---

## 📬 Ready API collection for Postman

The collection file **`Coworking_API.postman_collection.json`** has been added to the root of the repository. It contains preset requests for all scenarios (authorization, admin panel, booking creation, check-in, and webhooks).

### How to use:
1. Download the `Coworking_API.postman_collection.json` from the project root.
2. Open Postman and click the **Import** button (it can be found in the drop-down list marked as `...`) in the upper-left corner.
3. Drag and drop the downloaded file into the import window (or select this file).
4. For endpoints that require authorization, the token is automatically substituted from the global variable after successfully calling `POST /api/login_check` and filling in the `cowork_jwt` variable with the token.

---

## 📋 Interactive documentation of API endpoints

* All endpoints, with the exception of public web sites and authorization, require Authorization `Auth Type` - `Bearer Token` and `Token` - `<JWT_TOKEN>` (or the `{{cowork_jwt}}` variable).

### 🔐 Authentication and Users
* `POST /api/register` (**User registration**  (public)) — User registration.
  * **Body example (JSON DTO):** `{"fullName": "Test User 1", "email": "user1@example.com", "password": "qwerty123456"}`
* `POST /api/login_check` (**User login** (public)) — Getting a JWT token.
  * **Body example (JSON):** `{"username": " user1@example.com ", "password": "qwerty123456"}`
* `GET /api/admin/users` (**Users List** (admin)) — List of users *(Admin only)*.
  * **Query parameters (DTO Filter):** `page` (default 1), `limit` (default 10).

### 🛠 Resource Management *(Admin Only)*
* `GET /api/admin/resources` (**Resources List** (admin)) — An end-to-end list of all resources with pagination and history.
  * **Query-параметры (DTO Фильтр):** `userId` (UUID), `type` (`desk`|`meeting_room`), `isActive` (bool), `startDate` (ATOM ISO 8601), `endDate` (ATOM ISO 8601), `status` (string), `page` (int), `limit` (int).
* `POST /api/admin/resource` (**Resource Create** (admin)) — Creating a new resource.
  * **Body example (JSON DTO):** `{"title": "Desk № 12", "type": "desk", "description": "With monitor 27", "isActive": true, "pricePerHour": 500}`*( pricePerHour in cents)*.
* `GET /api/admin/resource/{id}` (**Resource Show** (admin)) — Viewing a specific resource by its UUID.
* `PUT /api/admin/resource/{id}` (**Resource Update** (admin)) — Full resource update.
  * **Body example (JSON DTO):** The structure is similar to the creation POST request.
* `DELETE /api/admin/resource/{id}` (**Resource Delete** (admin)) — Removing a resource from the system.

### 👤 Client Catalog and Booking *(Authorized User)*
* `GET /api/resources` (**Resources List** (client)) — List of available active resources for clients (`isActive=true`).
  * **Query Parameters (DTO Filter):** `type` (`desk`|`meeting_room`), `page` (int), `limit` (int).
* `GET /api/bookings` (**Bookings List** (admin/client)) — The endpoint of the booking list. Polymorphic depending on the role:
  * **For the Client (`ROLE_USER`):** Automatically returns only his own bookings.
* **For the Admin (`ROLE_ADMIN`):** Opens access to the entire database with filtering.
  * **Query Parameters (DTO Filter):** (available only to admin) `userId` (UUID), `resourceId` (UUID), `startDate` (ATOM ISO 8601), `endDate` (ATOM ISO 8601), `status` (Enum value), `page` (int), `limit` (int).
* `POST /api/booking` (**Booking Create** (client)) — Making a reservation (reserves a slot with the `pending` status for 15 minutes).
  * **Body example (JSON DTO):** `{"resourceId": " 019ef838-c0d4-7a77-b817-a5cdb460d662", "startedAt": "2026-07-10T10:00:00Z", "duration": 120}` *( resourceId  - UUID, startedAt - ATOM ISO 8601, duration in minutes)*
* `GET /api/bookings/{id}` (**Booking Cancel** (admin/client)) — The endpoint of the booking cancel by its UUID.
  * **For the Admin/Client (`ROLE_ADMIN`/`ROLE_USER`):** Cancels the booking (changes the status to `cancelled`).
* `POST /api/booking/{id}/pay` (**Booking Payment** (client)) — The intention to pay. Generates a transaction session.
  * **Response (JSON):** `{"status": "pending", "payment_token": "ch_fake_d92b2afb0ee5", "redirect_url": "https://mock-payment-gateway.com/ch_fake_d92b2afb0ee5"}`
* `POST /api/booking/{id}/check_in` (**Booking Check In** (client)) — Confirmation of the client's presence (activation of the reservation). Available as part of the `bookingTechBreak` buffer (5 minutes before the start).

### 💳 Public Gateway Webhooks (`PUBLIC_ACCESS`)
* `POST /api/webhooks/payment` (**Webhook** (public)) — Asynchronous receipt of notifications from the payment system.
**Request body example (JSON для Postman):**
```json
{
  "type": "payment.succeeded",
  "object": {
    "id": " ch_fake_d92b2afb0ee5", // insert the token from the `/api/booking/{id}/pay` endpoint here
    "amount": 1000
  }
}
```

