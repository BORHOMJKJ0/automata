# Automata

<p align="right">This project is primarily aimed at Arabic-speaking users — the UI and messages are in Arabic.</p>

## 🔧 Project Overview

**Automata** is a Laravel 10 application that helps teams work with Dropbox shared folders to:

- Browse and navigate Dropbox shared folders (via shared links)
- Download and preview files (PDF, text, Excel)
- Search and match PDFs / text files by metadata (e.g., Producer Name, Wastes Location)
- Extract structured data from PDFs and update Excel spreadsheets automatically
- Notify a Bitrix24 instance about uploads and processing results

It combines Dropbox API usage, PDF parsing (Smalot), Excel handling (PhpSpreadsheet), and Bitrix webhook notifications to automate file processing workflows.

---

## ✅ Key Features

- OAuth-based connection flow to Dropbox
- Browse shared Dropbox links and folders
- Download and preview files (with size limits for previews)
- Recursive file discovery and type categorization (PDF, Excel, text)
- PDF text extraction and data extraction (manifest number, date, waste description, quantity)
- Excel row updates and upload of updated spreadsheet back to Dropbox
- Bitrix notifications for important events (file uploaded, excel processed, search results, errors)

---

## 🧩 Tech Stack

- PHP 8.1+
- Laravel 10
- Guzzle / Http client
- spatie/flysystem-dropbox (optional adapter)
- PhpSpreadsheet (read/write Excel)
- Smalot PDF parser (extract PDF text)

---

## ⚙️ Requirements

- PHP >= 8.1
- Composer
- Node.js + npm (for frontend assets via Vite)
- A Dropbox app (for OAuth credentials)
- A Bitrix24 incoming webhook URL (optional for notifications)

---

## 🚀 Quick Start

1. Clone the repository

   ```bash
   git clone <repo-url> automata
   cd automata
   ```

2. Install PHP dependencies

   ```bash
   composer install --no-interaction --prefer-dist
   ```

3. Copy env and generate key

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Install frontend dependencies and build assets

   ```bash
   npm install
   npm run build      # or `npm run dev` for local development
   ```

5. Configure environment variables (see next section)

6. Serve the app

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```

7. Open http://localhost:8000/dropbox and connect to Dropbox

---

## 🔐 Environment Variables

Add the following to your `.env` (or set via CI):

- APP_URL (e.g., https://your-host)
- DROPBOX_CLIENT_ID — created in the Dropbox App Console
- DROPBOX_CLIENT_SECRET — created in the Dropbox App Console
- DROPBOX_REDIRECT_URI — must match the Dropbox app settings (default: `https://your-host/dropbox/callback`)
- BITRIX_WEBHOOK_URL — (optional) base webhook URL for Bitrix24
- BITRIX_NOTIFY_CHAT_ID or BITRIX_NOTIFY_USER_ID — (optional) notification target

Notes:
- Dropbox OAuth requires the redirect URI to match exactly what you register in the Dropbox developer console.
- The app stores Dropbox access tokens in the session for the current user.

---

## 🧪 Running Tests

Run the test suite with:

```bash
php artisan test
# or
vendor/bin/phpunit
```

---

## 💡 Usage Notes & Tips

- Use the **/dropbox** route to start the OAuth flow and connect a Dropbox account.
- Use **/dropbox/test-connection** to verify API access (requires active session and shared URL).
- The app limits previewable files to ~1MB to avoid large memory usage during previews.
- PDF parsing relies on textual PDFs. OCR'd images inside PDFs will not extract text with Smalot.

---

## 🛠 Troubleshooting

- If you get authentication errors, confirm dropbox client ID/secret and redirect URI.
- If PDF parsing returns no text, check if the PDF is image-only (requires OCR).
- For Bitrix notifications, ensure the webhook URL is reachable from your host.

---

## ♻️ Contributing

Contributions are welcome. Please open issues for bugs or feature requests and submit PRs for changes.

- Follow PSR-12 / Laravel conventions
- Keep tests or add tests for new features

---

## 📄 License

This project is open-sourced under the **MIT License**.

---

## ✉️ Contact

If you need help with setup or want to discuss feature ideas, open an issue or contact the maintainers listed in the repository.
