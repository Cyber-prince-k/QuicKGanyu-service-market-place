# QuickGanyu Service Marketplace

## Overview
QuickGanyu is a static service marketplace website built with HTML, CSS, and JavaScript. It includes a home page, authentication pages, provider listing, profile, and admin pages.

## Project Structure
- `index.html` — home page
- `html/admin.html` — admin dashboard page
- `html/login.html` — login page
- `html/signup.html` — signup page
- `html/profile.html` — provider profile page
- `html/providers.html` — service browsing page
- `css/styles.css` — shared stylesheet for layout, forms, footer, and navigation
- `js/script.js` — shared JavaScript support file

## Auth Pages
- `html/login.html` uses a centered split-panel layout with the sign-in action inside the white panel and a black side panel for contextual CTA.
- `html/signup.html` preserves the original signup fields while arranging them in a clean two-column form layout.
- The signup page no longer includes email and phone fields if the system owner requested removal.

## Footer
The site footer has been rebuilt to use a template-inspired multi-column layout:
- site navigation links
- explore links
- contact information
- newsletter subscription block with social icons
- branded bottom row and back-to-top link

## Notes
- All moved HTML pages are located in the `html/` directory, with `index.html` kept in the project root.
- Relative links were updated for moved files and assets.
- Footer markup and CSS were standardized across every page.

## How to run
1. Open `index.html` in a browser to view the homepage.
2. Open pages inside `html/` directly as needed.

## Git Deployment
This repository is prepared for Git deployment and can be pushed to GitHub or any other remote host.
