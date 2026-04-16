# SMART-ACSESS Starter (UI-Enhanced) — Laravel + Nuxt 3 + Tailwind + PostgreSQL

This is an updated starter with a **more polished Nuxt 3 UI template** (minimalist white, UPLB palette).

Palette:
- Green: `#00563F`
- Red: `#8D1436`
- Yellow: `#FFB71C`
Typography:
- Base: **Poppins** (loaded via Google Fonts)
- Headings: **Futura** (falls back to local Futura if installed; otherwise uses Poppins)

Features (Option B):
- Login / Register
- User Management (any logged-in user can CRUD users)
- Add multiple PostgreSQL databases via DSN connection string
- Inspect schema (tables + columns)
- Dashboard metrics + date filters + period grouping
- PDF export
- Chatbot widget (lower right) + safe stub backend endpoint

---

## Requirements
- PHP 8.2+
- Composer
- Node.js 18+ (recommended 20 LTS)
- PostgreSQL 14+

---

## Backend Setup
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# edit .env DB creds
php artisan migrate
php artisan db:seed
php artisan serve
```

Default seeded user:
- email: `admin@uplb.edu.ph`
- password: `Admin@123!`

Backend: http://localhost:8000

---

## Frontend Setup
```bash
cd ../frontend
npm install
npm run dev
```

Frontend: http://localhost:3000

---

## Notes
- Cookie auth uses Sanctum. If you change ports/domains, update:
  - `backend/.env`: SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN
  - `frontend/nuxt.config.ts`: apiBase
