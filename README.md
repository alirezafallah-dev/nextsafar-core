<div align="center">

# 🌍 NextSafar Core

> **Enterprise WordPress Plugin for Headless Travel Platform** WordPress Plugin for Headless Travel Platform

[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.5.0-blue.svg)](CHANGELOG.md)

**The backend brain of [NextSafar.com](https://nextsafar.com) — a production-grade travel booking platform**

[Features](#-features) • [Architecture](#-architecture) • [Installation](#-installation) • [API Reference](#-api-reference) • [Contributing](#-contributing)

</div>

---

## 📖 Overview

**NextSafar Core** is a powerful WordPress plugin that serves as the **headless backend** for a modern travel booking platform. It provides a comprehensive REST API for hotels, flights, destinations, tours, restaurants, and visa services, while integrating with multiple external data providers for real-time pricing and availability.

This plugin is the **backbone** of a production system serving thousands of daily users, handling real-time hotel searches, intelligent matching algorithms, multi-provider API orchestration, and sophisticated caching strategies.

### 🎯 What Makes This Project Special

- **Multi-Provider API Orchestration** — Seamlessly integrates SerpApi, SearchApi, and custom providers with automatic fallback
- **Intelligent Hotel Matching** — Proprietary algorithm that matches internal hotel database with external providers using name similarity, geolocation, and confidence scoring
- **Real-Time Price Aggregation** — Fetches live pricing from Google Hotels API with currency conversion
- **Headless-First Architecture** — Pure REST API design optimized for Next.js frontend consumption
- **Enterprise Caching** — Multi-layer caching with transients and smart invalidation
- **AI Integration** — Gemini-powered trip planning and content generation

---

## ✨ Features

### 🏨 Hotel System
- **Unified Search** — Merges site-owned hotels with external providers in a single response
- **Smart Matching Algorithm** — Haversine distance + Jaccard similarity + external ID matching
- **Real-Time Pricing** — Live rates from Google Hotels API with USD to Toman conversion
- **#### Hotel Details** — Rich data including rooms, amenities, reviews, nearby places, and images
- **Featured Hotels** — Curated selection with priority ranking
- **Favorites Sync** — Cross-device favorite synchronization via user meta

### ✈️ Flight Search
- **Google Flights Integration** — Real-time flight search via SerpApi
- **Multi-Cabin Support** — Economy, Premium Economy, Business, First Class
- **IATA Code Mapping** — 200+ airport codes mapped to Persian city names
- **Round-Trip & One-Way** — Flexible trip type support

### 🗺️ Destinations & Places
- **Custom Geo System** — Dedicated geo-table for fast spatial queries
- **Place Enrichment** — Auto-fills address, hours, and contact info from SearchApi
- **Category System** — Rich taxonomy support for destinations, restaurants, hospitals

### 🤖 AI Integration
- **### AI Trip Planner** — Gemini-powered itinerary generation with background processing
- **AI Content Rewriter** — Automatic content enhancement for SEO
- **News Filtering** — AI-powered relevance scoring for travel news

### 🔐 Authentication
- **OTP Login** — Passwordless authentication via Kavenegar SMS gateway
- **Google OAuth** — Social login support
- **Session Management** — Secure token-based sessions

### 📰 News Aggregation
- **Multi-Source** — RSS feeds and NewsAPI integration
- **AI Filtering** — Automatic relevance scoring and categorization
- **Duplicate Detection** — Prevents duplicate content

---

## 🏗️ Architecture
┌────────────────────────────────────────────────────────┐
│                    Next.js Frontend                    │
│              (React + TypeScript + Tailwind)           │
└───────────────────────┬────────────────────────────────┘
                        │ REST API (JSON)
                        │
                        ▼
┌────────────────────────────────────────────────────────┐
│             NextSafar Core (WordPress Plugin)          │
│                                                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │   Hotels     │  │   Flights    │  │ Destinations │  │
│  │   System     │  │   System     │  │   System     │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
│                                                        │
│  ┌─────────────────────────────────────────────────┐   │
│  │          LiveSearch Engine (Core)               │   │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────────────┐ │   │
│  │  │ SerpApi  │ │SearchApi │ │   DataForSEO     │ │   │
│  │  └──────────┘ └──────────┘ └──────────────────┘ │   │
│  └─────────────────────────────────────────────────┘   │
│                                                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │    MySQL     │  │   Transients │  │  User Meta   │  │
│  │   Database   │  │    Cache     │  │   Storage    │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
└────────────────────────────────────────────────────────┘

### Key Components

| Component | File | Purpose |
|-----------|------|---------|
| **LiveSearch** | `inc/admin/live-search.php` | Central search engine with multi-provider support |
| **Hotel Matcher** | `inc/hotels/matcher.php` | Intelligent hotel matching algorithm |
| **Endpoints** | `inc/hotels/endpoints.php` | REST API registration for hotel routes |
| **Geo Schema** | `inc/sync/geo-schema.php` | Spatial data management |
| **API Clients** | `inc/api/serpapi-client.php` | External API integrations |
| **News Sync** | `inc/api/news-sync.php` | Background news aggregation |
| **### AI Trip Planner** | `inc/api/ai-trip-planner-endpoint.php` | Gemini-powered itinerary generation |

---

## 🚀 Installation

### Requirements

- **PHP**: 8.1 or higher
- **WordPress**: 6.0 or higher
- **MySQL**: 5.7+ or MariaDB 10.3+
- **Extensions**: cURL, JSON, mbstring, OpenSSL

### Quick Start

```bash
# 1. Clone the repository into your WordPress plugins directory
cd wp-content/plugins/
<<<<<<< HEAD
git clone https://github.com/alirezafallaah78/nextsafar_core_v2_5.git nextsafar-core
=======
git clone https://github.com/alirezafallah-dev/nextsafar-core.git
>>>>>>> 0df7db7226b7731771c852ac1af53c642ca175dc

# 2. Activate the plugin via WordPress admin or WP-CLI
wp plugin activate nextsafar-core

# 3. Configure API keys in WordPress Admin → NextSafar → Live Search

### Environment Configuration

Create API keys for the following services (at least one required):

| Service | Purpose | Get Key |
|---|---|---|
| **SerpApi (Recommended)** | Google Hotels & Flights | `serpapi.com` |
| **SearchApi (Fallback)** | Backup provider | `searchapi.io` |
| **Kavenegar** | SMS OTP authentication | `kavenegar.com` |
| **Gemini API** | ### AI Trip Planner | `ai.google.dev` |

Configure keys in: **WordPress Admin → NextSafar → Live Search**
## 📡 API Reference
### Hotel Endpoints
#### Search Hotels
GET /wp-json/nextsafar/v1/hotels/search
### Parameters

| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `city` | string | ✅ | City name (Persian or English) |
| `check_in` | string | ✅ | Check-in date (YYYY/MM/DD or YYYY-MM-DD) |
| `check_out` | string | ✅ | Check-out date |
| `adults` | integer | ❌ | Number of adults (default: 2) |
| `children` | integer | ❌ | Number of children (default: 0) |
| `q_en` | string | ❌ | English city name override |
Response:

```json
{
  "ok": true,
  "items": [
    {
      "source": "site|online",
      "match_confidence": 0.95,
      "site": { "id": 123, "title": "Hotel Name", "stars": 4 },
      "online": { "property_id": "abc", "name": "Hotel Name", "token": "..." },
      "price": { "per_night_toman": 2500000, "usd": 23.50 },
      "badges": ["featured", "site"]
    }
  ],
  "provider": "serpapi",
  "meta": {
    "nights": 3,
    "site_count": 45,
    "online_count": 120,
    "matched": 38,
    "stale": false
  }
}
```

### #### Hotel Details
GET /wp-json/nextsafar/v1/hotels/details
### Parameters

| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `token` | string | ✅ | Property token from search results |
| `check_in` | string | ❌ | Check-in date |
| `check_out` | string | ❌ | Check-out date |
| `adults` | integer | ❌ | Number of adults |
| `name` | string | ❌ | Hotel name for query |
#### Clear Cache
POST /wp-json/nextsafar/v1/hotels/clear-cache
### Flight Endpoints
#### Search Flights
GET /wp-json/nextsafar/v1/flights/search
### Parameters

| Parameter | Type | Required | Description |
|---|---|:---:|---|
| `origin` | string | ✅ | IATA code of departure airport |
| `dest` | string | ✅ | IATA code of arrival airport |
| `date` | string | ✅ | Departure date (YYYY-MM-DD) |
| `return_date` | string | ❌ | Return date (for round-trip) |
| `trip_type` | string | ❌ | `one_way` or `round_trip` |
| `cabin` | string | ❌ | `economy`, `business`, `first` |
| `adults` | integer | ❌ | Number of adults |
| `children` | integer | ❌ | Number of children |
### Account Endpoints
#### Hotel Favorites
GET  /wp-json/nextsafar/v1/account/hotel-favorites
POST /wp-json/nextsafar/v1/account/hotel-favorites
#### User Profile
GET  /wp-json/nextsafar/v1/account/overview
POST /wp-json/nextsafar/v1/account/update
### Authentication Endpoints
POST /wp-json/nextsafar/v1/auth/send-otp
POST /wp-json/nextsafar/v1/auth/verify-otp
GET  /wp-json/nextsafar/v1/auth/me
POST /wp-json/nextsafar/v1/auth/google/start
GET  /wp-json/nextsafar/v1/auth/google/callback
### AI Trip Planner
POST /wp-json/nextsafar/v1/ai-trip/generate
GET  /wp-json/nextsafar/v1/ai-trip/plan/{id}
POST /wp-json/nextsafar/v1/ai-trip/process-now
## 🧠 Hotel Matching Algorithm
The matcher.php implements a sophisticated multi-stage matching algorithm:
Matching Stages
Stage

Confidence

Method

Description
T1

1.0

External ID

Direct match on google_property_token
T2

0.90

Strong Name

Jaccard similarity ≥ 0.80
T3

0.80

Geo + Name

Distance ≤ 100m AND similarity ≥ 0.55
T4

0.70

Close Geo + Name

Distance ≤ 50m AND similarity ≥ 0.40
T5

0.65

Good Name

Name similarity ≥ 0.70
Name Normalization
// Stopwords removed: hotel, grand, resort, suites, هتل, بین‌المللی, etc.
// Lowercase + Unicode normalization
// Jaccard coefficient calculation
Greedy Assignment
After scoring all candidate pairs, the algorithm uses greedy assignment (highest confidence first) to ensure 1:1 mapping between site hotels and provider hotels.
## 📁 Project Structure

nextsafar-core/
├── nextsafar-core.php           # Plugin entry point
├── inc/
│   ├── admin/
│   │   ├── live-search.php      # Search engine (1300+ lines)
│   │   ├── settings.php         # Plugin settings
│   │   ├── sync-page.php        # Sync management UI
│   │   └── exchange.php         # Currency rate management
│   ├── api/
│   │   ├── serpapi-client.php   # SerpApi integration
│   │   ├── searchapi-client.php # SearchApi integration
│   │   ├── gemini-client.php    # Gemini AI client
│   │   ├── hotel-sync.php       # Hotel sync service
│   │   ├── news-sync.php        # News aggregation
│   │   └── ai-trip-planner-endpoint.php # AI trip planning
│   ├── hotels/
│   │   ├── endpoints.php        # Hotel REST endpoints
│   │   └── matcher.php          # Hotel matching algorithm
│   ├── flights/
│   │   └── endpoints.php        # Flight endpoints
│   ├── auth/
│   │   ├── otp.php              # OTP authentication
│   │   ├── google.php           # Google OAuth
│   │   └── session.php          # Session management
│   ├── sync/
│   │   ├── geo-schema.php       # Geo data schema
│   │   ├── geo-sync.php         # Geo data sync
│   │   └── place-enrich-trait.php # Place enrichment
│   ├── account/
│   │   ├── endpoints.php        # Account endpoints
│   │   └── favorites-endpoint.php # Hotel favorites
│   ├── posttypes/               # Custom post types
│   ├── metaboxes/               # Custom fields
│   └── taxonomies/              # Custom taxonomies
├── assets/                      # Admin CSS/JS
└── views/                       # Admin view templates
## 🔧 Configuration
Provider Priority
Configure the provider priority in WordPress Admin → NextSafar → Live Search:

    SerpApi (Primary) — Best quality, Google Hotels & Flights
    SearchApi (Fallback) — Automatic fallback on SerpApi errors

Currency Conversion
The system supports automatic USD → Toman conversion:

    Live Rate: Fetched from exchange rate service
    Manual Override: Set fixed rate in settings
    Cache: Rate cached for optimal performance

## 🧪 Testing
Test API Connection
# Test SerpApi connection
curl -X POST "http://your-site/wp-json/nextsafar/v1/hotels/test-search?city=Istanbul"

Test Hotel Search
curl "http://your-site/wp-json/nextsafar/v1/hotels/search?city=Istanbul&check_in=2026-12-01&check_out=2026-12-03&adults=2"

#### Clear Cache
curl -X POST "http://your-site/wp-json/nextsafar/v1/hotels/clear-cache"

## 🤝 Contributing
Contributions are welcome! Please follow these guidelines:

    Fork the repository
    Create a feature branch: git checkout -b feature/amazing-feature
    Commit your changes: git commit -m 'Add amazing feature'
    Push to the branch: git push origin feature/amazing-feature
    Open a Pull Request

Code Style

    PHP: PSR-12 coding standards
    Comments: English only
    Type Hints: Use PHP 8.1+ union types and return types
    Naming: camelCase for methods, snake_case for variables

## 📄 License
This project is licensed under the MIT License — see the LICENSE
 file for details.
## 👨‍💻 Author
Alireza Fallah

    GitHub: @alirezafallaah78
    Project: NextSafar.com

## 🙏 Acknowledgments

    SerpApi — Google Hotels & Flights data
    SearchApi — Fallback search provider
    WordPress — The best CMS platform
    Next.js — Modern React framework
    Gemini AI — Trip planning intelligence

<div align="center">

If this project helped you, please ⭐ star the repository!
Made with ❤️ for the travel industry
</div>
