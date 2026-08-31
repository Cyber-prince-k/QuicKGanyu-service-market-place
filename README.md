# QuickGanyu Service Marketplace

QuickGanyu is a Malawi-focused marketplace connecting customers with local service providers such as plumbers, electricians, photographers, caterers, tailors, tutors, and IT technicians. Customers can register and browse available providers. Providers apply, are approved by an administrator, and build evidence-backed profiles before they can be shown to customers.

## Technology

- Front end: HTML, CSS, vanilla JavaScript
- Back end: PHP
- Database: MySQL/MariaDB
- Local development: XAMPP (Apache + MySQL)

## Project structure

```
index.html                  Home page and provider application form
html/                       Login, signup, dashboards, setup, and browse pages
api/                        PHP API endpoints and business rules
sql/schema.sql              The single database setup/upgrade file
css/styles.css              Shared visual styling
js/api.js                   API calls, sessions, and navigation helpers
uploads/provider-portfolio/ Provider portfolio media, created on first upload
uploads/provider-products/  Product/service listing media, created on first upload
```

## Database and identity rules

`users` is the base account table. `admins`, `customers`, and `service_providers` contain role-specific data. `provider_requests` stores applications, while `provider_portfolio` and `provider_testimonials` store a provider's proof of work and client feedback.

- Full names are unique.
- Emails are deliberately not unique; more than one account may share an address.
- Usernames are unique so they can identify an account unambiguously.
- Users sign in with a username or full name and their password, not their email address.

## Roles

| Role | What they can do |
| --- | --- |
| Customer | Register, manage their profile, and browse eligible providers. |
| Provider | Complete a professional profile, add testimonials, upload project proof, and publish service/product details with photos or videos. |
| Administrator | Review provider applications, approve/decline them, and create providers directly. |

## Provider availability rule

Providers are not listed to customers, and therefore cannot be presented as available for work, until they provide all of the following:

1. Phone, location, service category, skills, qualifications, biography, and at least one year of experience.
2. At least one portfolio image or video.
3. At least one client testimonial.

This is enforced server-side by `api/providers.php`, not only by the dashboard interface. Portfolio uploads are limited to JPG, PNG, WEBP, MP4, and WebM files of 25 MB or less.

## Installation

### Requirements

- PHP 8+ and MySQL/MariaDB
- Apache or equivalent web server
- XAMPP is recommended for local use

### Setup steps

1. Place the project in your web root, for example `C:\xampp\htdocs\roi`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Import **only** `sql/schema.sql`. It creates the `quickganyu` database and safely upgrades existing installations.
5. Check `api/config.php` and adjust database credentials if needed. Defaults are host `127.0.0.1`, database `quickganyu`, user `root`, and an empty password.
6. Open `http://localhost/roi/` in a browser.

Do not open pages directly from the file system: PHP APIs and sessions require a web server.

## User manual

### Customer

1. Select **Sign up** and enter a unique full name, email, phone number, and password.
2. After registration, use the customer dashboard to update profile information.
3. Select **Browse providers** to see providers who have completed the marketplace requirements.

### Provider

1. On the home page, select **Apply to join as a provider** and submit the application.
2. Wait for an administrator's decision. Approved providers receive temporary credentials by email.
3. Sign in with the full name and temporary password, then select a username and permanent password on the setup page.
4. On the Provider Dashboard, complete all professional fields: location, category, experience, skills, qualifications, and biography.
5. Add a client testimonial and upload at least one project photo or video.
6. Use **Services & work showcase** to publish what you offer, its details, and up to five images/videos of completed work. Prices are not displayed.
7. Once the dashboard reports that the profile is complete, the provider becomes visible in Browse Providers.

### Administrator

1. Sign in with an administrator account. Seed credentials are documented in comments in `sql/schema.sql`.
2. Open the **Admin** dashboard.
3. Review pending provider applications and select **Approve** or **Reject**.
4. Use **Add Provider** when an administrator needs to create a provider account directly.

## API overview

| Endpoint | Purpose |
| --- | --- |
| `api/register.php` | Customer registration |
| `api/login.php` / `api/logout.php` | Login and logout |
| `api/session.php` | Current signed-in user |
| `api/offer.php` | Provider application submission |
| `api/admin.php` | Administrator actions |
| `api/setup.php` | Provider first-login setup |
| `api/profile.php` | Profile updates, testimonials, and evidence retrieval |
| `api/portfolio.php` | Provider image/video upload |
| `api/products.php` | Provider product/service listing and media upload |
| `api/providers.php` | Public list of complete providers only |
| `api/engagements.php` | Customer/provider negotiation, bookings, and payment status |

## Troubleshooting

| Problem | What to check |
| --- | --- |
| Database connection failed | MySQL is running and `api/config.php` credentials are correct. |
| Provider is missing from Browse | Complete every profile field, add a portfolio item and testimonial, and ensure the account is active. |
| Upload fails | File type/size is allowed and Apache can write to `uploads/provider-portfolio/`. |
| Login fails | Use username or full name, rather than email, and verify the password. |
| Schema import finds duplicate names | Make existing `users.full_name` values unique, then run `sql/schema.sql` again. |

## Security and maintenance

- Passwords use PHP password hashing.
- Server-side validation protects roles, input values, and upload type/size.
- Use HTTPS, strong database credentials, backups, and a protected SMTP configuration in production.
- Any future quote, booking, or payment endpoint must check `provider_profile_complete` before accepting a provider.
- PayChangu Mobile Money payments use the non-redirect Direct Charge flow. Set the server-only
  `PAYCHANGU_SECRET_KEY` environment variable to your PayChangu secret key before accepting
  real payments; customers still authorize the charge with their Mobile Money provider.
